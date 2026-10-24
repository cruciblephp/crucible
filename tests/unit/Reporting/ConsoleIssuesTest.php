<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Reporting;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\ConsoleReporter;
use LucianoPereira\Crucible\Test\TestId;

use function fopen;
use function rewind;
use function stream_get_contents;
use function strpos;

/**
 * The issues a test triggered, in the progress line and listed before the
 * tally (D-154). Each expectation is PHPUnit 13.4.3's answer.
 */
#[CoversClass(ConsoleReporter::class)]
final class ConsoleIssuesTest extends TestCase
{
    public function testAPassingTestShowsItsIssueAWarningOverADeprecationOverANotice(): void
    {
        $output = $this->run([
            [Outcome::Passed, [IssueKind::Deprecation, IssueKind::Warning]],
            [Outcome::Passed, [IssueKind::Notice, IssueKind::Deprecation]],
            [Outcome::Passed, [IssueKind::Notice]],
            [Outcome::Failed, [IssueKind::Warning]],
            [Outcome::Passed, []],
        ]);

        $this->assertStringStartsWith('WDNF.', $output);
    }

    public function testAListedKindNamesEveryTestThatTriggeredItBeforeTheTally(): void
    {
        $output = $this->run([
            [Outcome::Passed, [IssueKind::Warning]],
            [Outcome::Passed, [IssueKind::Warning, IssueKind::Notice]],
        ], 'warning');

        $this->assertStringContainsString(
            "\n1 warning(s):\n  src/Thing.php:9  Undefined array key \"missing\"\n    triggered by tests/unit/IssuesTest.php::test0\n    triggered by tests/unit/IssuesTest.php::test1\n",
            $output,
        );
        $this->assertStringNotContainsString('notice(s)', $output);

        $listed = strpos($output, 'warning(s)');
        $tally  = strpos($output, 'Tests:');
        self::assertIsInt($listed);
        self::assertIsInt($tally);
        $this->assertLessThan($tally, $listed);
    }

    public function testNothingIsListedUnasked(): void
    {
        $this->assertStringNotContainsString('warning(s)', $this->run([[Outcome::Passed, [IssueKind::Warning]]]));
    }

    /**
     * @param list<array{Outcome, list<IssueKind>}> $tests
     */
    private function run(array $tests, string $issues = ''): string
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            self::fail('Cannot open the in-memory stream.');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-10-21T12:00:00+00:00')));
        $emitter->subscribe(new ConsoleReporter($stream, issues: $issues));
        $emitter->emit(new RunStarted());

        foreach ($tests as $index => [$outcome, $kinds]) {
            $triggered = [];

            foreach ($kinds as $kind) {
                $triggered[] = $kind === IssueKind::Warning
                    ? new Issue($kind, 'Undefined array key "missing"', 'src/Thing.php', 9)
                    : new Issue($kind, $kind->value . ' message', 'src/Thing.php', 20);
            }

            $emitter->emit(new TestFinished(new TestId('tests/unit/IssuesTest.php', 'test' . $index), $outcome, 0.001, issues: $triggered));
        }

        $emitter->emit(new RunFinished(new RunSummary(passed: 1), 0.01));

        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
