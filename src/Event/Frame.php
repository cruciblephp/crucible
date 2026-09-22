<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

/**
 * One stack-trace frame of a failure payload.
 */
final readonly class Frame
{
    /**
     * @param non-empty-string  $file
     * @param positive-int      $line
     * @param ?non-empty-string $function
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $function = null,
    ) {}

    /**
     * @return array{file: non-empty-string, line: positive-int, function?: non-empty-string}
     */
    public function toArray(): array
    {
        $frame = ['file' => $this->file, 'line' => $this->line];

        if ($this->function !== null) {
            $frame['function'] = $this->function;
        }

        return $frame;
    }
}
