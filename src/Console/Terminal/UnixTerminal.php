<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Terminal;

use LucianoPereira\Crucible\Console\Exceptions\TerminalException;

use function defined;
use function exec;
use function explode;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function implode;
use function is_numeric;
use function is_string;
use function max;
use function shell_exec;
use function sprintf;
use function stream_isatty;
use function stream_set_blocking;
use function trim;

use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * A {@see Terminal} backed by a real POSIX TTY, driving raw mode through the
 * `stty` utility and reading/writing via the standard streams.
 */
final class UnixTerminal implements Terminal
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /** @var resource */
    private $errorOutput;

    private ?string $initialMode = null;

    public function __construct()
    {
        $this->input       = defined('STDIN') ? STDIN : $this->open('php://stdin', 'rb');
        $this->output      = defined('STDOUT') ? STDOUT : $this->open('php://stdout', 'wb');
        $this->errorOutput = defined('STDERR') ? STDERR : $this->open('php://stderr', 'wb');
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $stream = fopen($path, $mode);

        if ($stream === false) {
            throw new TerminalException(sprintf('Unable to open stream "%s".', $path));
        }

        return $stream;
    }

    public function enableRawMode(): void
    {
        if ($this->initialMode !== null) {
            return;
        }

        $this->initialMode = $this->exec('stty -g');
        $this->exec('stty -icanon -isig -echo');
        stream_set_blocking($this->input, true);
    }

    public function restoreMode(): void
    {
        if ($this->initialMode === null) {
            return;
        }

        $this->exec(sprintf('stty %s', $this->initialMode));
        $this->initialMode = null;
    }

    public function read(): string
    {
        $chunk = fread($this->input, 1024);

        return $chunk === false ? '' : $chunk;
    }

    public function write(string $text): void
    {
        fwrite($this->output, $text);
    }

    public function writeError(string $text): void
    {
        fwrite($this->errorOutput, $text);
    }

    public function columns(): int
    {
        $columns = getenv('COLUMNS');

        if ($columns !== false && is_numeric($columns)) {
            return max(1, (int) $columns);
        }

        return $this->size()[1] ?? 80;
    }

    public function lines(): int
    {
        $lines = getenv('LINES');

        if ($lines !== false && is_numeric($lines)) {
            return max(1, (int) $lines);
        }

        return $this->size()[0] ?? 24;
    }

    /**
     * The terminal [rows, columns] via `stty size`, or [null, null] when it is
     * unavailable (e.g. no TTY) so callers can fall back to sensible defaults.
     *
     * @return array{int|null, int|null}
     */
    private function size(): array
    {
        try {
            $parts = explode(' ', $this->exec('stty size'));
        } catch (TerminalException) {
            return [null, null];
        }

        if (isset($parts[1]) && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return [max(1, (int) $parts[0]), max(1, (int) $parts[1])];
        }

        return [null, null];
    }

    public function supportsInteractivity(): bool
    {
        return function_exists('stream_isatty')
            && stream_isatty($this->input)
            && stream_isatty($this->output)
            && $this->hasStty();
    }

    /**
     * Run a shell command against the controlling TTY and return trimmed
     * stdout. Failure is detected from the exit code, not from empty output:
     * commands like `stty -icanon` succeed while printing nothing at all.
     */
    private function exec(string $command): string
    {
        $output   = [];
        $exitCode = 0;

        exec($command . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new TerminalException(sprintf('Failed to execute terminal command: "%s".', $command));
        }

        return trim(implode("\n", $output));
    }

    private function hasStty(): bool
    {
        $which = shell_exec('command -v stty 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }
}
