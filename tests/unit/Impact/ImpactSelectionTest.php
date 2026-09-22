<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ChangedFiles;
use LucianoPereira\Crucible\Impact\DependencyGraph;
use LucianoPereira\Crucible\Impact\ImpactResult;
use LucianoPereira\Crucible\Impact\ImpactRule;
use LucianoPereira\Crucible\Impact\ImpactSelection;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_map;
use function dirname;
use function implode;

#[CoversClass(ImpactSelection::class)]
#[CoversClass(ImpactResult::class)]
#[CoversClass(ImpactRule::class)]
final class ImpactSelectionTest extends TestCase
{
    private const string FIXTURES = 'tests/unit/Impact/Fixtures';

    /**
     * @param non-empty-string $file project-relative
     */
    private function group(string $file): TestGroup
    {
        return new TestGroup($file, [
            new TestDefinition(
                new TestId($file, 'stands in'),
                static fn(array $values): mixed => null,
                MetadataCollection::from(),
            ),
        ]);
    }

    /**
     * @param non-empty-string       $file   project-relative
     * @param non-empty-string       ...$in  the groups every test in it carries
     */
    private function grouped(string $file, string ...$in): TestGroup
    {
        return new TestGroup($file, [
            new TestDefinition(
                new TestId($file, 'stands in'),
                static fn(array $values): mixed => null,
                MetadataCollection::from(...array_map(
                    static fn(string $name): Group => new Group($name),
                    $in,
                )),
            ),
        ]);
    }

    /**
     * @param list<non-empty-string> $environmentFiles
     * @param list<ImpactRule>       $rules
     */
    private function selection(array $environmentFiles = [], array $rules = []): ImpactSelection
    {
        $root = dirname(__DIR__, 3);

        return new ImpactSelection(new DependencyGraph($root), $root, $environmentFiles, $rules);
    }

    /**
     * @return list<TestGroup>
     */
    private function groups(): array
    {
        return [
            $this->group(self::FIXTURES . '/Gamma.php'),
            $this->group(self::FIXTURES . '/Delta.php'),
        ];
    }

    public function testAChangeSelectsTheGroupsWhoseClosureReachesIt(): void
    {
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/' . self::FIXTURES . '/Alpha.php']);
        $result  = $this->selection()->select($this->groups(), $changed);

        self::assertNotNull($result->groups, 'Expected a narrowed selection.');
        $selected = $result->groups;

        self::assertSame(
            [self::FIXTURES . '/Gamma.php'],
            array_map(static fn(TestGroup $group): string => $group->name, $selected),
        );
    }

    public function testAChangedTestFileSelectsItself(): void
    {
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/' . self::FIXTURES . '/Delta.php']);
        $result  = $this->selection()->select($this->groups(), $changed);

        self::assertNotNull($result->groups, 'Expected a narrowed selection.');
        $selected = $result->groups;

        self::assertSame(
            [self::FIXTURES . '/Delta.php'],
            array_map(static fn(TestGroup $group): string => $group->name, $selected),
        );
    }

    public function testAnUntouchedGraphSelectsNothing(): void
    {
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/src/Version.php']);
        $result  = $this->selection()->select($this->groups(), $changed);

        self::assertSame([], $result->groups);
    }

    public function testAChangeAVitestSuiteAnswersForIsNotCalledUnaccountedFor(): void
    {
        // Both tiers print. VitestImpact says it is running related tests
        // for this file; the PHP tier must not say the opposite above it.
        $root    = dirname(__DIR__, 3);
        $changed = new ChangedFiles([$root . '/inertia-oracle/entry.mjs']);

        $selection = new ImpactSelection(
            new DependencyGraph($root),
            $root,
            [],
            [],
            [$root . '/inertia-oracle'],
        );

        $notes = implode("\n", $selection->select($this->groups(), $changed)->notes);

        self::assertStringContainsString('answered by another tier', $notes);
        self::assertStringNotContainsString('matched by no impact rule', $notes);
    }

    public function testAChangeNoTestReachesSaysSoRatherThanGoingQuiet(): void
    {
        // A PHP change nothing covers narrows the run to nothing. That is
        // the same silence a missing impact rule causes, and it wants the
        // opposite fix — a test, not a rule — so it is reported apart.
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/src/Version.php']);
        $result  = $this->selection()->select($this->groups(), $changed);

        $notes = implode("\n", $result->notes);

        self::assertStringContainsString('reached by no test', $notes);
        self::assertStringNotContainsString('matched by no impact rule', $notes, 'A PHP file is not an undeclared asset.');
    }

    public function testDeletionsWidenToTheFullSuite(): void
    {
        $result = $this->selection()->select($this->groups(), new ChangedFiles([], ['src/Gone.php']));

        self::assertNull($result->groups);
        self::assertNotSame([], $result->notes);
    }

    public function testComposerChangesWidenToTheFullSuite(): void
    {
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/composer.json']);

        self::assertNull($this->selection()->select($this->groups(), $changed)->groups);
    }

    public function testAJavascriptDependencyBumpWidensToo(): void
    {
        // Crucible runs JavaScript suites through the Vitest tier, so an
        // npm bump can break a test in this run. Widening on composer
        // and not on this would narrow past the tests that catch it.
        foreach (['package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml'] as $lockfile) {
            $changed = new ChangedFiles([dirname(__DIR__, 3) . '/' . $lockfile]);

            self::assertNull(
                $this->selection()->select($this->groups(), $changed)->groups,
                $lockfile . ' must widen the run.',
            );
        }
    }

    public function testEnvironmentFilesWidenToTheFullSuite(): void
    {
        $configuration = dirname(__DIR__, 3) . '/crucible.php';
        $changed       = new ChangedFiles([$configuration]);

        self::assertNull($this->selection([$configuration])->select($this->groups(), $changed)->groups);
    }

    public function testANonPhpChangeIsReportedInTheNotes(): void
    {
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/README.md']);
        $result  = $this->selection()->select($this->groups(), $changed);

        self::assertSame([], $result->groups);
        self::assertStringContainsString('matched by no impact rule', $result->notes[0]);
    }

    // -- declared impact rules (D-083) ------------------------------------

    public function testARuleSelectsItsGroupsForAnUnreachableFile(): void
    {
        $groups  = [$this->grouped('tests/Checkout.php', 'browser'), $this->group('tests/Unit.php')];
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/resources/js/Cart.vue']);
        $result  = $this->selection([], [new ImpactRule('resources/js', ['browser'])])
            ->select($groups, $changed);

        self::assertNotNull($result->groups, 'Expected a narrowed selection.');
        self::assertSame(
            ['tests/Checkout.php'],
            array_map(static fn(TestGroup $group): string => $group->name, $result->groups),
        );
    }

    public function testARuleMatchesTemplatesThatEndInPhp(): void
    {
        $groups  = [$this->grouped('tests/Mail.php', 'mail')];
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/resources/views/mail/welcome.blade.php']);
        $result  = $this->selection([], [new ImpactRule('resources/views', ['mail'])])
            ->select($groups, $changed);

        self::assertNotNull($result->groups, 'Expected a narrowed selection.');
        self::assertCount(1, $result->groups);
    }

    public function testRulesOnlyWidenNeverNarrow(): void
    {
        // The graph reaches Gamma; a rule naming a group nothing carries
        // must leave that answer exactly as it was.
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/' . self::FIXTURES . '/Alpha.php']);
        $result  = $this->selection([], [new ImpactRule('resources/js', ['browser'])])
            ->select($this->groups(), $changed);

        self::assertNotNull($result->groups, 'Expected a narrowed selection.');
        self::assertSame(
            [self::FIXTURES . '/Gamma.php'],
            array_map(static fn(TestGroup $group): string => $group->name, $result->groups),
        );
    }

    public function testAGroupSelectedByBothSourcesIsNotDuplicated(): void
    {
        $groups  = [$this->grouped(self::FIXTURES . '/Gamma.php', 'browser')];
        $changed = new ChangedFiles([
            dirname(__DIR__, 3) . '/' . self::FIXTURES . '/Alpha.php',
            dirname(__DIR__, 3) . '/resources/js/Cart.vue',
        ]);
        $result = $this->selection([], [new ImpactRule('resources/js', ['browser'])])
            ->select($groups, $changed);

        self::assertNotNull($result->groups, 'Expected a narrowed selection.');
        self::assertCount(1, $result->groups);
    }

    public function testARuleMatchIsNotCountedAsAnIgnoredChange(): void
    {
        $groups  = [$this->grouped('tests/Checkout.php', 'browser')];
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/resources/js/Cart.vue']);
        $result  = $this->selection([], [new ImpactRule('resources/js', ['browser'])])
            ->select($groups, $changed);

        $notes = implode("\n", $result->notes);

        self::assertStringContainsString('matched an impact rule', $notes);
        self::assertStringNotContainsString('matched by no impact rule', $notes);
    }

    public function testARuleForAGroupNoTestCarriesIsReported(): void
    {
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/resources/js/Cart.vue']);
        $result  = $this->selection([], [new ImpactRule('resources/js', ['typo'])])
            ->select($this->groups(), $changed);

        self::assertSame([], $result->groups);
        self::assertStringContainsString('carried by no test', implode("\n", $result->notes));
    }

    public function testAGlobPatternMatchesAcrossDirectories(): void
    {
        $groups  = [$this->grouped('tests/Checkout.php', 'browser')];
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/resources/js/pages/Cart.vue']);
        $result  = $this->selection([], [new ImpactRule('resources/**/*.vue', ['browser'])])
            ->select($groups, $changed);

        self::assertNotNull($result->groups, 'Expected a narrowed selection.');
        self::assertCount(1, $result->groups);
    }

    public function testAChangeMatchingNoRuleSelectsNothing(): void
    {
        $groups  = [$this->grouped('tests/Checkout.php', 'browser')];
        $changed = new ChangedFiles([dirname(__DIR__, 3) . '/docs/guide.md']);
        $result  = $this->selection([], [new ImpactRule('resources/js', ['browser'])])
            ->select($groups, $changed);

        self::assertSame([], $result->groups);
        self::assertStringContainsString('matched by no impact rule', implode("\n", $result->notes));
    }
}
