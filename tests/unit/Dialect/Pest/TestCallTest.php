<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Dialect\Pest\TestCall;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;

use function getenv;
use function putenv;

/**
 * The chainable handle test()/it() return.
 *
 * It is a mutable value object and nothing else — no browser, no
 * runner, no file being loaded — yet 75 of its 88 lines were reached by
 * nothing: not the self-hosted suite, and not spatie/schema-org's 1,926
 * real Pest tests, whose coverage was a strict subset of the suite's.
 * A real suite reaches the handful of chainables it happens to use, so
 * "a benchmark will cover it" was never going to.
 *
 * The chain is the whole pest surface a user touches before their test
 * body runs, and getting one of these wrong is silent: a skip that does
 * not skip reads exactly like a test that passed.
 */
#[CoversClass(TestCall::class)]
final class TestCallTest extends TestCase
{
    private ?string $ci = null;

    protected function setUp(): void
    {
        $ci       = getenv('CI');
        $this->ci = $ci === false ? null : $ci;
    }

    protected function tearDown(): void
    {
        // Process-global, and skipOnCi()/skipLocally() read it: leaving
        // it set would decide a later test's outcome.
        if ($this->ci === null) {
            putenv('CI');

            return;
        }

        putenv('CI=' . $this->ci);
    }

    public function testTheDeclaredNameCarriesTheItPrefixAndTheDescribePath(): void
    {
        self::assertSame('sums two numbers', (new TestCall('sums two numbers', 'test', null))->name());
        self::assertSame('it sums two numbers', (new TestCall('sums two numbers', 'it', null))->name());

        // Outermost first, and the prefix stays on the leaf.
        $nested = new TestCall('adds', 'it', null, ['Calculator', 'arithmetic']);
        self::assertSame('Calculator > arithmetic > it adds', $nested->name());
    }

    public function testHigherOrderChainingRecordsMethodsAndPropertyReads(): void
    {
        $call = new TestCall('rounds up', 'it', null);

        $call->expect(1.2)->not->toBe(2);

        // [name, arguments, isProperty] -- a property read is recorded
        // with no arguments and the flag set, because replaying ->not as
        // a method call would be a different thing entirely.
        self::assertSame([
            ['expect', [1.2], false],
            ['not', [], true],
            ['toBe', [2], false],
        ], $call->chain);
    }

    public function testChainingOnATestWithABodyIsATypoRatherThanAHigherOrderStep(): void
    {
        $call = new TestCall('sums', 'test', static fn(): int => 4);

        // Load time, not run time: with a body there is nothing to
        // replay the chain against, so this can only be a misspelling.
        try {
            $call->tobe(4);
            self::fail('a chainable typo on a test with a body must be refused');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString('tobe', $refusal->getMessage());
            self::assertStringContainsString('without a body', $refusal->getMessage());
        }

        try {
            $call->not; // @phpstan-ignore expr.resultUnused
            self::fail('a property read on a test with a body must be refused too');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString('not', $refusal->getMessage());
        }
    }

    public function testEachWithCallIsOneCartesianFactorAndKeepsItsRowKeys(): void
    {
        $call = new TestCall('sums', 'test', null);

        $call->with([1, 2])->with('shared-dataset')->with(['named row' => [3]]);

        $lazy = static fn(): array => [[4]];
        $call->with($lazy);

        self::assertSame([[1, 2], 'shared-dataset', ['named row' => [3]], $lazy], $call->datasets);
    }

    public function testWithAcceptsAnyIterableAndMaterialisesIt(): void
    {
        $call = new TestCall('sums', 'test', null);

        $call->with((static function (): iterable {
            yield 'first' => [1];
            yield 'second' => [2];
        })());

        // A generator is consumed once, so the rows are taken now
        // rather than stored as something a second read would exhaust.
        self::assertSame([['first' => [1], 'second' => [2]]], $call->datasets);
    }

    public function testSkipDistinguishesAllFourOfItsArgumentShapes(): void
    {
        // false: not skipped, and the reason is not recorded either.
        $none = (new TestCall('a', 'test', null))->skip(false, 'unused');
        self::assertFalse($none->skipped);
        self::assertSame('', $none->skipReason);

        // true with no reason stays true; true with one becomes it.
        self::assertTrue((new TestCall('a', 'test', null))->skip()->skipped);
        self::assertSame('why', (new TestCall('a', 'test', null))->skip(true, 'why')->skipped);

        // A bare string IS the reason.
        self::assertSame('not ready', (new TestCall('a', 'test', null))->skip('not ready')->skipped);
    }

    public function testAClosureSkipIsKeptUnevaluatedWithItsReasonBeside(): void
    {
        $condition = static fn(): bool => true;
        $call      = (new TestCall('a', 'test', null))->skip($condition, 'decided later');

        // Deliberately not called here: the spec evaluates it after
        // beforeEach, so evaluating at declaration would ask before the
        // state it depends on exists.
        self::assertSame($condition, $call->skipped);
        self::assertSame('decided later', $call->skipReason);
    }

    public function testThePlatformSkipsAgreeWithThisMachine(): void
    {
        // PHP_OS_FAMILY is Linux in this suite; asserting the pair keeps
        // the test honest on a machine where it is not.
        $linux = PHP_OS_FAMILY === 'Linux';

        $skipHere = (new TestCall('a', 'test', null))->skipOnLinux();
        $onlyHere = (new TestCall('a', 'test', null))->onlyOnLinux();

        self::assertSame($linux ? 'Skipped on Linux.' : false, $skipHere->skipped);
        self::assertSame($linux ? false : 'Only runs on Linux.', $onlyHere->skipped);

        // The other two families cannot both be this one, so exactly one
        // of each pair fires whatever machine runs it.
        self::assertSame(PHP_OS_FAMILY === 'Windows', (new TestCall('a', 'test', null))->skipOnWindows()->skipped !== false);
        self::assertSame(PHP_OS_FAMILY === 'Darwin', (new TestCall('a', 'test', null))->skipOnMac()->skipped !== false);
        self::assertSame(PHP_OS_FAMILY !== 'Windows', (new TestCall('a', 'test', null))->onlyOnWindows()->skipped !== false);
        self::assertSame(PHP_OS_FAMILY !== 'Darwin', (new TestCall('a', 'test', null))->onlyOnMac()->skipped !== false);
    }

    public function testCiSkipsReadTheEnvironmentAndTreatFalseyValuesAsNotCi(): void
    {
        putenv('CI=true');
        self::assertSame('Skipped on CI.', (new TestCall('a', 'test', null))->skipOnCi()->skipped);
        self::assertFalse((new TestCall('a', 'test', null))->skipLocally()->skipped);

        // '0', 'false' and '' are set-but-not-CI, which is how CI
        // variables are commonly turned off rather than unset.
        foreach (['0', 'false', 'FALSE', ''] as $off) {
            putenv('CI=' . $off);

            self::assertFalse(
                (new TestCall('a', 'test', null))->skipOnCi()->skipped,
                'CI=' . $off . ' must not count as CI',
            );
            self::assertSame('Skipped locally.', (new TestCall('a', 'test', null))->skipLocally()->skipped);
        }

        putenv('CI');
        self::assertFalse((new TestCall('a', 'test', null))->skipOnCi()->skipped);
    }

    public function testSkipOnPhpUnderstandsEveryOperatorTheSpecWrites(): void
    {
        // A constraint that holds on any PHP this can run on, and one
        // that cannot: the pair pins the operator rather than a version.
        foreach (['>=8.0.0', '>8.0.0', '!=1.0.0', '<>1.0.0'] as $holds) {
            self::assertSame(
                'Skipped on PHP ' . $holds . '.',
                (new TestCall('a', 'test', null))->skipOnPhp($holds)->skipped,
                $holds,
            );
        }

        foreach (['<8.0.0', '<=1.0.0', '==1.0.0', '=1.0.0', '1.0.0'] as $fails) {
            self::assertFalse((new TestCall('a', 'test', null))->skipOnPhp($fails)->skipped, $fails);
        }
    }

    public function testAConstraintNamingNoVersionIsRefused(): void
    {
        // Newline rather than '': the parameter is a non-empty-string,
        // so '' is a lie the type gate catches and not a case a caller
        // can reach. ✓ Measured that this one does reach the refusal --
        // `.` matches no newline and `\s*` can leave `.+` nothing, so
        // the constraint pattern does not match at all.
        try {
            (new TestCall('a', 'test', null))->skipOnPhp("\n");
            self::fail('a constraint naming no version cannot be compared against');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString('not a version constraint', $refusal->getMessage());
        }
    }

    public function testTodoWipAndDoneCarryTheirStatusAndMetadata(): void
    {
        $todo = (new TestCall('a', 'test', null))->todo('ada', 42, 'later')->todo;
        self::assertNotNull($todo);
        self::assertSame(TodoStatus::Todo, $todo->status);
        self::assertSame('ada', $todo->assignee);
        self::assertSame(42, $todo->issue);
        self::assertSame('later', $todo->note);

        $wip = (new TestCall('a', 'test', null))->wip()->todo;
        self::assertNotNull($wip);
        self::assertSame(TodoStatus::Wip, $wip->status);

        $done = (new TestCall('a', 'test', null))->done()->todo;
        self::assertNotNull($done);
        self::assertSame(TodoStatus::Done, $done->status);
    }

    public function testFailsRecordsTheExpectationAndItsOptionalFragment(): void
    {
        $bare = (new TestCall('a', 'test', null))->fails();
        self::assertTrue($bare->fails);
        self::assertNull($bare->failsMessage);

        $fragment = (new TestCall('a', 'test', null))->fails('division by zero');
        self::assertSame('division by zero', $fragment->failsMessage);
    }

    public function testExpectsSkipAndExpectsIncompleteRecordTheOutcomeTheyDemand(): void
    {
        $skip = (new TestCall('a', 'test', null))->expectsSkip('needs docker');
        self::assertNotNull($skip->expectedOutcome);
        self::assertSame(Outcome::Skipped, $skip->expectedOutcome->expected);
        self::assertSame('needs docker', $skip->expectedOutcome->reasonFragment);

        $incomplete = (new TestCall('a', 'test', null))->expectsIncomplete();
        self::assertNotNull($incomplete->expectedOutcome);
        self::assertSame(Outcome::Incomplete, $incomplete->expectedOutcome->expected);
        self::assertNull($incomplete->expectedOutcome->reasonFragment);
    }

    public function testThrowsRecordsAClassOrAFragmentAndAnOptionalMessage(): void
    {
        $call = (new TestCall('a', 'test', null))->throws(RuntimeException::class, 'boom');

        self::assertSame(RuntimeException::class, $call->throws);
        self::assertSame('boom', $call->throwsMessage);

        $nothing = (new TestCall('a', 'test', null))->throwsNoExceptions();
        self::assertTrue($nothing->throwsNothing);
        self::assertNull($nothing->throws);
    }

    public function testThrowsIfAndThrowsUnlessAreOppositesOverBothConditionForms(): void
    {
        foreach ([true, false] as $condition) {
            $if     = (new TestCall('a', 'test', null))->throwsIf($condition, RuntimeException::class);
            $unless = (new TestCall('a', 'test', null))->throwsUnless($condition, RuntimeException::class);

            self::assertSame($condition ? RuntimeException::class : null, $if->throws);
            self::assertSame($condition ? null : RuntimeException::class, $unless->throws);

            // A closure decides the same way a bool does, and is called
            // once, here -- these are declaration-time conditions.
            $lazyIf     = (new TestCall('a', 'test', null))->throwsIf(static fn(): bool => $condition, RuntimeException::class);
            $lazyUnless = (new TestCall('a', 'test', null))->throwsUnless(static fn(): bool => $condition, RuntimeException::class);

            self::assertSame($if->throws, $lazyIf->throws);
            self::assertSame($unless->throws, $lazyUnless->throws);
        }
    }

    public function testRepeatTakesAPositiveCountAndRefusesAnythingElse(): void
    {
        self::assertSame(1, (new TestCall('a', 'test', null))->repetitions);
        self::assertSame(5, (new TestCall('a', 'test', null))->repeat(5)->repetitions);

        foreach ([0, -1] as $impossible) {
            try {
                (new TestCall('a', 'test', null))->repeat($impossible);
                self::fail('repeat(' . $impossible . ') asks for no test at all');
            } catch (ConfigurationException $refusal) {
                self::assertStringContainsString('positive', $refusal->getMessage());
            }
        }
    }

    public function testGroupsAndDependenciesAccumulateAcrossCalls(): void
    {
        $call = (new TestCall('a', 'test', null))
            ->group('slow', 'network')
            ->group('flaky')
            ->depends('it sums')
            ->depends('it rounds', 'it floors');

        // Appended, not replaced: two ->group() calls mean both.
        self::assertSame(['slow', 'network', 'flaky'], $call->groups);
        self::assertSame(['it sums', 'it rounds', 'it floors'], $call->dependsOn);
    }
}
