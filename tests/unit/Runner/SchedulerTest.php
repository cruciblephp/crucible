<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Large;
use LucianoPereira\Crucible\Attributes\Medium;
use LucianoPereira\Crucible\Attributes\Small;
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Runner\Scheduler;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_map;
use function array_search;
use function array_values;
use function sort;

#[CoversClass(Scheduler::class)]
final class SchedulerTest extends TestCase
{
    /**
     * @param non-empty-string       $name
     * @param list<non-empty-string> $dependencies
     */
    private static function definition(string $name, array $dependencies = [], CrucibleAttribute ...$attributes): TestDefinition
    {
        return new TestDefinition(
            new TestId('tests/FixtureTest.php', $name),
            static fn(array $values): mixed => null,
            MetadataCollection::from(...$attributes),
            $dependencies,
        );
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string ...$tests
     */
    private function group(string $name, string ...$tests): TestGroup
    {
        return new TestGroup($name, array_values(array_map(
            static fn(string $test): TestDefinition => self::definition($test),
            $tests,
        )));
    }

    /**
     * @return list<non-empty-string>
     */
    private function names(TestGroup $group): array
    {
        return array_map(static fn(TestDefinition $test): string => $test->id->name, $group->tests);
    }

    public function testDeclaredOrderIsUntouched(): void
    {
        $groups    = [$this->group('A', 'one', 'two'), $this->group('B', 'three')];
        $scheduled = (new Scheduler(ExecutionOrder::Declared))->schedule($groups);

        $this->assertSame(['A', 'B'], array_map(static fn(TestGroup $group): string => $group->name, $scheduled));
        $this->assertSame(['one', 'two'], $this->names($scheduled[0]));
    }

    public function testReverseReversesGroupsAndTestsWithinGroups(): void
    {
        $groups    = [$this->group('A', 'one', 'two'), $this->group('B', 'three')];
        $scheduled = (new Scheduler(ExecutionOrder::Reversed))->schedule($groups);

        $this->assertSame(['B', 'A'], array_map(static fn(TestGroup $group): string => $group->name, $scheduled));
        $this->assertSame(['two', 'one'], $this->names($scheduled[1]));
    }

    public function testRandomOrderIsReplayableFromItsSeed(): void
    {
        $groups = [$this->group('A', 'one', 'two', 'three', 'four'), $this->group('B', 'five', 'six')];

        $first  = (new Scheduler(ExecutionOrder::Random, seed: 42))->schedule($groups);
        $replay = (new Scheduler(ExecutionOrder::Random, seed: 42))->schedule($groups);

        $this->assertSame(
            array_map($this->names(...), $first),
            array_map($this->names(...), $replay),
        );
    }

    public function testRandomOrderIsAPermutationOfTheSuite(): void
    {
        $groups    = [$this->group('A', 'one', 'two', 'three', 'four', 'five')];
        $scheduled = (new Scheduler(ExecutionOrder::Random, seed: 7))->schedule($groups);

        $names = $this->names($scheduled[0]);
        sort($names);

        $this->assertSame(['five', 'four', 'one', 'three', 'two'], $names);
    }

    public function testDefectsFirstWeighsRecencyOverSeverity(): void
    {
        $history = new ResultCache();

        // brokeYesterday: failed on the most recent run.
        $history->record('tests/FixtureTest.php::brokeYesterday', Outcome::Failed, 0.1);

        // erroredLastWeek: an error, but two runs back — halved twice.
        $history->record('tests/FixtureTest.php::erroredLastWeek', Outcome::Errored, 0.1);
        $history->record('tests/FixtureTest.php::erroredLastWeek', Outcome::Passed, 0.1);
        $history->record('tests/FixtureTest.php::erroredLastWeek', Outcome::Passed, 0.1);

        $history->record('tests/FixtureTest.php::alwaysGreen', Outcome::Passed, 0.1);

        $groups    = [$this->group('A', 'alwaysGreen', 'erroredLastWeek', 'brokeYesterday')];
        $scheduled = (new Scheduler(ExecutionOrder::DefectsFirst, history: $history))->schedule($groups);

        $this->assertSame(['brokeYesterday', 'erroredLastWeek', 'alwaysGreen'], $this->names($scheduled[0]));
    }

    public function testDefectsFirstBreaksTiesTowardShorterTests(): void
    {
        $history = new ResultCache();
        $history->record('tests/FixtureTest.php::slowFailure', Outcome::Failed, 3.0);
        $history->record('tests/FixtureTest.php::quickFailure', Outcome::Failed, 0.01);

        $groups    = [$this->group('A', 'slowFailure', 'quickFailure')];
        $scheduled = (new Scheduler(ExecutionOrder::DefectsFirst, history: $history))->schedule($groups);

        $this->assertSame(['quickFailure', 'slowFailure'], $this->names($scheduled[0]));
    }

    public function testDurationOrderRunsFastestFirst(): void
    {
        $history = new ResultCache();
        $history->record('tests/FixtureTest.php::slow', Outcome::Passed, 2.0);
        $history->record('tests/FixtureTest.php::quick', Outcome::Passed, 0.01);

        // 'unseen' has no history and sorts as zero — before everything.
        $groups    = [$this->group('A', 'slow', 'quick', 'unseen')];
        $scheduled = (new Scheduler(ExecutionOrder::Duration, history: $history))->schedule($groups);

        $this->assertSame(['unseen', 'quick', 'slow'], $this->names($scheduled[0]));
    }

    public function testSizeOrderRunsSmallBeforeMediumBeforeLargeBeforeUnsized(): void
    {
        $group = new TestGroup('A', [
            self::definition('unsized'),
            self::definition('large', [], new Large()),
            self::definition('small', [], new Small()),
            self::definition('medium', [], new Medium()),
        ]);

        $scheduled = (new Scheduler(ExecutionOrder::SizeAscending))->schedule([$group]);

        $this->assertSame(['small', 'medium', 'large', 'unsized'], $this->names($scheduled[0]));
    }

    public function testReorderingNeverRunsADependentBeforeItsDependency(): void
    {
        $group = new TestGroup('A', [
            self::definition('base'),
            self::definition('builds', ['base']),
            self::definition('tops', ['builds']),
            self::definition('unrelated'),
        ]);

        foreach ([ExecutionOrder::Reversed, ExecutionOrder::Random, ExecutionOrder::DefectsFirst, ExecutionOrder::Duration] as $order) {
            $names = $this->names((new Scheduler($order, seed: 99))->schedule([$group])[0]);

            $this->assertLessThan(array_search('builds', $names, true), array_search('base', $names, true));
            $this->assertLessThan(array_search('tops', $names, true), array_search('builds', $names, true));
        }
    }

    public function testDatasetRowsTravelAsOneBlockThroughRepair(): void
    {
        $rowA = new TestDefinition(
            new TestId('tests/FixtureTest.php', 'rows', 'a'),
            static fn(array $values): mixed => null,
            MetadataCollection::from(),
        );
        $rowB = new TestDefinition(
            new TestId('tests/FixtureTest.php', 'rows', 'b'),
            static fn(array $values): mixed => null,
            MetadataCollection::from(),
        );
        $group = new TestGroup('A', [$rowA, $rowB, self::definition('dependsOnRows', ['rows'])]);

        $scheduled = (new Scheduler(ExecutionOrder::Reversed))->schedule([$group]);

        $this->assertSame(['rows', 'rows', 'dependsOnRows'], $this->names($scheduled[0]));
    }

    public function testIgnoringDependenciesLeavesTheOrderingAlone(): void
    {
        $group = new TestGroup('A', [
            self::definition('base'),
            self::definition('builds', ['base']),
        ]);

        $repaired = (new Scheduler(ExecutionOrder::Reversed))->schedule([$group])[0];
        $ignored  = (new Scheduler(ExecutionOrder::Reversed, resolveDependencies: false))->schedule([$group])[0];

        $this->assertSame(['base', 'builds'], $this->names($repaired));
        $this->assertSame(['builds', 'base'], $this->names($ignored));
    }
}
