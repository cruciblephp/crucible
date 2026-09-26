<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Test\TestId;

use function fclose;
use function feof;
use function fgets;
use function fwrite;
use function getenv;
use function is_resource;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function rtrim;
use function str_ends_with;
use function stream_set_blocking;
use function substr;
use function trim;

use const PHP_BINARY;

/**
 * One spawned worker: `php crucible --worker`, manifest written to stdin
 * (then closed — the worker reads to EOF), NDJSON events read from
 * stdout line by line, stderr drained for crash diagnostics. All
 * reads are non-blocking; the Supervisor multiplexes many workers
 * over stream_select.
 */
final class WorkerProcess
{
    private string $stdoutBuffer = '';

    private string $stderrTail = '';

    private string $stderrPending = '';

    private bool $finished = false;

    /**
     * @param resource     $process
     * @param resource     $stdout
     * @param resource     $stderr
     * @param list<TestId> $expected the test ids this worker must finish
     */
    private function __construct(private $process, private $stdout, private $stderr, public readonly array $expected) {}

    /**
     * @param non-empty-string        $crucibleBinary path of the crucible entry script
     * @param list<TestId>            $expected
     * @param list<non-empty-string>  $phpArguments extra php CLI arguments (e.g. the -d flags that
     *                                              load the coverage driver — children do not inherit
     *                                              the parent's -d flags)
     * @param array<string, string>   $env          extra environment for the child, merged over the
     *                                              inherited environment (the mutation worker injects
     *                                              its mutant this way); empty = plain inheritance
     */
    public static function spawn(
        string $crucibleBinary,
        WorkingDirectory $workingDirectory,
        WorkerManifest $manifest,
        array $expected,
        array $phpArguments = [],
        array $env = [],
    ): ?self {
        $process = proc_open(
            [PHP_BINARY, ...$phpArguments, $crucibleBinary, '--worker'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory->path,
            $env === [] ? null : [...getenv(), ...$env],
        );

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $manifest->toJson());
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[1], $pipes[2], $expected);
    }

    /**
     * @return resource
     */
    public function stdout()
    {
        return $this->stdout;
    }

    /**
     * @return resource
     */
    public function stderr()
    {
        return $this->stderr;
    }

    /**
     * Complete NDJSON lines currently available, without blocking.
     * Partial lines stay buffered until their newline arrives.
     *
     * @return list<string>
     */
    public function readLines(): array
    {
        $lines = [];

        while (($chunk = fgets($this->stdout)) !== false) {
            $this->stdoutBuffer .= $chunk;

            if (!str_ends_with($this->stdoutBuffer, "\n")) {
                continue;
            }

            $line               = rtrim($this->stdoutBuffer, "\n");
            $this->stdoutBuffer = '';

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Drains stderr into a bounded tail kept for crash diagnostics, and
     * into the pending text {@see takeStderr()} hands over — what a test
     * wrote is the test's output, not only a crash's last words.
     */
    public function drainStderr(): void
    {
        // close() drained it one last time; what it held stays pending.
        if (!is_resource($this->stderr)) {
            return;
        }

        while (($chunk = fgets($this->stderr)) !== false) {
            $this->stderrTail = substr($this->stderrTail . $chunk, -2048);
            $this->stderrPending .= $chunk;
        }
    }

    /**
     * Everything drained since the last call. A pipe is written in
     * order, so draining when a test:finish line is read collects all
     * the test wrote before it finished.
     */
    public function takeStderr(): string
    {
        $this->drainStderr();

        $pending             = $this->stderrPending;
        $this->stderrPending = '';

        return $pending;
    }

    public function stderrTail(): string
    {
        return trim($this->stderrTail);
    }

    /**
     * The worker emitted its run:finish — the completion handshake.
     */
    public function markFinished(): void
    {
        $this->finished = true;
    }

    public function hasFinished(): bool
    {
        return $this->finished;
    }

    public function atEof(): bool
    {
        return feof($this->stdout);
    }

    /**
     * Hard-kill the worker (signal 9 by number, so no pcntl constant is
     * needed — the cold mutation path runs where pcntl is absent). Used to
     * enforce a mutant's timeout; close() still reaps it afterwards.
     */
    public function terminate(): void
    {
        proc_terminate($this->process, 9);
    }

    public function close(): int
    {
        $this->drainStderr();

        fclose($this->stdout);
        fclose($this->stderr);

        return proc_close($this->process);
    }
}
