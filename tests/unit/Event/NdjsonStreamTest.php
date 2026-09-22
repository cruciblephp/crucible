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
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Frame;
use LucianoPereira\Crucible\Event\NdjsonWriter;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\OutputChannel;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\SuiteFinished;
use LucianoPereira\Crucible\Event\SuiteStarted;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestOutputWritten;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Test\TestId;

use function explode;
use function fopen;
use function fseek;
use function implode;
use function json_decode;
use function stream_get_contents;

use const JSON_THROW_ON_ERROR;

/**
 * Golden test: a complete miniature run must serialize to a
 * byte-exact NDJSON stream. This pins the schema — any change to the
 * envelope or a payload shows up here first and must bump
 * Envelope::SCHEMA_VERSION when it breaks compatibility.
 */
#[CoversClass(NdjsonWriter::class)]
#[CoversClass(Emitter::class)]
final class NdjsonStreamTest extends TestCase
{
    public function testMiniatureRunProducesTheExactGoldenStream(): void
    {
        $stream  = $this->openMemoryStream();
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00.123456+00:00')));
        $emitter->subscribe(new NdjsonWriter($stream));

        $passing = new TestId('tests/Unit/SumTest.php', 'testAddsIntegers');
        $failing = new TestId('tests/Unit/SumTest.php', 'testAddsFloats', 'row 1');

        $emitter->emit(new RunStarted('0.1.0', '8.5.7'));
        $emitter->emit(new SuiteStarted('unit'));
        $emitter->emit(new TestStarted($passing));
        $emitter->emit(new TestOutputWritten($passing, OutputChannel::Stdout, "debug\n"));
        $emitter->emit(new TestFinished($passing, Outcome::Passed, 0.005));
        $emitter->emit(new TestStarted($failing));
        $emitter->emit(new TestFinished(
            $failing,
            Outcome::Failed,
            0.25,
            new Failure(
                'Failed asserting that two floats are identical.',
                'AssertionFailedError',
                [new Frame('tests/Unit/SumTest.php', 42, 'testAddsFloats')],
                '0.3',
                '0.30000000000000004',
            ),
        ));
        $emitter->emit(new SuiteFinished('unit', 0.5));
        $emitter->emit(new RunFinished(new RunSummary(passed: 1, failed: 1), 1.0));

        $expected = implode("\n", [
            '{"ver":1,"event":"run:start","ts":"2026-07-14T12:00:00.123456+00:00","seq":1,"crucible":"0.1.0","php":"8.5.7"}',
            '{"ver":1,"event":"suite:start","ts":"2026-07-14T12:00:00.123456+00:00","seq":2,"suite":"unit"}',
            '{"ver":1,"event":"test:start","ts":"2026-07-14T12:00:00.123456+00:00","seq":3,"id":"tests/Unit/SumTest.php::testAddsIntegers"}',
            '{"ver":1,"event":"test:output","ts":"2026-07-14T12:00:00.123456+00:00","seq":4,"id":"tests/Unit/SumTest.php::testAddsIntegers","channel":"stdout","chunk":"debug\n"}',
            '{"ver":1,"event":"test:finish","ts":"2026-07-14T12:00:00.123456+00:00","seq":5,"id":"tests/Unit/SumTest.php::testAddsIntegers","outcome":"pass","duration":0.005}',
            '{"ver":1,"event":"test:start","ts":"2026-07-14T12:00:00.123456+00:00","seq":6,"id":"tests/Unit/SumTest.php::testAddsFloats#row 1"}',
            '{"ver":1,"event":"test:finish","ts":"2026-07-14T12:00:00.123456+00:00","seq":7,"id":"tests/Unit/SumTest.php::testAddsFloats#row 1","outcome":"fail","duration":0.25,"error":{"message":"Failed asserting that two floats are identical.","class":"AssertionFailedError","trace":[{"file":"tests/Unit/SumTest.php","line":42,"function":"testAddsFloats"}],"diff":{"expected":"0.3","actual":"0.30000000000000004"}}}',
            '{"ver":1,"event":"suite:finish","ts":"2026-07-14T12:00:00.123456+00:00","seq":8,"suite":"unit","duration":0.5}',
            '{"ver":1,"event":"run:finish","ts":"2026-07-14T12:00:00.123456+00:00","seq":9,"duration":1.0,"counts":{"pass":1,"fail":1,"error":0,"skip":0,"incomplete":0,"risky":0}}',
        ]) . "\n";

        $this->assertSame($expected, $this->contents($stream));
    }

    /**
     * An incomplete test carries where it stopped; a skipped one does
     * not, and the difference is deliberate.
     *
     * "Not finished yet" is a note about a line somebody wrote, and a
     * reader wants to open it. "Redis is not here" is a statement about
     * the environment and points nowhere. Both keep `reason`; only the
     * first gets an `error`, and a consumer classifies on `outcome`
     * rather than on whether that key is present.
     */
    public function testIncompleteCarriesItsOriginAndSkippedDoesNot(): void
    {
        $stream  = $this->openMemoryStream();
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00.123456+00:00')));
        $emitter->subscribe(new NdjsonWriter($stream));

        $unfinished = new TestId('tests/Unit/SumTest.php', 'testNotYet');
        $ungated    = new TestId('tests/Unit/SumTest.php', 'testNeedsRedis');

        $emitter->emit(new TestFinished(
            $unfinished,
            Outcome::Incomplete,
            0.001,
            new Failure('later', 'IncompleteTestError', [new Frame('tests/Unit/SumTest.php', 12, 'markTestIncomplete')]),
            reason: 'later',
        ));
        $emitter->emit(new TestFinished($ungated, Outcome::Skipped, 0.001, reason: 'Redis is not available.'));

        $lines = explode("\n", $this->contents($stream));

        /** @var array{outcome: string, reason: string, error: array{class: string, trace: list<array{line: int}>}} $incomplete */
        $incomplete = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);

        /** @var array{outcome: string, reason: string} $skipped */
        $skipped = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('incomplete', $incomplete['outcome']);
        self::assertSame('later', $incomplete['reason']);
        self::assertSame('IncompleteTestError', $incomplete['error']['class']);
        self::assertSame(12, $incomplete['error']['trace'][0]['line']);

        self::assertSame('skip', $skipped['outcome']);
        self::assertSame('Redis is not available.', $skipped['reason']);
        $this->assertArrayNotHasKey('error', $skipped);
    }

    public function testOutputChunkConcatenationIsExact(): void
    {
        $stream  = $this->openMemoryStream();
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe(new NdjsonWriter($stream));

        $test = new TestId('tests/T.php', 't');

        $emitter->emit(new TestOutputWritten($test, OutputChannel::Stdout, 'hel'));
        $emitter->emit(new TestOutputWritten($test, OutputChannel::Stdout, "lo, wörld / \"quoted\""));
        $emitter->emit(new TestOutputWritten($test, OutputChannel::Stdout, "\n"));

        $reassembled = '';

        foreach (explode("\n", $this->contents($stream)) as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array{chunk: string} $decoded */
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $reassembled .= $decoded['chunk'];
        }

        $this->assertSame("hello, wörld / \"quoted\"\n", $reassembled);
    }

    public function testInvalidUtf8InChunksIsSubstitutedNotFatal(): void
    {
        $stream  = $this->openMemoryStream();
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe(new NdjsonWriter($stream));

        $emitter->emit(new TestOutputWritten(new TestId('tests/T.php', 't'), OutputChannel::Stderr, "\xB1\x31"));

        $this->assertStringContainsString('"chunk":"�1"', $this->contents($stream));
    }

    /**
     * @return resource
     */
    private function openMemoryStream()
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            self::fail('Cannot open memory stream.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        fseek($stream, 0);
        $contents = stream_get_contents($stream);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
