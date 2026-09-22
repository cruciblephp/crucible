<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use Closure;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\Repetition;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_map;
use function array_values;

#[CoversClass(Repetition::class)]
final class RepetitionTest extends TestCase
{
    /**
     * @param non-empty-string ...$names
     */
    private function group(string ...$names): TestGroup
    {
        return new TestGroup('A', array_values(array_map(
            static fn(string $name): TestDefinition => new TestDefinition(
                new TestId('tests/FixtureTest.php', $name),
                static fn(array $values): mixed => null,
                MetadataCollection::from(),
            ),
            $names,
        )));
    }

    /**
     * @return list<string>
     */
    private function names(TestGroup $group): array
    {
        return array_map(static fn(TestDefinition $test): string => $test->id->name, $group->tests);
    }

    public function testRepetitionsAreAdjacentPerTestNotPerGroup(): void
    {
        $repeated = Repetition::apply([$this->group('one', 'two')], 3);

        // The spec repeats each method, not the run: "one" three times,
        // then "two" three times, never the pair three times over.
        $this->assertSame(
            ['one', 'one', 'one', 'two', 'two', 'two'],
            $this->names($repeated[0]),
        );
    }

    public function testAnythingUnderTwoLeavesThePlanUntouched(): void
    {
        $groups = [$this->group('one')];

        foreach ([null, 1, 0, -3] as $times) {
            $this->assertSame(['one'], $this->names(Repetition::apply($groups, $times)[0]));
        }
    }

    public function testTheGroupKeepsItsNameAndLifecycleHooks(): void
    {
        $ran  = 0;
        $hook = static function () use (&$ran): void {
            $ran++;
        };
        $group = new TestGroup('A', $this->group('one')->tests, $hook, $hook);

        $repeated = Repetition::apply([$group], 2)[0];

        $this->assertSame('A', $repeated->name);
        $this->assertSame(['one', 'one'], $this->names($repeated));

        // The hooks travel with the group, still the ones it was given.
        self::assertInstanceOf(Closure::class, $repeated->beforeAll);
        self::assertInstanceOf(Closure::class, $repeated->afterAll);

        ($repeated->beforeAll)();
        ($repeated->afterAll)();

        $this->assertSame(2, $ran);
    }
}
