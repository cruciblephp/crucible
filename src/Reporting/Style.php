<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

/**
 * ANSI coloring for the console reporters. A Style either paints or
 * does not — the auto/always/never resolution (TTY detection
 * included) happens once, in the CLI, not per write.
 */
final readonly class Style
{
    public function __construct(
        public bool $enabled = false,
    ) {}

    public function green(string $text): string
    {
        return $this->paint('32', $text);
    }

    public function red(string $text): string
    {
        return $this->paint('31', $text);
    }

    public function yellow(string $text): string
    {
        return $this->paint('33', $text);
    }

    /**
     * A muted tone for the quiet, non-alarming output — the untested
     * block, whose skips are environmental rather than defects.
     */
    public function gray(string $text): string
    {
        return $this->paint('90', $text);
    }

    private function paint(string $code, string $text): string
    {
        return $this->enabled ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
