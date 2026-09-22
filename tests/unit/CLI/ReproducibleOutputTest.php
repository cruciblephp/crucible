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
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\PhpWriter;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\GenericReportWriter;

use function is_string;

/**
 * `--reproducible` exists so `cmp` on two reports answers the only
 * question CI asks: did BEHAVIOUR change? Without it the answer is
 * always yes, because a report restates when it ran and how long it
 * took, and those move even when nothing else did.
 *
 * Two kinds of volatility, two mechanisms — which is why this is not
 * one switch in one place. Timestamps already ride the injectable
 * Clock, so freezing it settles them everywhere at once. Durations
 * deliberately do NOT come from that clock (they are monotonic, taken
 * at the call site), so the report boundary has to normalise them.
 */
#[CoversClass(CliOptions::class)]
#[CoversClass(GenericReportWriter::class)]
#[CoversClass(PhpWriter::class)]
final class ReproducibleOutputTest extends TestCase
{
    public function testTheFlagIsOffUntilAskedFor(): void
    {
        self::assertFalse($this->parse([])->reproducible);
        self::assertTrue($this->parse(['--reproducible'])->reproducible);
    }

    public function testAFrozenClockSettlesTheTimestampAWriterWouldOtherwiseRead(): void
    {
        // PhpWriter used to construct its own DateTimeImmutable, which
        // no configuration could reach. Passing the instant in is what
        // makes it answerable to the run's clock like everything else.
        $data   = new CoverageData(lines: ['/app/src/Thing.php' => [1 => 1]]);
        $frozen = new FrozenClock(new DateTimeImmutable('@0'));

        $first  = (new PhpWriter())->write($data, 'xdebug', null, $frozen->now());
        $second = (new PhpWriter())->write($data, 'xdebug', null, $frozen->now());

        self::assertSame($first, $second);
        self::assertStringContainsString('1970', $first);
    }

    public function testAWallClockWriterStillReportsTheRealInstant(): void
    {
        // The normalisation must be opt-in: a default run still says
        // truthfully when it happened.
        $data     = new CoverageData(lines: ['/app/src/Thing.php' => [1 => 1]]);
        $rendered = (new PhpWriter())->write($data, 'xdebug', null, (new SystemClock())->now());

        self::assertStringNotContainsString('1970', $rendered);
    }

    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): CliOptions
    {
        $options = CliOptions::fromArgv(['crucible', ...$argv]);

        if (is_string($options)) {
            self::fail('Unexpected parse error: ' . $options);
        }

        return $options;
    }
}
