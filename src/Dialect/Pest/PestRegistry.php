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
use LucianoPereira\Crucible\Architecture\Architecture;
use LucianoPereira\Crucible\Architecture\ArchRule;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Property\Gen;
use LucianoPereira\Crucible\Property\Property;
use ReflectionFunction;

use function array_map;
use function array_pop;
use function array_slice;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function get_debug_type;
use function implode;
use function is_array;
use function is_object;
use function is_string;
use function ltrim;
use function mb_strlen;
use function mb_substr;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;
use function token_get_all;
use function trim;

use const T_COMMENT;

/**
 * Where the pest dialect's global functions deposit what a *.pest.php
 * file declares while it is being required. One file is collected at
 * a time (loading is sequential); the builder drains the state after
 * the require returns.
 */
final class PestRegistry
{
    /** @var list<TestCall> */
    private static array $calls = [];

    /** @var list<non-empty-string> */
    private static array $describePath = [];

    /** @var list<Closure> */
    private static array $beforeEach = [];

    /** @var list<Closure> */
    private static array $afterEach = [];

    /** @var list<Closure> */
    private static array $beforeAll = [];

    /** @var list<Closure> */
    private static array $afterAll = [];

    /** @var list<ScopeRegistration> */
    private static array $registrations = [];

    /**
     * Derived name → occurrences, per file (check()/table() uniqueness).
     *
     * @var array<string, int>
     */
    private static array $autoNames = [];

    /**
     * Composed names already declared in this file, as a set: the
     * duplicate check is per test() call, so a linear scan of the calls
     * would be quadratic over a large file.
     *
     * @var array<string, true>
     */
    private static array $takenNames = [];

    /**
     * File-level covers() targets, applied to every test the file
     * declares — the file-scoped spelling of #[CoversClass].
     *
     * @var list<non-empty-string>
     */
    private static array $covers = [];

    /**
     * File-level mutates() targets — the source `crucible mutate`
     * narrows to.
     *
     * @var list<non-empty-string>
     */
    private static array $mutates = [];

    /**
     * Source lines per file, for check() auto-naming.
     *
     * @var array<string, list<string>>
     */
    private static array $sources = [];

    /** @var ?non-empty-string */
    private static ?string $file = null;

    private static bool $collecting = false;

    private static string $root = '';

    /**
     * @param non-empty-string $file the *.pest.php file about to be required
     * @param string           $root the project root the file sits under, for fixture()
     */
    public static function begin(string $file, string $root = ''): void
    {
        self::$root = rtrim($root, '/');

        self::$calls         = [];
        self::$describePath  = [];
        self::$beforeEach    = [];
        self::$afterEach     = [];
        self::$beforeAll     = [];
        self::$afterAll      = [];
        self::$registrations = [];
        self::$autoNames     = [];
        self::$takenNames    = [];
        self::$covers        = [];
        self::$mutates       = [];
        self::$file          = $file;
        self::$collecting    = true;
    }

    public static function drain(): PestFileState
    {
        self::$collecting = false;
        self::$file       = null;

        // Fold the file's uses() chains: last class wins, traits and
        // groups accumulate, chained hooks join the file-level ones.
        $class      = null;
        $traits     = [];
        $groups     = [];
        $beforeEach = self::$beforeEach;
        $afterEach  = self::$afterEach;
        $beforeAll  = self::$beforeAll;
        $afterAll   = self::$afterAll;

        foreach (self::$registrations as $registration) {
            $class      = $registration->class ?? $class;
            $traits     = [...$traits, ...$registration->traits];
            $groups     = [...$groups, ...$registration->groups];
            $beforeEach = [...$beforeEach, ...$registration->beforeEach];
            $afterEach  = [...$afterEach, ...$registration->afterEach];
            $beforeAll  = [...$beforeAll, ...$registration->beforeAll];
            $afterAll   = [...$afterAll, ...$registration->afterAll];
        }

        return new PestFileState(
            self::$calls,
            $beforeEach,
            $afterEach,
            $beforeAll,
            $afterAll,
            $class,
            $traits,
            $groups,
            self::$covers,
            self::$mutates,
        );
    }

    /**
     * @param non-empty-string ...$targets
     */
    public static function covers(string ...$targets): void
    {
        foreach ($targets as $target) {
            self::$covers[] = $target;
        }
    }

    /**
     * @param non-empty-string ...$targets
     */
    public static function mutates(string ...$targets): void
    {
        foreach ($targets as $target) {
            self::$mutates[] = $target;
        }
    }

    /**
     * The directory of the file currently being collected — where
     * dataset() declarations made inside a test file are scoped.
     *
     * @return ?non-empty-string
     */
    public static function currentFileDirectory(): ?string
    {
        return self::$file === null ? null : dirname(self::$file);
    }

    /**
     * The path to a fixture file: `tests/Fixtures/` under the project
     * root, always, whatever directory the calling test file sits in.
     *
     * Measured against Pest 5.1.1 rather than guessed, because the
     * obvious rule is the wrong one. Crucible first resolved this
     * against the *nearest* `Fixtures/` at or above the test file,
     * which returns a different file whenever a project has more than
     * one — and the incumbent has no such rule. Probed from
     * `tests/nested/deep/`, with `tests/nested/Fixtures/sample.txt`
     * present and nearer, it still resolved `tests/Fixtures/sample.txt`;
     * probed from a second test-suite directory, it still looked under
     * `tests/`, not that suite's own.
     *
     * The name is joined to that directory, so `fixture('Fixtures/x')`
     * is an error there and here — the incumbent prepends `Fixtures/`
     * itself.
     *
     * A missing file is refused: a fixture path that silently does not
     * exist fails later, somewhere else, as a confusing read error.
     *
     * @param non-empty-string $path
     *
     * @return non-empty-string absolute path
     */
    public static function fixture(string $path): string
    {
        if (self::$file === null) {
            throw new ConfigurationException(
                'fixture() is only available while a test file is being collected.',
            );
        }

        $candidate = self::$root . '/tests/Fixtures/' . $path;

        if (!file_exists($candidate)) {
            throw new ConfigurationException(sprintf('fixture(%s): the fixture file [%s] does not exist.', $path, $candidate));
        }

        $real = realpath($candidate);

        return $real === false ? $candidate : $real;
    }

    /**
     * @param non-empty-string $description
     * @param 'test'|'it'      $kind
     */
    public static function test(string $description, ?Closure $test, string $kind): TestCall
    {
        self::ensureCollecting($kind === 'it' ? 'it()' : 'test()');

        $call = new TestCall($description, $kind, $test, self::$describePath);
        $name = $call->name();

        // ✓ Measured against Pest 5.1.1, not inferred: a second test
        // with the same composed name is refused at COLLECTION, with
        // Pest\Exceptions\TestAlreadyExist and exit 1, and nothing in
        // the file runs -- neither body, not even the first. Crucible
        // used to accept both, and since a name IS the TestId that
        // quietly filed two tests under one id: the shape where a
        // result stops meaning what it says.
        //
        // Composed, because scoping is what the incumbent counts.
        // ✓ Measured in the same run: `group A > shared` and
        // `group B > shared` coexist, and `it shared` is not `shared`.
        if (isset(self::$takenNames[$name])) {
            throw new ConfigurationException(sprintf(
                'A test named [%s] already exists in [%s]. Please give this test a different description.',
                $name,
                self::$file ?? '',
            ));
        }

        self::$takenNames[$name] = true;
        self::$calls[]           = $call;

        return $call;
    }

    /**
     * An architecture rule (D-088): a fluent rule that registers itself
     * as an ordinary test. The closure captures the rule object, so
     * expectations chained on after this call are visible by the time
     * the test runs — built at collection time, asserted at execution
     * time, like every other deferred body here.
     *
     * @param ?non-empty-string $description
     */
    public static function arch(?string $description = null): ArchRule
    {
        self::ensureCollecting('arch()');

        $rule = Architecture::rule();

        self::test($description ?? 'architecture', static function () use ($rule): void {
            $rule->assert();
        }, 'test');

        return $rule;
    }

    /**
     * The crucible dialect's nameless test (G2b): a deferred value with
     * the whole expectation surface chained directly on the handle.
     * The name comes from the source line unless one is given.
     *
     * @param ?non-empty-string $description
     */
    public static function check(Closure $test, ?string $description = null): TestCall
    {
        self::ensureCollecting('check()');

        $call          = self::test($description ?? self::autoName($test), null, 'test');
        $call->chain[] = ['expect', [$test], false];

        return $call;
    }

    /**
     * The crucible dialect's property test (G5): generators first, the
     * property closure last, one test on the engine's property runner.
     *
     * @param non-empty-string $description
     * @param Gen<mixed>|Closure ...$arguments
     */
    public static function property(string $description, Gen|Closure ...$arguments): TestCall
    {
        self::ensureCollecting('property()');

        $arguments = array_values($arguments);
        $body      = array_pop($arguments);

        if (!$body instanceof Closure) {
            throw new ConfigurationException('property() takes the property closure as its last argument.');
        }

        $generators = [];

        foreach ($arguments as $generator) {
            if (!$generator instanceof Gen) {
                throw new ConfigurationException('property() takes generators between the description and the closure.');
            }

            $generators[] = $generator;
        }

        if ($generators === []) {
            throw new ConfigurationException('property() needs at least one generator.');
        }

        return self::test($description, static function () use ($generators, $body): void {
            Property::forAll(...$generators)->check($body);
        }, 'test');
    }

    /**
     * The crucible dialect's I/O table (G2b): one test per row, the last
     * element being the expected value (toEqual semantics), everything
     * before it the arguments. A string row key names the case.
     *
     * @param iterable<array-key, mixed> $rows
     */
    public static function table(callable $subject, iterable $rows): DescribeCall
    {
        self::ensureCollecting('table()');

        $base  = self::callableName($subject);
        $calls = [];

        foreach ($rows as $key => $row) {
            if (!is_array($row) || $row === []) {
                throw new ConfigurationException(
                    'table() rows are arrays of [arguments..., expected value] — got a ' . get_debug_type($row) . ' row.',
                );
            }

            $arguments = array_values($row);
            $expected  = array_pop($arguments);

            $name = is_string($key)
                ? $base . ': ' . $key
                : $base . '(' . implode(', ', array_map(Exporter::describe(...), $arguments)) . ') = ' . Exporter::describe($expected);

            $call          = self::test(self::uniqueName($name), null, 'test');
            $call->chain[] = ['expect', [static fn(): mixed => $subject(...$arguments)], false];
            $call->chain[] = ['toEqual', [$expected], false];

            $calls[] = $call;
        }

        return new DescribeCall($calls);
    }

    /**
     * @param non-empty-string $description
     */
    public static function describe(string $description, Closure $body): DescribeCall
    {
        self::ensureCollecting('describe()');

        $first = count(self::$calls);

        self::$describePath[] = $description;

        try {
            $body();
        } finally {
            array_pop(self::$describePath);
        }

        return new DescribeCall(array_slice(self::$calls, $first));
    }

    public static function beforeEach(Closure $hook): void
    {
        self::ensureCollecting('beforeEach()');
        self::$beforeEach[] = $hook;
    }

    public static function afterEach(Closure $hook): void
    {
        self::ensureCollecting('afterEach()');
        self::$afterEach[] = $hook;
    }

    public static function beforeAll(Closure $hook): void
    {
        self::ensureCollecting('beforeAll()');
        self::$beforeAll[] = $hook;
    }

    public static function afterAll(Closure $hook): void
    {
        self::ensureCollecting('afterAll()');
        self::$afterAll[] = $hook;
    }

    /**
     * The legacy spelling: classes and traits mixed in one call. In
     * Pest.php it registers a suite scope; in a test file it is
     * file-local.
     */
    public static function uses(string ...$names): ScopeRegistration
    {
        if (PestScopes::configuring()) {
            return PestScopes::uses(...$names);
        }

        self::ensureCollecting('uses()');

        $file = self::$file ?? throw new ConfigurationException(
            'uses() can only be called while a *.pest.php file is being loaded.',
        );

        $registration = (new ScopeRegistration(dirname($file), fromConfigFile: false))->assign(...$names);

        self::$registrations[] = $registration;

        return $registration;
    }

    private static function ensureCollecting(string $function): void
    {
        if (!self::$collecting) {
            throw new ConfigurationException(
                $function . ' can only be called while a *.pest.php file is being loaded.',
            );
        }
    }

    /**
     * A check()'s name, in order of preference: the trailing comment
     * on its line (`check(...)->toBe(3); // adds small numbers`), the
     * source line itself, the line number when the source is
     * unreadable.
     *
     * @return non-empty-string
     */
    private static function autoName(Closure $test): string
    {
        $reflection = new ReflectionFunction($test);
        $file       = $reflection->getFileName();
        $line       = $reflection->getStartLine();

        $snippet = $file === false || $line === false ? '' : trim(self::sourceLine($file, $line));
        $comment = self::trailingComment($snippet);

        if ($comment !== null) {
            return self::uniqueName($comment);
        }

        if (mb_strlen($snippet) > 100) {
            $snippet = mb_substr($snippet, 0, 100) . '…';
        }

        return self::uniqueName($snippet === '' ? 'check on line ' . (int) $line : $snippet);
    }

    /**
     * The text of a trailing comment on the line, tokenizer-parsed so
     * a '//' inside a string can never false-match. Handles the //,
     * #, and inline /* *​/ forms.
     *
     * @return ?non-empty-string
     */
    private static function trailingComment(string $line): ?string
    {
        if ($line === '' || (!str_contains($line, '//') && !str_contains($line, '#') && !str_contains($line, '/*'))) {
            return null;
        }

        foreach (@token_get_all('<?php ' . $line) as $token) {
            if (!is_array($token) || $token[0] !== T_COMMENT) {
                continue;
            }

            $text = $token[1];

            $text = str_starts_with($text, '/*')
                ? substr($text, 2, -2)
                : ltrim($text, '/#');

            $text = trim($text);

            return $text === '' ? null : $text;
        }

        return null;
    }

    /**
     * Names must be unique within a file — they are the TestId. Two
     * identical snippets (or table rows) get an occurrence suffix.
     *
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    private static function uniqueName(string $name): string
    {
        $occurrence = self::$autoNames[$name] = (self::$autoNames[$name] ?? 0) + 1;

        return $occurrence > 1 ? $name . ' #' . $occurrence : $name;
    }

    private static function sourceLine(string $file, int $line): string
    {
        $lines = self::$sources[$file] ??= (static function (string $file): array {
            $contents = file_get_contents($file);

            return $contents === false ? [] : explode("\n", $contents);
        })($file);

        return $lines[$line - 1] ?? '';
    }

    /** @return non-empty-string */
    private static function callableName(callable $subject): string
    {
        if (is_string($subject)) {
            return $subject;
        }

        if (is_array($subject)) {
            [$classOrObject, $method] = $subject;

            return (is_object($classOrObject) ? $classOrObject::class : $classOrObject) . '::' . $method;
        }

        if ($subject instanceof Closure) {
            $reflection = new ReflectionFunction($subject);
            $name       = $reflection->getName();

            if (!str_contains($name, '{closure')) {
                return $name;
            }

            return 'table on line ' . (int) $reflection->getStartLine();
        }

        return get_debug_type($subject) . '::__invoke';
    }
}
