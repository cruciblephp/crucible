<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Event;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\EventTextWriter;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Test\TestId;

use function fopen;
use function fseek;
use function implode;
use function stream_get_contents;

/**
 * --log-events-text and --log-events-verbose-text are the NDJSON stream
 * rendered for a person: same envelopes, same sequence, no decisions of
 * their own beyond whether the payload is elided.
 */
#[CoversClass(EventTextWriter::class)]
final class EventTextWriterTest extends TestCase
{
    public function testTerseFormIsSequenceAndNameOnly(): void
    {
        $stream = $this->emitMiniatureRun(verbose: false);

        $this->assertSame(implode("\n", [
            '1 run:start',
            '2 test:start',
            '3 test:finish',
            '4 run:finish',
        ]) . "\n", $stream);
    }

    public function testVerboseFormKeepsThePayloadFields(): void
    {
        $stream = $this->emitMiniatureRun(verbose: true);

        $this->assertSame(implode("\n", [
            '1 run:start crucible=0.1.0 php=8.5.7',
            '2 test:start id=tests/Unit/SumTest.php::testAddsIntegers',
            '3 test:finish id=tests/Unit/SumTest.php::testAddsIntegers outcome=pass duration=0.005',
            '4 run:finish duration=1 counts=[6]',
        ]) . "\n", $stream);
    }

    public function testTelemetryPrefixesEachLineWithElapsedTimeAndPeakMemory(): void
    {
        $stream = $this->emitMiniatureRun(verbose: false, telemetry: true);

        // [since start / since previous] [N bytes] before the sequence,
        // which is the spec's shape.
        self::assertMatchesRegularExpression(
            '/^\[\d{2}:\d{2}:\d{2}\.\d{9} \/ \d{2}:\d{2}:\d{2}\.\d{9}\] \[\d+ bytes\] 1 run:start$/m',
            $stream,
        );

        // Still one line per event, and still in sequence order.
        self::assertMatchesRegularExpression('/\] 4 run:finish$/m', $stream);
    }

    private function emitMiniatureRun(bool $verbose, bool $telemetry = false): string
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            self::fail('Cannot open memory stream.');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe(new EventTextWriter($stream, $verbose, $telemetry));

        $test = new TestId('tests/Unit/SumTest.php', 'testAddsIntegers');

        $emitter->emit(new RunStarted('0.1.0', '8.5.7'));
        $emitter->emit(new TestStarted($test));
        $emitter->emit(new TestFinished($test, Outcome::Passed, 0.005));
        $emitter->emit(new RunFinished(new RunSummary(passed: 1), 1.0));

        fseek($stream, 0);
        $contents = stream_get_contents($stream);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
