<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use Closure;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\CoversFunction;
use LucianoPereira\Crucible\Attributes\ExpectedOutcome;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\MutatesClass;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Bridge\PestSnapshots\AutoMixedTrait;
use LucianoPereira\Crucible\Bridge\PestSnapshots\SnapshotFileContext;
use LucianoPereira\Crucible\Bridge\PestSnapshots\SnapshotIdentityWrapper;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\HookPlanner;
use LucianoPereira\Crucible\Framework\IncompleteTestError;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

use function array_map;
use function basename;
use function class_exists;
use function count;
use function dirname;
use function function_exists;
use function in_array;
use function interface_exists;
use function is_array;
use function is_int;
use function is_iterable;
use function is_string;
use function method_exists;
use function rtrim;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use function trait_exists;

use const DIRECTORY_SEPARATOR;

/**
 * The pest dialect frontend (growth G2): turns a *.pest.php file into
 * the same dialect-neutral TestGroup every other frontend produces
 * (D-008) — the engine cannot tell a test() closure from a TestCase
 * method. Lifecycle is composed *inside* the definition closure:
 * beforeEach/afterEach run bound to a fresh instance of the uses()
 * class around each test body, so the engine needs no pest-specific
 * hooks.
 */
final readonly class PestBuilder
{
    /**
     * @param non-empty-string $file     absolute path
     * @param non-empty-string $relative project-relative path, used for TestIds
     */
    public function build(string $file, string $relative): TestGroup
    {
        self::ensureUsable();
        RealPhpUnitBootstrap::ensureConfigured();

        // One separator from here on: on Windows the discovered path
        // can arrive mixed (getcwd() gives '\', config joins with '/'),
        // and every scope and dataset lookup below compares prefixes.
        $file = WorkingDirectory::native($file);

        // Suite-level configuration first: Pest.php and Datasets/*.php
        // from every directory above the file, outermost first.
        $root = rtrim(substr($file, 0, strlen($file) - strlen($relative)), '/' . DIRECTORY_SEPARATOR);

        if ($root !== '') {
            PestScopes::loadConfiguration($root, dirname($file));
        }

        PestRegistry::begin($file, $root);

        require $file;

        $state = $this->effectiveState(PestRegistry::drain(), $file);
        $class = $state->uses ?? PestTestCase::class;

        if (!class_exists($class)) {
            throw new ConfigurationException(sprintf('uses(%s) in %s: the class does not exist.', $class, $relative));
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            throw new ConfigurationException(sprintf('uses(%s) in %s: the class is abstract.', $class, $relative));
        }

        $class = TraitComposer::compose($class, [...$state->traits, ...AutoMixedTrait::forClass($reflection)]);
        $class = SnapshotIdentityWrapper::wrap($class);

        // Real Pest's own equivalent (spatie/pest-plugin-snapshots'
        // override of SnapshotDirectoryAware) hardcodes this same
        // 'tests' literal relative to the project root, not the
        // individual test file's own directory — confirmed against a
        // real pestphp/pest + spatie/pest-plugin-snapshots run
        // (every snapshot landed in one project-root-level
        // tests/__snapshots__/, regardless of how deeply nested the
        // test file itself was). $testFileBasename mirrors real
        // Pest's own TestCaseFactory::evaluate(), which names its
        // generated class after the test file's own basename up to
        // the first dot (so both SomeTest.php and SomeTest.pest.php
        // become "SomeTest") — SnapshotIdAware's real, unoverridden
        // id format bakes the running class's short name in, and
        // real Pest's generated class is literally named after the
        // file for exactly this reason.
        $snapshotsDirectory = $root . '/tests/__snapshots__';
        $testFileBasename   = basename($relative);
        $dotPosition        = strpos($testFileBasename, '.');

        if ($dotPosition !== false) {
            $testFileBasename = substr($testFileBasename, 0, $dotPosition);
        }

        $definitions = [];

        foreach ($state->calls as $call) {
            // wip and done run their bodies like Pest; a todo, and any
            // body-less call (nothing to run), stay pending. So a bare
            // body-less call auto-todos, and a body-less wip/done is
            // folded to a plain todo — its fields kept, its runnable
            // status dropped. The marker travels as engine-visible
            // metadata (D-045): --todos still selects on it and the
            // runner blocks a todo before anything runs, every dialect
            // alike.
            $bodyless = !$call->test instanceof Closure && $call->chain === [];
            $todo     = match (true) {
                $bodyless && $call->todo instanceof Todo => new Todo(
                    TodoStatus::Todo,
                    $call->todo->assignee,
                    $call->todo->issue,
                    $call->todo->note,
                ),
                $bodyless => new Todo(),
                default   => $call->todo,
            };

            $metadata = MetadataCollection::from(
                ...array_map(
                    static fn(string $group): Group => new Group($group),
                    [...$state->groups, ...$call->groups],
                ),
                ...$this->coversOf($state),
                ...($todo instanceof Todo ? [$todo] : []),
                ...($call->expectedOutcome instanceof ExpectedOutcome ? [$call->expectedOutcome] : []),
            );

            foreach ($this->rows($call, dirname($file)) as $datasetKey => $arguments) {
                // PHP silently converts numeric-string array keys back
                // to ints; the TestId wants the string form.
                $datasetKey = (string) $datasetKey;

                $id = new TestId($relative, $call->name(), $datasetKey === '' ? null : $datasetKey);

                $definitions[] = new TestDefinition(
                    $id,
                    $this->definition($call, $arguments, $state, $class, $snapshotsDirectory, $testFileBasename, $id->dataset),
                    $metadata,
                    $call->dependsOn,
                );
            }
        }

        return new TestGroup(
            $relative,
            $definitions,
            $state->beforeAll === [] ? null : static function () use ($state): void {
                foreach ($state->beforeAll as $hook) {
                    $hook();
                }
            },
            $state->afterAll === [] ? null : static function () use ($state): void {
                foreach ($state->afterAll as $hook) {
                    $hook();
                }
            },
        );
    }

    /**
     * Folds the matching Pest.php scopes into the file's own state:
     * the file's uses() beats scoped extend(), traits and groups
     * accumulate, global before-hooks run before file-level ones and
     * global after-hooks after (spec §3). Bare registrations
     * contribute hooks only.
     *
     * @param non-empty-string $file
     */
    private function effectiveState(PestFileState $state, string $file): PestFileState
    {
        $scopes = PestScopes::matching($file);

        if ($scopes === []) {
            return $state;
        }

        $class      = null;
        $traits     = [];
        $groups     = [];
        $beforeEach = [];
        $afterEach  = [];
        $beforeAll  = [];
        $afterAll   = [];

        foreach ($scopes as $scope) {
            if ($scope->globs !== null) {
                $class  = $scope->class ?? $class;
                $traits = [...$traits, ...$scope->traits];
                $groups = [...$groups, ...$scope->groups];
            }

            $beforeEach = [...$beforeEach, ...$scope->beforeEach];
            $afterEach  = [...$afterEach, ...$scope->afterEach];
            $beforeAll  = [...$beforeAll, ...$scope->beforeAll];
            $afterAll   = [...$afterAll, ...$scope->afterAll];
        }

        return new PestFileState(
            $state->calls,
            [...$beforeEach, ...$state->beforeEach],
            [...$state->afterEach, ...$afterEach],
            [...$beforeAll, ...$state->beforeAll],
            [...$state->afterAll, ...$afterAll],
            $state->uses ?? $class,
            [...$traits, ...$state->traits],
            [...$groups, ...$state->groups],
            $state->covers,
            $state->mutates,
        );
    }

    /**
     * covers() as the attributes the coverage reader already consumes.
     * A target that names a live function becomes CoversFunction, and
     * everything else CoversClass — the same split #[CoversClass] and
     * #[CoversFunction] express, spelled file-scoped.
     *
     * @return list<CoversClass|CoversFunction|MutatesClass>
     */
    private function coversOf(PestFileState $state): array
    {
        $attributes = [];

        foreach ($state->mutates as $target) {
            if (!class_exists($target)) {
                throw new ConfigurationException(sprintf(
                    'mutates(%s): no such class. mutates() names the source to mutate, so a target '
                    . 'that does not exist would silently narrow the run to nothing.',
                    $target,
                ));
            }

            $attributes[] = new MutatesClass($target);
        }

        foreach ($state->covers as $target) {
            if (function_exists($target) && !class_exists($target)) {
                $attributes[] = new CoversFunction($target);

                continue;
            }

            if (class_exists($target) || interface_exists($target) || trait_exists($target)) {
                $attributes[] = new CoversClass($target);

                continue;
            }

            throw new ConfigurationException(sprintf(
                'covers(%s): no such class or function. covers() names the code under test, so a '
                . 'target that does not exist would silently cover nothing.',
                $target,
            ));
        }

        return $attributes;
    }

    /**
     * The D-019 coexistence rule, pest edition: with the real Pest
     * installed the dialect refuses — two owners of test()/expect()
     * in one process can only end badly. Public static because the
     * inline dialect's doctests run on the same expect() surface.
     */
    /**
     * Whether this process defined the pest vocabulary itself.
     *
     * The invariant D-019 protects is "never mask a real package", and
     * the thing that would do the masking is REDEFINING a name the real
     * package owns — not the package being present on disk. Keying the
     * refusal on installation was a proxy for that, and it was wrong in
     * both directions: it refused where the names were free, and it
     * would have said nothing if some other package had taken them.
     */
    private static function vocabularyIsOurs(bool ...$set): bool
    {
        /** @var bool $ours */
        static $ours = false;

        if ($set !== []) {
            $ours = $set[0];
        }

        return $ours;
    }

    /**
     * Define the pest vocabulary if the names are free; never throw.
     *
     * The real package loads its globals through Composer's `files`
     * autoload, so in a Pest project they exist from the moment
     * `vendor/autoload.php` runs. Crucible defines them lazily instead,
     * which is why discovery has to be able to ask for them without
     * risking an exception.
     */
    public static function loadVocabulary(): void
    {
        if (function_exists('test')) {
            return;
        }

        require __DIR__ . '/functions.php';
        self::vocabularyIsOurs(true);
    }

    public static function ensureUsable(): void
    {
        // `test()` already declared and not by us: the real package (or
        // something else) owns the name, and PHP has no function_alias()
        // to stand beside it. Redeclaring is an uncatchable fatal, so
        // this is a refusal, not a preference.
        if (function_exists('test') && !self::vocabularyIsOurs()) {
            throw new ConfigurationException(
                'the pest dialect stays off: test() is already declared, so its global functions '
                . 'would mask whatever owns that name (pestphp/pest, normally). PHP has no '
                . 'function_alias(), so they cannot coexist in one process. Either remove '
                . 'pestphp/pest, or run Crucible with the vocabulary prelude, which asks Composer '
                . "not to load Pest's two function files while leaving its classes intact:"
                . PHP_EOL . PHP_EOL
                . '    php -d auto_prepend_file=' . __DIR__ . '/vocabulary-prelude.php vendor/bin/crucible'
                . PHP_EOL,
            );
        }

        self::loadVocabulary();

        // Pest\Laravel\* and Pest\Livewire\livewire() (growth G2
        // follow-up): the real plugin packages that export these
        // hard-require pestphp/pest itself, which the guard above
        // already refuses to coexist with — so they can never
        // actually be installed alongside this dialect, and these
        // proxy files are safe to load unconditionally rather than
        // gated on some other package's presence.
        if (!function_exists('Pest\Laravel\mock')) {
            require dirname(__DIR__, 2) . '/Bridge/PestLaravel/functions.php';
        }

        if (!function_exists('Pest\Livewire\livewire')) {
            require dirname(__DIR__, 2) . '/Bridge/PestLivewire/functions.php';
        }

        if (!function_exists('Spatie\Snapshots\assertMatchesSnapshot')) {
            require dirname(__DIR__, 2) . '/Bridge/PestSnapshots/functions.php';
        }
    }

    /**
     * Dataset rows keyed for TestIds: '' means no dataset. A list row
     * spreads as positional arguments, an associative row binds to
     * closure parameters by name (spec §4), anything else is one
     * argument. Multiple ->with() calls multiply (the spec's
     * Cartesian product; keys join with ' / '), and ->repeat(n) is
     * one more factor.
     *
     * PHP degrades numeric-string keys to ints at runtime, so the
     * declared key type is array-key even though only strings are
     * ever assigned.
     *
     * @param non-empty-string $fileDirectory
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    private function rows(TestCall $call, string $fileDirectory): array
    {
        $rows = ['' => []];

        foreach ($call->datasets as $dataset) {
            $factor = $this->materializeFactor($dataset, $fileDirectory);
            $next   = [];

            foreach ($rows as $key => $arguments) {
                foreach ($factor as $rowKey => $row) {
                    $rowArguments = is_array($row) ? $row : [$row];

                    $next[$this->joinKeys($key, (string) $rowKey)] = [...$arguments, ...$rowArguments];
                }
            }

            $rows = $next;
        }

        if ($call->repetitions > 1) {
            $next = [];

            foreach ($rows as $key => $arguments) {
                for ($repetition = 1; $repetition <= $call->repetitions; ++$repetition) {
                    $label = sprintf('repetition %d of %d', $repetition, $call->repetitions);

                    $next[$this->joinKeys($key, $label)] = $arguments;
                }
            }

            $rows = $next;
        }

        return $rows;
    }

    /**
     * PHP degrades numeric-string array keys to ints, so the left
     * side (an accumulated key read back from an array) arrives as
     * either type.
     */
    private function joinKeys(int|string $left, string $right): string
    {
        return $left === '' ? $right : $left . ' / ' . $right;
    }

    /**
     * One ->with() factor as rows: inline arrays pass through, a
     * string resolves a shared dataset() (nearest folder wins), a
     * closure is a lazy factory invoked now.
     *
     * @param array<array-key, mixed>|non-empty-string|Closure $dataset
     * @param non-empty-string                                 $fileDirectory
     *
     * @return array<array-key, mixed>
     */
    private function materializeFactor(array|string|Closure $dataset, string $fileDirectory): array
    {
        if (is_string($dataset)) {
            return PestScopes::resolveDataset($dataset, $fileDirectory);
        }

        if ($dataset instanceof Closure) {
            $rows = $dataset();

            if (!is_iterable($rows)) {
                throw new ConfigurationException('with(closure): the closure must return an iterable of rows.');
            }

            return PestScopes::materialize($rows);
        }

        return $dataset;
    }

    /**
     * @param array<array-key, mixed> $arguments dataset arguments (string keys bind by name), before dependency injections
     * @param class-string            $class
     * @param non-empty-string        $snapshotsDirectory
     * @param ?non-empty-string       $dataset
     *
     * @return Closure(list<mixed>): mixed
     */
    private function definition(TestCall $call, array $arguments, PestFileState $state, string $class, string $snapshotsDirectory, string $testFileBasename, ?string $dataset = null): Closure
    {
        // The real PHPUnit\Framework\TestCase::__construct() requires
        // its $name argument (Orchestra Testbench's TestCase inherits
        // it unchanged, being final); Crucible's own TestCase declares
        // no constructor at all, so passing an argument it doesn't
        // expect would itself be the error. Checked once per call, at
        // build time, not per invocation.
        $classReflection = new ReflectionClass($class);
        $needsName       = ($classReflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0;

        // #[Before]/#[PreCondition]/#[PostCondition]/#[After] — real
        // PHPUnit's own attributes too (HookPlanner, via
        // MetadataParser/RealPhpUnitHookAttributes), not just
        // Crucible's. A separate mechanism from beforeEach/afterEach
        // above: those are pest's own dialect-level hooks; these are
        // attributed methods on the uses() class itself, or on a
        // trait mixed into it (Spatie\Snapshots\MatchesSnapshots's
        // real #[PostCondition] method, the case this was built for
        // — verified against a real phpunit/phpunit run first, then
        // confirmed Crucible produced a different result before this
        // existed). Computed once per TestCall, reused across every
        // dataset row, matching $needsName above.
        $hooks = HookPlanner::forClass($classReflection);

        return static function (array $dependencyValues) use ($call, $arguments, $state, $class, $needsName, $hooks, $snapshotsDirectory, $testFileBasename, $dataset): mixed {
            /** @var list<mixed> $dependencyValues */
            if ($call->skipped === true || is_string($call->skipped)) {
                throw new SkippedTestError(is_string($call->skipped) ? $call->skipped : 'Skipped.');
            }

            $instance = $needsName ? new $class($call->name()) : new $class();

            // Named before setUp(), matching invokeTest()'s contract —
            // the phpunit dialect's seam for the same thing. Without
            // it a pest test has no name to answer with, which is what
            // the snapshot bridge's identity is built from.
            if ($instance instanceof TestCase) {
                $instance->nameTest($call->name(), $dataset);
            }

            $invoke = static function (Closure $closure, array $args = []) use ($instance, $class): mixed {
                $bound = @Closure::bind($closure, $instance, $class);

                return ($bound ?? $closure)(...$args);
            };

            // A uses() class may be a real setUp()/tearDown()-based
            // TestCase — Crucible's own, or (via phpunit/phpunit,
            // e.g. Orchestra Testbench) the real PHPUnit one. Real
            // Pest runs those as genuine PHPUnit test cases, so their
            // setUp() is what boots the app; skipping it silently
            // starved every Laravel-package Pest suite of its
            // container (confirmed against spatie/laravel-data).
            // Duck-typed, not an instanceof check, since either
            // TestCase family satisfies the same contract. Invoked by
            // reflection, not a bound closure, since setUp/tearDown
            // are protected and PHP has allowed reflection to reach
            // non-public methods without setAccessible() since 8.1.
            $hasLifecycle  = method_exists($instance, 'setUp') && method_exists($instance, 'tearDown');
            $isRealPhpUnit = method_exists($instance, 'numberOfAssertionsPerformed');

            // Scoped for Pest\Laravel\* (src/Bridge/PestLaravel), which
            // reaches "the instance currently running a test" the same
            // way real Pest's own plugin proxies do (test()'s zero-arg
            // form) — cleared in the finally block below regardless of
            // outcome, so a failure never leaks the instance into the
            // next test.
            CurrentTest::set($instance);

            // Scoped the same way, for SnapshotIdentityWrapper's
            // generated overrides (src/Bridge/PestSnapshots) — see
            // SnapshotFileContext's own docblock for why this exists
            // at all.
            SnapshotFileContext::set($snapshotsDirectory, $testFileBasename);

            if ($hasLifecycle) {
                if ($isRealPhpUnit) {
                    RealPhpUnitBootstrap::resetAssertionCount();
                }

                (new ReflectionMethod($instance, 'setUp'))->invoke($instance);
            }

            foreach ($state->beforeEach as $hook) {
                $invoke($hook);
            }

            try {
                // The spec evaluates closure-form skips after beforeEach,
                // so the condition can read hook-provided state.
                if ($call->skipped instanceof Closure) {
                    $verdict = $invoke($call->skipped);

                    if ($verdict !== false && $verdict !== null) {
                        throw new SkippedTestError(match (true) {
                            is_string($verdict)      => $verdict,
                            $call->skipReason !== '' => $call->skipReason,
                            default                  => 'Skipped.',
                        });
                    }
                }

                foreach ($hooks->before as $hook) {
                    (new ReflectionMethod($instance, $hook))->invoke($instance);
                }

                foreach ($hooks->preConditions as $hook) {
                    (new ReflectionMethod($instance, $hook))->invoke($instance);
                }

                // Bound rows (spec §4): a closure-valued dataset argument
                // resolves after beforeEach, bound to the instance — but
                // only when BOTH: it takes zero required parameters
                // (`fn() => new ArrayObject([$this->x])`, DESIGN.md's own
                // example — a closure requiring parameters can't be
                // zero-arg-invoked at all, confirmed against a real
                // spatie/laravel-data case, ->with(fn () => yield [...,
                // fn (LazyData $data) => $data->include('name'), ...]));
                // AND the test's own parameter at this position isn't
                // itself typed to accept a callable — `Closure
                // $temporaryPartial` (another real spatie/laravel-data
                // case) or `callable|null $setDefaults` wants the closure
                // itself, not what calling it produces, even when the
                // closure happens to take zero arguments. Named arguments
                // go last so positional ones stay positional.
                $positional = [];
                $named      = [];

                foreach ($arguments as $key => $argument) {
                    $argument = $argument instanceof Closure
                        && (new ReflectionFunction($argument))->getNumberOfRequiredParameters() === 0
                        && !self::targetParameterAcceptsCallable($call->test, $key)
                        ? $invoke($argument)
                        : $argument;

                    if (is_int($key)) {
                        $positional[] = $argument;
                    } else {
                        $named[$key] = $argument;
                    }
                }

                $args = [...$positional, ...$dependencyValues, ...$named];
                $body = self::body($call, $instance, $class);

                if ($call->fails) {
                    try {
                        $body($args);

                        // Verification failures are test failures, so
                        // fails() must cover them too (D-046).
                        if ($instance instanceof TestCase) {
                            $instance->verifyTestDoubles();
                        }
                    } catch (SkippedTestError|IncompleteTestError $signal) {
                        throw $signal;
                    } catch (Throwable $thrown) {
                        if ($call->failsMessage !== null) {
                            Assert::assertStringContainsString($call->failsMessage, $thrown->getMessage());
                        }

                        Assert::countSatisfiedAssertion(); // the expected failure happened

                        return null;
                    }

                    Assert::fail('Expected the test to fail, but it passed.');
                }

                try {
                    $value = $body($args);
                } catch (SkippedTestError|IncompleteTestError $signal) {
                    throw $signal;
                } catch (Throwable $thrown) {
                    if ($call->throws !== null) {
                        self::assertThrown($thrown, $call->throws, $call->throwsMessage);

                        return null;
                    }

                    // Real PHPUnit's own $this->expectException() family
                    // (PHPUnit\Framework\Assert, on a real
                    // PHPUnit-descended uses() class) — a separate
                    // mechanism from ->throws() above, only ever
                    // verified by real PHPUnit's own runner, which
                    // PestBuilder never delegates to. Rethrows its own
                    // AssertionFailedError when set but mismatched,
                    // same as real PHPUnit would fail the test rather
                    // than silently accept a wrong-shaped exception.
                    if (RealPhpUnitExceptionExpectations::wasExpected($instance, $thrown)) {
                        return null;
                    }

                    throw $thrown;
                }

                if ($call->throws !== null) {
                    Assert::fail(sprintf('Expected %s to be thrown, but nothing was.', $call->throws));
                }

                RealPhpUnitExceptionExpectations::verifyNotUnraised($instance);

                if ($call->throwsNothing) {
                    Assert::countSatisfiedAssertion(); // the absence was verified
                }

                // End-of-test double settlement (D-046) — the seam the
                // phpunit dialect gets from invokeTest. Skipped on the
                // expected-exception paths above, matching that spec.
                if ($instance instanceof TestCase) {
                    $instance->verifyTestDoubles();
                }

                // Success-path only, same as TestCase::invokeTest()'s
                // own placement — unlike before/preConditions, a
                // postCondition does not run on the fails()/throws()
                // early-return paths above, matching real PHPUnit:
                // it only fires once the test method itself completed
                // normally.
                foreach ($hooks->postConditions as $hook) {
                    (new ReflectionMethod($instance, $hook))->invoke($instance);
                }

                return $value;
            } finally {
                foreach ($state->afterEach as $hook) {
                    $invoke($hook);
                }

                // Unconditional, like tearDown() below — matching
                // TestCase::invokeTest()'s own "after/tearDown always
                // run" contract (HookPlan's own docblock).
                foreach ($hooks->after as $hook) {
                    (new ReflectionMethod($instance, $hook))->invoke($instance);
                }

                if ($hasLifecycle) {
                    (new ReflectionMethod($instance, 'tearDown'))->invoke($instance);
                }

                // Real PHPUnit's own TestCase tracks assertions
                // separately from Crucible's. Two sources feed it,
                // both invisible to Crucible's own counter without
                // bridging: Orchestra Testbench's Mockery integration
                // verifies expectations during tearDown() and reports
                // them via addToAssertionCount() directly; and
                // $this->assertSame()-style calls made anywhere in the
                // test count through a separate static counter that
                // only ever reaches the instance via
                // RealPhpUnitBootstrap::bridgeAssertionCount() (see its
                // own docblock). Without either, such tests are wrongly
                // flagged risky. Bridged the same way real Laravel test
                // helpers report into PHPUnit's counter
                // (Assert::addToAssertionCount()'s own docblock).
                if ($isRealPhpUnit) {
                    RealPhpUnitBootstrap::bridgeAssertionCount($instance);

                    $performed = $instance->numberOfAssertionsPerformed();

                    if (is_int($performed)) {
                        (new class extends Assert {})->addToAssertionCount($performed);
                    }
                }

                // Cleared last, after afterEach/tearDown have both had
                // their chance to still reach it — matching real Pest's
                // own scoping, where the instance stays "current" for
                // the whole test lifecycle, not just its body.
                CurrentTest::set(null);
                SnapshotFileContext::set(null, null);
            }
        };
    }

    /**
     * Whether the test closure's own parameter at this dataset slot is
     * itself typed to accept a callable — `Closure`/`callable`, bare or
     * inside a union — in which case a zero-arg closure-valued row must
     * be passed through as-is, not auto-invoked (see definition()'s
     * bound-rows comment). Only positional slots resolve; a named row
     * key can't be matched to a parameter by position.
     */
    private static function targetParameterAcceptsCallable(?Closure $test, int|string $key): bool
    {
        if (!$test instanceof Closure || !is_int($key)) {
            return false;
        }

        $parameters = (new ReflectionFunction($test))->getParameters();
        $type       = ($parameters[$key] ?? null)?->getType();

        return $type !== null && self::typeAcceptsCallable($type);
    }

    private static function typeAcceptsCallable(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return in_array($type->getName(), ['callable', 'Closure'], true);
        }

        if (!$type instanceof ReflectionUnionType) {
            // An intersection type: callable/Closure can't be a member
            // of one (both are non-interface types), so it never matches.
            return false;
        }

        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType && self::typeAcceptsCallable($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The runnable body: the test closure, or — higher-order tests —
     * the collected chain replayed against the instance. `->expect()`
     * re-roots the chain on an Expectation (its closure receives the
     * dataset/dependency arguments); every other step is called on
     * the running subject, so `get('/')->assertStatus(200)` reads the
     * way the spec writes it.
     *
     * @param class-string $class
     *
     * @return Closure(array<array-key, mixed>): mixed
     */
    private static function body(TestCall $call, object $instance, string $class): Closure
    {
        $test = $call->test;

        if ($test instanceof Closure) {
            return static function (array $args) use ($test, $instance, $class): mixed {
                $bound = @Closure::bind($test, $instance, $class);

                return ($bound ?? $test)(...$args);
            };
        }

        return static function (array $args) use ($call, $instance, $class): mixed {
            $subject = $instance;

            foreach ($call->chain as $position => [$member, $stepArguments, $isProperty]) {
                if ($isProperty) {
                    $subject = $subject->{$member};

                    continue;
                }

                $stepArguments = array_map(
                    static fn(mixed $argument): mixed => $argument instanceof Closure
                        ? (@Closure::bind($argument, $instance, $class) ?? $argument)
                        : $argument,
                    $stepArguments,
                );

                if ($subject === $instance && $member === 'expect') {
                    $value = $stepArguments[0] ?? null;

                    // toThrow needs the closure unevaluated — it invokes
                    // and observes it itself. Everything else expects the
                    // produced value.
                    $subject = new Expectation(
                        $value instanceof Closure && !self::nextMatcherIsToThrow($call->chain, $position)
                            ? $value(...$args)
                            : $value,
                    );

                    continue;
                }

                $subject = $subject->{$member}(...$stepArguments);
            }

            return null;
        };
    }

    /**
     * Whether the first matcher after the expect step (skipping
     * property reads like ->not) is toThrow.
     *
     * @param list<array{non-empty-string, list<mixed>, bool}> $chain
     */
    private static function nextMatcherIsToThrow(array $chain, int $expectPosition): bool
    {
        for ($i = $expectPosition + 1, $count = count($chain); $i < $count; ++$i) {
            [$member, , $isProperty] = $chain[$i];

            if ($isProperty) {
                continue;
            }

            return $member === 'toThrow';
        }

        return false;
    }

    /**
     * The spec's throws() dual reading: a class name checks the type,
     * anything else is a message fragment.
     *
     * @param non-empty-string $exception
     */
    private static function assertThrown(Throwable $thrown, string $exception, ?string $message): void
    {
        if (class_exists($exception) || interface_exists($exception)) {
            Assert::assertInstanceOf($exception, $thrown);
        } else {
            $message ??= $exception;
        }

        if ($message !== null) {
            Assert::assertStringContainsString($message, $thrown->getMessage());
        }
    }
}
