<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Terminal;

use function array_shift;

/**
 * An in-memory {@see Terminal} for tests and non-interactive scripting.
 *
 * Input is supplied up front as a queue of key sequences; all output is
 * captured so it can be asserted against.
 *
 * @phpcpd-keep Driven from tests/ through Runtime::setTerminal(); phpcpd scans
 * src/ only, so "used by no production code" and "dead" look identical to it.
 * It stays in src/ deliberately — it implements Terminal, and a stand-in outside
 * the type gate drifts from the contract it imitates with nothing to notice.
 */
final class FakeTerminal implements Terminal
{
    private string $output = '';

    private string $errorOutput = '';

    private bool $rawMode = false;

    /**
     * @param list<string> $keys queued input chunks, consumed one per read
     * @param bool $interactive whether prompts should treat this as a TTY
     */
    public function __construct(private array $keys = [], private readonly int $columns = 80, private readonly int $lines = 24, private readonly bool $interactive = true) {}

    public function enableRawMode(): void
    {
        $this->rawMode = true;
    }

    public function restoreMode(): void
    {
        $this->rawMode = false;
    }

    public function read(): string
    {
        return array_shift($this->keys) ?? '';
    }

    public function write(string $text): void
    {
        $this->output .= $text;
    }

    public function writeError(string $text): void
    {
        $this->errorOutput .= $text;
    }

    public function columns(): int
    {
        return $this->columns;
    }

    public function lines(): int
    {
        return $this->lines;
    }

    public function supportsInteractivity(): bool
    {
        return $this->interactive;
    }

    public function isRawMode(): bool
    {
        return $this->rawMode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    /** Append additional input chunks to the queue. */
    public function pushKeys(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->keys[] = $key;
        }
    }
}
