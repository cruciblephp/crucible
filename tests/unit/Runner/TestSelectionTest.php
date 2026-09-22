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
use LucianoPereira\Crucible\Attributes\CoversMethod;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Attributes\UsesClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\NameFilter;
use LucianoPereira\Crucible\Runner\TestSelection;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_map;
use function array_values;

#[CoversClass(NameFilter::class)]
#[CoversClass(TestSelection::class)]
final class TestSelectionTest extends TestCase
{
    /**
     * @param non-empty-string  $name
     * @param ?non-empty-string $dataset
     */
    private function definition(string $name, ?string $dataset = null, CrucibleAttribute ...$attributes): TestDefinition
    {
        return new TestDefinition(
            new TestId('tests/AlphaTest.php', $name, $dataset),
            static fn(array $values): mixed => null,
            MetadataCollection::from(...$attributes),
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private function names(TestSelection $selection, TestGroup ...$groups): array
    {
        $names = [];

        foreach ($selection->apply(array_values($groups)) as $group) {
            foreach ($group->tests as $test) {
                $names[] = $test->id->name;
            }
        }

        return $names;
    }

    public function testPlainPatternIsACaseInsensitiveSubstringMatch(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testAddsNumbers'),
            $this->definition('testSubtracts'),
        ]);

        $selection = new TestSelection(new NameFilter('addsnumbers'));

        $this->assertSame(['testAddsNumbers'], $this->names($selection, $group));
    }

    public function testPatternMatchesClassQualifiedNamesAndWildcards(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testAdds'),
            $this->definition('testSubtracts'),
        ]);

        $this->assertSame(['testAdds'], $this->names(new TestSelection(new NameFilter('AlphaTest::testAdds')), $group));
        $this->assertSame(['testAdds', 'testSubtracts'], $this->names(new TestSelection(new NameFilter('AlphaTest::test*s')), $group));
        $this->assertSame([], $this->names(new TestSelection(new NameFilter('BetaTest::')), $group));
    }

    public function testPatternReachesDatasetSpellings(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testRows', 'first'),
            $this->definition('testRows', 'second'),
            $this->definition('testRows', '0'),
        ]);

        $named   = new TestSelection(new NameFilter('testRows with data set "first"'));
        $indexed = new TestSelection(new NameFilter('testRows with data set #0'));

        $this->assertCount(1, $this->names($named, $group));
        $this->assertCount(1, $this->names($indexed, $group));
    }

    public function testRegularExpressionPatternsPassThroughVerbatim(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testAdds'),
            $this->definition('testSubtracts'),
        ]);

        $selection = new TestSelection(new NameFilter('/::test(Adds|Never)$/'));

        $this->assertSame(['testAdds'], $this->names($selection, $group));
    }

    public function testGroupSelectionIncludesMembersAndExclusionWins(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testFast', null, new Group('fast')),
            $this->definition('testSlow', null, new Group('slow')),
            $this->definition('testBoth', null, new Group('fast'), new Group('slow')),
            $this->definition('testUngrouped'),
        ]);

        $this->assertSame(
            ['testFast', 'testBoth'],
            $this->names(new TestSelection(groups: ['fast']), $group),
        );
        $this->assertSame(
            ['testFast', 'testUngrouped'],
            $this->names(new TestSelection(excludeGroups: ['slow']), $group),
        );
        // Exclusion beats inclusion for tests in both groups.
        $this->assertSame(
            ['testFast'],
            $this->names(new TestSelection(groups: ['fast'], excludeGroups: ['slow']), $group),
        );
    }

    public function testEmptiedGroupsDisappearFromTheSelection(): void
    {
        $alpha = new TestGroup('App\AlphaTest', [$this->definition('testAdds')]);

        $this->assertSame([], array_map(
            static fn(TestGroup $group): string => $group->name,
            (new TestSelection(new NameFilter('nothingMatchesThis')))->apply([$alpha]),
        ));
    }

    public function testTodoListingSelectsOnlyMarkedTestsWhateverTheStatus(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testOpen', null, new Todo()),
            $this->definition('testLanded', null, new Todo(TodoStatus::Done)),
            $this->definition('testOrdinary'),
        ]);

        $this->assertSame(
            ['testOpen', 'testLanded'],
            $this->names(new TestSelection(todos: true), $group),
        );
    }

    public function testAssigneeAndIssueNarrowByTheMarkerFieldsAndImplyMembership(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testMine', null, new Todo(assignee: 'luciano', issue: 31)),
            $this->definition('testTheirs', null, new Todo(assignee: 'ana', issue: 'PR-7')),
            $this->definition('testUnassigned', null, new Todo()),
            $this->definition('testOrdinary'),
        ]);

        // Each flag stands alone (the observed pest behavior): a
        // non-todo test never matches, --todos itself is implied.
        $this->assertSame(
            ['testMine'],
            $this->names(new TestSelection(assignee: 'luciano'), $group),
        );
        $this->assertSame(
            ['testTheirs'],
            $this->names(new TestSelection(issue: 'PR-7'), $group),
        );
        // Integer issues match their string spelling from the CLI.
        $this->assertSame(
            ['testMine'],
            $this->names(new TestSelection(todos: true, issue: '31'), $group),
        );
        $this->assertSame(
            [],
            $this->names(new TestSelection(assignee: 'nobody'), $group),
        );
    }

    public function testNoWipDropsWorkInProgressButKeepsTodoDoneAndOrdinary(): void
    {
        $group = new TestGroup('App\AlphaTest', [
            $this->definition('testUnderway', null, new Todo(TodoStatus::Wip)),
            $this->definition('testPlanned', null, new Todo()),
            $this->definition('testLanded', null, new Todo(TodoStatus::Done)),
            $this->definition('testOrdinary'),
        ]);

        // wip runs by default (Pest parity, D-074); --no-wip is a custom
        // exclusion that drops only wip — todos, done, and ordinary
        // tests are untouched.
        $this->assertSame(
            ['testPlanned', 'testLanded', 'testOrdinary'],
            $this->names(new TestSelection(noWip: true), $group),
        );
    }

    public function testExcludeFilterIsTheNegatedFilter(): void
    {
        $group = new TestGroup('AlphaTest', [
            $this->definition('testKeep'),
            $this->definition('testDrop'),
        ]);

        $this->assertSame(
            ['testKeep'],
            $this->names(new TestSelection(excludeFilter: new NameFilter('testDrop')), $group),
        );

        // Exclusion wins: a name matched by both is not run.
        $this->assertSame(
            [],
            $this->names(new TestSelection(new NameFilter('testKeep'), excludeFilter: new NameFilter('testKeep')), $group),
        );
    }

    public function testCoversAndUsesSelectOnDeclaredIntent(): void
    {
        // Real class names: the attributes take class-string, so a made-up
        // namespace would only be testing the fixture.
        $group = new TestGroup('AlphaTest', [
            $this->definition('testCoversFilter', null, new CoversClass(NameFilter::class)),
            $this->definition('testCoversMethod', null, new CoversMethod(NameFilter::class, 'matches')),
            $this->definition('testUsesGroup', null, new UsesClass(TestGroup::class)),
            $this->definition('testDeclaresNothing'),
        ]);

        // A class name matches both the class attribute and a method
        // attribute on that class; the class::method form matches only the
        // method one.
        $this->assertSame(
            ['testCoversFilter', 'testCoversMethod'],
            $this->names(new TestSelection(covers: [NameFilter::class]), $group),
        );

        $this->assertSame(
            ['testCoversMethod'],
            $this->names(new TestSelection(covers: [NameFilter::class . '::matches']), $group),
        );

        $this->assertSame(
            ['testUsesGroup'],
            $this->names(new TestSelection(uses: [TestGroup::class]), $group),
        );

        $this->assertSame([], $this->names(new TestSelection(covers: [TestSelection::class]), $group));
    }

    public function testRequiresPhpExtensionSelectsOnTheDeclaredRequirement(): void
    {
        $group = new TestGroup('AlphaTest', [
            $this->definition('testNeedsJson', null, new RequiresPhpExtension('json')),
            $this->definition('testNeedsNothing'),
        ]);

        $this->assertSame(
            ['testNeedsJson'],
            $this->names(new TestSelection(extensions: ['json']), $group),
        );
    }

    public function testIdAndFileSelectionTellNoFilterFromAFilterThatMatchedNothing(): void
    {
        $group = new TestGroup('AlphaTest', [
            $this->definition('testFirst'),
            $this->definition('testSecond'),
        ]);

        // null is "no id filter"; an empty list would be "an id filter
        // nothing matched", and the two must not collapse.
        $this->assertSame(['testFirst', 'testSecond'], $this->names(new TestSelection(), $group));
        $this->assertSame([], $this->names(new TestSelection(testIds: []), $group));

        $this->assertSame(
            ['testSecond'],
            $this->names(new TestSelection(testIds: ['tests/AlphaTest.php::testSecond']), $group),
        );

        $this->assertSame(['testFirst', 'testSecond'], $this->names(new TestSelection(files: ['tests/AlphaTest.php']), $group));
        $this->assertSame([], $this->names(new TestSelection(files: ['tests/OtherTest.php']), $group));
    }
}
