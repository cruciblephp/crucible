<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\PostRunReport;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Php;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\IssueLog;
use LucianoPereira\Crucible\Runner\OutcomeLog;
use LucianoPereira\Crucible\Test\TestId;

use function array_fill;
use function is_string;
use function ob_get_clean;
use function ob_start;

/**
 * The exit-code policy, which is the part of the CLI a pipeline actually
 * reads. The scoped deprecation switches are judged here because the
 * scope is attribution the collector made, not something the summary
 * tally can express.
 */
#[CoversClass(PostRunReport::class)]
final class PostRunReportTest extends TestCase
{
    /**
     * @param list<string> $argv
     */
    private function options(array $argv): CliOptions
    {
        $options = CliOptions::fromArgv(['crucible', ...$argv]);

        if (is_string($options)) {
            self::fail('Unexpected parse error: ' . $options);
        }

        return $options;
    }

    private function configuration(): Configuration
    {
        return new Configuration([], new Source(), new Php());
    }

    private function logWith(DeprecationScope $scope): IssueLog
    {
        $log = new IssueLog();

        $log->handle(new Envelope(
            1,
            new DateTimeImmutable('2026-07-14T12:00:00+00:00'),
            new TestFinished(
                new TestId('tests/ScopeFixture.php', 'deprecates'),
                Outcome::Passed,
                0.0,
                null,
                1,
                null,
                [new Issue(IssueKind::Deprecation, 'old api', '/app/src/Thing.php', 12, $scope)],
            ),
        ));

        return $log;
    }

    public function testAScopedDeprecationPolicyJudgesOnlyItsOwnScope(): void
    {
        $report  = new PostRunReport();
        $summary = new RunSummary(passed: 1, deprecations: 1);

        $self = $this->logWith(DeprecationScope::Self_);

        $this->assertSame(1, $report->exitCode($summary, $this->configuration(), $this->options(['--fail-on-self-deprecation']), issueLog: $self));
        $this->assertSame(0, $report->exitCode($summary, $this->configuration(), $this->options(['--fail-on-direct-deprecation']), issueLog: $self));
        $this->assertSame(0, $report->exitCode($summary, $this->configuration(), $this->options(['--fail-on-indirect-deprecation']), issueLog: $self));

        $direct = $this->logWith(DeprecationScope::Direct);

        $this->assertSame(1, $report->exitCode($summary, $this->configuration(), $this->options(['--fail-on-direct-deprecation']), issueLog: $direct));
        $this->assertSame(0, $report->exitCode($summary, $this->configuration(), $this->options(['--fail-on-self-deprecation']), issueLog: $direct));
    }

    public function testTheNegationBeatsAConfigurationThatTurnedThePolicyOn(): void
    {
        $report  = new PostRunReport();
        $summary = new RunSummary(passed: 1, deprecations: 1);
        $log     = $this->logWith(DeprecationScope::Self_);

        $configured = new Configuration([], new Source(), new Php(), failOnSelfDeprecation: true);

        $this->assertSame(1, $report->exitCode($summary, $configured, $this->options([]), issueLog: $log));
        $this->assertSame(0, $report->exitCode($summary, $configured, $this->options(['--do-not-fail-on-self-deprecation']), issueLog: $log));
    }

    public function testFailOnAllIssuesCoversEveryPolicyAndStillYieldsToANegation(): void
    {
        $report = new PostRunReport();

        $this->assertSame(1, $report->exitCode(new RunSummary(skipped: 1), $this->configuration(), $this->options(['--fail-on-all-issues'])));
        $this->assertSame(0, $report->exitCode(
            new RunSummary(skipped: 1),
            $this->configuration(),
            $this->options(['--fail-on-all-issues', '--do-not-fail-on-skipped']),
        ));
    }

    public function testAnEmptySuiteFailsByDefaultAndTheNegationTurnsThatOff(): void
    {
        $report = new PostRunReport();

        $this->assertSame(1, $report->exitCode(new RunSummary(), $this->configuration(), $this->options([])));
        $this->assertSame(0, $report->exitCode(
            new RunSummary(),
            $this->configuration(),
            $this->options(['--do-not-fail-on-empty-test-suite']),
        ));
    }

    public function testTheDisplayFamilyNamesWhatTheTallyCounted(): void
    {
        $log = $this->logWith(DeprecationScope::Direct);

        $outcomes = new OutcomeLog();
        $outcomes->handle(new Envelope(
            2,
            new DateTimeImmutable('2026-07-14T12:00:01+00:00'),
            new TestFinished(new TestId('tests/ScopeFixture.php', 'skips'), Outcome::Skipped, 0.0, null, 1, 'needs a database'),
        ));

        ob_start();
        (new PostRunReport())->printIssueDisplays($this->options(['--display-all-issues']), $log, $outcomes);
        $printed = (string) ob_get_clean();

        $this->assertStringContainsString('1 deprecation(s):', $printed);
        $this->assertStringContainsString('/app/src/Thing.php:12  old api [direct]', $printed);
        $this->assertStringContainsString('1 skipped test(s):', $printed);
        $this->assertStringContainsString('tests/ScopeFixture.php::skips  needs a database', $printed);

        // A kind with nothing to show prints no heading at all.
        $this->assertStringNotContainsString('notice(s):', $printed);
        $this->assertStringNotContainsString('incomplete test(s):', $printed);
    }

    public function testNoDisplaySwitchPrintsNothing(): void
    {
        ob_start();
        (new PostRunReport())->printIssueDisplays($this->options([]), $this->logWith(DeprecationScope::Self_), new OutcomeLog());

        $this->assertSame('', (string) ob_get_clean());
    }

    public function testExactlyTheMinimumPasses(): void
    {
        // 9 of 10 executable lines, --min=90. Off-by-one here is the
        // difference between a gate that works and one nobody trusts.
        self::assertNull((new PostRunReport())->coverageFloor($this->coverage(9, 1), 90.0));
    }

    public function testAShortfallNamesTheNumbers(): void
    {
        $breach = (new PostRunReport())->coverageFloor($this->coverage(8, 2), 90.0);

        self::assertIsString($breach);
        self::assertStringContainsString('80.00%', $breach);
        self::assertStringContainsString('10 executable lines', $breach);
        self::assertStringContainsString('90%', $breach);
    }

    public function testNoMinimumIsNoGate(): void
    {
        self::assertNull((new PostRunReport())->coverageFloor($this->coverage(0, 10), null));
    }

    public function testDeadLinesDoNotDiluteTheScore(): void
    {
        // 1 covered, 1 missed, 8 dead: 50%, not 10%.
        $data = new CoverageData(lines: ['/app/src/Thing.php' => [1 => 1, 2 => -1] + array_fill(3, 8, -2)]);

        $breach = (new PostRunReport())->coverageFloor($data, 60.0);

        self::assertIsString($breach);
        self::assertStringContainsString('50.00%', $breach);
        self::assertStringContainsString('2 executable lines', $breach);
    }

    public function testTheGateJudgesThePrintedPercentage(): void
    {
        // 22499 of 25000 is 89.996%, which the report prints as 90.00%.
        // Failing that run against --min=90 would argue with the user
        // about a decimal place the report never shows them.
        self::assertNull((new PostRunReport())->coverageFloor($this->coverage(22499, 2501), 90.0));
    }

    public function testAFloorOfZeroCannotBeBreached(): void
    {
        // --min 0 demands nothing, so nothing can fail it. Without this
        // the gate is not monotonic: 0 passes on a measured scope and
        // fails on an empty one, which no caller would predict.
        self::assertNull((new PostRunReport())->coverageFloor(new CoverageData(), 0.0));
        self::assertNull((new PostRunReport())->coverageFloor($this->coverage(0, 10), 0.0));
    }

    public function testAnEmptyScopeIsABreach(): void
    {
        // Nothing measured must never report success: that is the exact
        // failure the gate exists to catch.
        // Only against a floor that DEMANDS something: an unmeetable
        // ask, not merely an unmeasured one.
        $breach = (new PostRunReport())->coverageFloor(new CoverageData(), 1.0);

        self::assertIsString($breach);
        self::assertStringContainsString('no executable lines', $breach);
    }

    private function coverage(int $covered, int $missed): CoverageData
    {
        $lines = [];
        $line  = 1;

        for ($i = 0; $i < $covered; $i++) {
            $lines[$line++] = 1;
        }

        for ($i = 0; $i < $missed; $i++) {
            $lines[$line++] = -1;
        }

        return new CoverageData(lines: ['/app/src/Thing.php' => $lines]);
    }
}
