<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Runner\TimingOverhead;
use Override;

use function array_slice;
use function count;
use function fwrite;
use function sprintf;
use function usort;

/**
 * The slowest tests of the run (`--profile`).
 *
 * A pure event-stream listener like every other reporter: `TestFinished`
 * already carries the wall-clock duration the scheduler uses for
 * duration ordering (D-021), so nothing is measured here that the run
 * was not measuring anyway — this only decides what to show.
 *
 * Retries are counted as they ran. A test that passed on its third
 * attempt genuinely cost three attempts of wall clock, and hiding that
 * would flatter exactly the tests worth looking at.
 */
final class ProfileReporter implements Listener
{
    public const int DEFAULT_LIMIT = 10;

    /** @var list<array{name: string, duration: float}> */
    private array $timings = [];

    private float $total = 0.0;

    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream,
        private readonly Style $style,
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {}

    #[Override]
    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $this->timings[] = ['name' => $event->test->toString(), 'duration' => $event->duration];
            $this->total += $event->duration;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->report();
        }
    }

    private function report(): void
    {
        if ($this->timings === []) {
            return;
        }

        usort($this->timings, static fn(array $a, array $b): int => $b['duration'] <=> $a['duration']);

        $shown = array_slice($this->timings, 0, $this->limit);

        fwrite($this->stream, sprintf(
            "\n%s\n",
            $this->style->yellow(sprintf(
                'Slowest %d of %d test(s)',
                count($shown),
                count($this->timings),
            )),
        ));

        // A ranking is a claim about the tests, not about the run, so
        // it says when the profiler is what it ranked.
        $notice = TimingOverhead::notice();

        if ($notice !== null) {
            fwrite($this->stream, '  ' . $this->style->gray($notice) . "\n");
        }

        foreach ($shown as $timing) {
            // The share of total runtime is the number that decides
            // whether a slow test is worth anyone's afternoon: 2s means
            // little until you know it is half the suite.
            $share = $this->total > 0.0 ? $timing['duration'] / $this->total * 100 : 0.0;

            fwrite($this->stream, sprintf(
                "  %8.3fs  %s  %s\n",
                $timing['duration'],
                $this->style->gray(sprintf('%5.1f%%', $share)),
                $timing['name'],
            ));
        }

        fwrite($this->stream, "\n");
    }
}
