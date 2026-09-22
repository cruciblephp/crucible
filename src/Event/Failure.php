<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use function array_map;

/**
 * Structured failure detail carried by a failing/erroring test:finish
 * event. Structured, not a text blob — TAP 14's YAML diagnostics and
 * jest-diff's Expected/Received model are the design inputs; reporters
 * decide presentation.
 */
final readonly class Failure
{
    /**
     * @param non-empty-string  $message
     * @param ?non-empty-string $throwableClass
     * @param list<Frame>       $trace
     * @param ?non-empty-string $expected serialized expected value, when the failure is a comparison
     * @param ?non-empty-string $actual   serialized actual value, when the failure is a comparison
     */
    public function __construct(
        public string $message,
        public ?string $throwableClass = null,
        public array $trace = [],
        public ?string $expected = null,
        public ?string $actual = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $failure = ['message' => $this->message];

        if ($this->throwableClass !== null) {
            $failure['class'] = $this->throwableClass;
        }

        if ($this->trace !== []) {
            $failure['trace'] = array_map(
                static fn(Frame $frame): array => $frame->toArray(),
                $this->trace,
            );
        }

        if ($this->expected !== null || $this->actual !== null) {
            $failure['diff'] = ['expected' => $this->expected, 'actual' => $this->actual];
        }

        return $failure;
    }
}
