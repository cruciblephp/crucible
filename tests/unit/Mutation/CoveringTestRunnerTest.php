<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use Closure;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Mutation\CoveringTestRunner;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_values;

/**
 * The covering-test runner drives a real (tiny) suite and reports which
 * test detected the change — the kill — without any mutation or fork; the
 * fork and the mutant are {@see \LucianoPereira\Crucible\Mutation\MutantApplier}'s
 * job, proven separately.
 */
#[CoversClass(CoveringTestRunner::class)]
final class CoveringTestRunnerTest extends TestCase
{
    /**
     * @param non-empty-string $name
     */
    private function def(string $name, Closure $body): TestDefinition
    {
        return new TestDefinition(new TestId('t.php', $name), $body, MetadataCollection::from());
    }

    private function runner(TestDefinition ...$defs): CoveringTestRunner
    {
        return new CoveringTestRunner([new TestGroup('t.php', array_values($defs))], new WorkingDirectory('/tmp'));
    }

    public function testTheFirstFailingCoveringTestIsTheKiller(): void
    {
        $fails = $this->def('fails', static function (array $values): mixed {
            throw new AssertionFailedError('the mutant changed the result');
        });
        $passes = $this->def('passes', static fn(array $values): mixed => null);

        $killer = $this->runner($fails, $passes)->firstKiller(['t.php::fails', 't.php::passes']);

        self::assertSame('t.php::fails', $killer);
    }

    public function testAllCoveringTestsSurvivingYieldsNoKiller(): void
    {
        $passes = $this->def('passes', static fn(array $values): mixed => null);

        self::assertNull($this->runner($passes)->firstKiller(['t.php::passes']));
    }

    public function testCoveringIdsThatMatchNothingYieldNoKiller(): void
    {
        $passes = $this->def('passes', static fn(array $values): mixed => null);

        self::assertNull($this->runner($passes)->firstKiller(['t.php::absent']));
    }

    public function testTheCheapestCoveringTestGetsTheFirstShotAtTheKill(): void
    {
        $boom = static function (array $values): mixed {
            throw new AssertionFailedError('the mutant changed the result');
        };
        // Both would kill; declared slow-first, but the cache makes 'fast'
        // cheaper, so fastest-first runs it first and it takes the kill.
        $slow = $this->def('slow', $boom);
        $fast = $this->def('fast', $boom);

        $durations = new ResultCache();
        $durations->record('t.php::slow', Outcome::Passed, 0.5);
        $durations->record('t.php::fast', Outcome::Passed, 0.01);

        $runner = new CoveringTestRunner([new TestGroup('t.php', [$slow, $fast])], new WorkingDirectory('/tmp'), $durations);

        self::assertSame('t.php::fast', $runner->firstKiller(['t.php::slow', 't.php::fast']));
    }
}
