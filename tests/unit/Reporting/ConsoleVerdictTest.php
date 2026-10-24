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
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\ConsoleReporter;
use LucianoPereira\Crucible\Test\TestId;

use function array_key_last;
use function explode;
use function fopen;
use function rewind;
use function rtrim;
use function stream_get_contents;

/**
 * The console's last line is the run's verdict, whatever happened (D-153).
 * Each expectation is PHPUnit 13.4.3's last word on the same run.
 */
#[CoversClass(ConsoleReporter::class)]
final class ConsoleVerdictTest extends TestCase
{
    /**
     * @return iterable<string, array{list<array{Outcome, bool}>, RunSummary, string}>
     */
    public static function runs(): iterable
    {
        yield 'all passed' => [[[Outcome::Passed, false]], new RunSummary(passed: 1), 'OK'];
        yield 'an error' => [[[Outcome::Passed, false], [Outcome::Errored, false]], new RunSummary(passed: 1, errored: 1), 'ERRORS!'];
        yield 'a failure' => [[[Outcome::Failed, false]], new RunSummary(failed: 1), 'FAILURES!'];
        yield 'an error and a failure' => [[[Outcome::Failed, false], [Outcome::Errored, false]], new RunSummary(failed: 1, errored: 1), 'ERRORS!'];
        yield 'a failure and a skip' => [[[Outcome::Failed, false], [Outcome::Skipped, false]], new RunSummary(failed: 1, skipped: 1), 'FAILURES!'];
        yield 'only a skip' => [[[Outcome::Passed, false], [Outcome::Skipped, false]], new RunSummary(passed: 1, skipped: 1), 'OK, but some tests were skipped!'];
        yield 'an incomplete' => [[[Outcome::Incomplete, false]], new RunSummary(incomplete: 1), 'OK, but there were issues!'];
        yield 'a skip and a risky' => [[[Outcome::Skipped, false], [Outcome::Risky, false]], new RunSummary(skipped: 1, risky: 1), 'OK, but there were issues!'];
        yield 'a warning' => [[[Outcome::Passed, false]], new RunSummary(passed: 1, warnings: 1), 'OK, but there were issues!'];
        yield 'a quarantined failure' => [[[Outcome::Passed, false], [Outcome::Failed, true]], new RunSummary(passed: 1, failed: 1), 'OK, but there were issues!'];
    }

    /**
     * @param list<array{Outcome, bool}> $outcomes each test's outcome, and whether it is quarantined
     */
    #[DataProvider('runs')]
    public function testTheLastLineIsTheVerdict(array $outcomes, RunSummary $summary, string $verdict): void
    {
        $lines = explode("\n", rtrim($this->run($outcomes, $summary)));

        $this->assertSame($verdict, $lines[array_key_last($lines)]);
    }

    public function testNoPassedTreeWhenNothingPassed(): void
    {
        $this->assertStringNotContainsString('Passed', explode('Tests:', $this->run([[Outcome::Failed, false]], new RunSummary(failed: 1)))[0]);
    }

    /**
     * @param list<array{Outcome, bool}> $outcomes
     */
    private function run(array $outcomes, RunSummary $summary): string
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            self::fail('Cannot open the in-memory stream.');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-10-21T12:00:00+00:00')));
        $emitter->subscribe(new ConsoleReporter($stream));
        $emitter->emit(new RunStarted());

        foreach ($outcomes as $index => [$outcome, $quarantined]) {
            $emitter->emit(new TestFinished(new TestId('tests/unit/VerdictTest.php', 'test' . $index), $outcome, 0.001, quarantined: $quarantined));
        }

        $emitter->emit(new RunFinished($summary, 0.01));

        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
