<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use function count;
use function fmod;
use function fwrite;
use function implode;
use function is_array;
use function is_bool;
use function is_scalar;
use function memory_get_peak_usage;
use function microtime;
use function sprintf;

/**
 * The same event stream as {@see NdjsonWriter}, rendered for a person
 * rather than a parser: one line per event, sequence and name first.
 *
 * The NDJSON stream is the substrate every consumer reads; this is a
 * view onto it, which is why it takes no decisions the JSON writer does
 * not — the verbose form simply stops eliding the payload.
 */
final class EventTextWriter implements Listener
{
    private ?float $started = null;

    private ?float $previous = null;

    /**
     * @param resource $stream    an open, writable stream
     * @param bool     $verbose   include each event's payload fields
     * @param bool     $telemetry prefix each line with elapsed time and peak memory
     */
    public function __construct(
        private $stream,
        private readonly bool $verbose = false,
        private readonly bool $telemetry = false,
    ) {}

    public function handle(Envelope $envelope): void
    {
        $line = sprintf('%d %s', $envelope->sequence, $envelope->event->name()->value);

        if ($this->telemetry) {
            $line = $this->telemetryPrefix() . ' ' . $line;
        }

        if ($this->verbose) {
            $fields = [];

            foreach ($envelope->event->payload() as $key => $value) {
                $fields[] = $key . '=' . $this->scalar($value);
            }

            if ($fields !== []) {
                $line .= ' ' . implode(' ', $fields);
            }
        }

        fwrite($this->stream, $line . "\n");
    }

    /**
     * Time since the first event and since the previous one, then peak
     * memory — the spec's shape, and the two questions a trace is read
     * to answer: where the run went, and where a step went.
     */
    private function telemetryPrefix(): string
    {
        $now = microtime(true);

        $this->started ??= $now;
        $previous       = $this->previous ?? $now;
        $this->previous = $now;

        return sprintf(
            '[%s / %s] [%d bytes]',
            $this->duration($now - $this->started),
            $this->duration($now - $previous),
            memory_get_peak_usage(true),
        );
    }

    private function duration(float $seconds): string
    {
        return sprintf('%02d:%02d:%02d.%09d', (int) ($seconds / 3600), (int) ($seconds / 60) % 60, (int) $seconds % 60, (int) (fmod($seconds, 1) * 1_000_000_000));
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value)   => $value ? 'true' : 'false',
            $value === null   => 'null',
            is_array($value)  => '[' . count($value) . ']',
            is_scalar($value) => (string) $value,
            default           => '?',
        };
    }
}
