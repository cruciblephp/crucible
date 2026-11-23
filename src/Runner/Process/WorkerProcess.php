<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\ProcessSetup;
use LucianoPereira\Crucible\Test\TestId;

use function fclose;
use function fgets;
use function fwrite;
use function getenv;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_set_blocking;
use function strpos;
use function substr;
use function trim;
use function usleep;

use const PHP_BINARY;

/**
 * One spawned worker: `php crucible --worker`, manifest written to stdin
 * (then closed — the worker reads to EOF), NDJSON events read from a
 * dedicated event channel, stdout drained (user output) and stderr
 * drained for crash diagnostics and D-124 attribution.
 *
 * The channel is separate from both user streams on purpose: a raw
 * `fwrite(STDOUT)` or `fwrite(STDERR)` can never be mistaken for a frame,
 * and no sentinel has to be taught to the reader. On POSIX it is fd 3;
 * on Windows, where the child cannot address an inherited handle by
 * number, it is a file read by offset (see {@see WorkerEventChannel}).
 *
 * All reads are non-blocking; the Supervisor multiplexes the pipes over
 * stream_select and reads a file channel on the same turn.
 */
final class WorkerProcess
{
    private string $eventsBuffer = '';

    private string $stderrTail = '';

    private string $stderrPending = '';

    private bool $finished = false;

    private bool $exited = false;

    /**
     * The first terminal status the process reported. proc_get_status()
     * answers a signal only to the call that first observes the exit: a
     * second read reports -1, which made close() lose the signal that
     * atEof() had already seen (WSL).
     *
     * @var ?array{exitcode: int, signaled: bool, termsig: int}
     */
    private ?array $terminal = null;

    private ?int $signal = null;

    /**
     * @param resource             $process
     * @param resource             $stdout
     * @param resource             $stderr
     * @param list<TestId>         $expected the test ids this worker must finish
     */
    private function __construct(
        private $process,
        private $stdout,
        private $stderr,
        private readonly WorkerEventChannel $events,
        public readonly array $expected,
    ) {}

    /**
     * @param non-empty-string        $crucibleBinary path of the crucible entry script
     * @param list<TestId>            $expected
     * @param list<non-empty-string>  $phpArguments extra php CLI arguments (e.g. the -d flags that
     *                                              load the coverage driver — children do not inherit
     *                                              the parent's -d flags)
     * @param array<string, string>   $env          extra environment for the child, merged over the
     *                                              inherited environment (the mutation worker injects
     *                                              its mutant this way); empty = plain inheritance
     * @param ?WorkerEventChannel     $events       the protocol channel; null = the platform's
     *                                              (fd 3 on POSIX, a file on Windows), injectable so
     *                                              both readers are exercised on one machine
     */
    public static function spawn(
        string $crucibleBinary,
        WorkingDirectory $workingDirectory,
        WorkerManifest $manifest,
        array $expected,
        array $phpArguments = [],
        array $env = [],
        ?WorkerEventChannel $events = null,
    ): ?self {
        $events ??= WorkerEventChannel::forPlatform();

        $process = proc_open(
            [PHP_BINARY, ...$phpArguments, $crucibleBinary, '--worker'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], ...$events->descriptors()],
            $pipes,
            $workingDirectory->path,
            self::environment([...$env, WorkerEventChannel::ENV => $events->spec()]),
        );

        if (!is_resource($process)) {
            $events->close();

            return null;
        }

        fwrite($pipes[0], $manifest->toJson());
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $events->bind($pipes);

        return new self($process, $pipes[1], $pipes[2], $events, $expected);
    }

    /**
     * @param array<string, string> $env
     *
     * @return ?array<string, string>
     */
    private static function environment(array $env): ?array
    {
        $inherited = ProcessSetup::inheritedEnvironment();

        if ($env === [] && $inherited === null) {
            return null;
        }

        return [...$inherited ?? getenv(), ...$env];
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
     * The event pipe when the channel is selectable; null for a file.
     *
     * @return ?resource
     */
    public function events()
    {
        return $this->events->selectable();
    }

    public function eventsAreSelectable(): bool
    {
        return $this->events->isSelectable();
    }

    /**
     * The next complete NDJSON event, without blocking. A partial line
     * stays buffered until its newline arrives.
     */
    public function nextLine(): ?string
    {
        while (true) {
            $newline = strpos($this->eventsBuffer, "\n");

            if ($newline !== false) {
                $line               = substr($this->eventsBuffer, 0, $newline);
                $this->eventsBuffer = substr($this->eventsBuffer, $newline + 1);

                if ($line !== '') {
                    return $line;
                }

                continue;
            }

            $chunk = $this->events->read();

            if ($chunk === '') {
                return null;
            }

            $this->eventsBuffer .= $chunk;
        }
    }

    /**
     * Every complete event currently available, one frame at a time.
     *
     * @return list<string>
     */
    public function readLines(): array
    {
        $lines = [];

        while (($line = $this->nextLine()) !== null) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Reads and discards what the worker wrote to stdout — a direct
     * `fwrite(STDOUT)`, bootstrap output, the test's raw reprint. It is
     * user output, never protocol, and draining it keeps the pipe from
     * filling.
     */
    public function drainStdout(): void
    {
        if (!is_resource($this->stdout)) {
            return;
        }

        while (fgets($this->stdout) !== false) {
            // Discarded by policy: a worker's stdout is not forwarded.
        }
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
     * Everything drained since the last call. The worker writes a test's
     * stderr before it emits the finish frame, so draining when that
     * frame is read collects the test's process output. The event channel
     * carries no user bytes, so text the same process writes after the
     * frame is still its own, never a protocol frame.
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

    /**
     * Whether the worker is done and its channels fully read. The first
     * call after the process exits answers false so the caller reads the
     * bytes it wrote before exiting; the next one answers true.
     */
    public function atEof(): bool
    {
        if ($this->exited) {
            return true;
        }

        if ($this->terminalStatus() === null) {
            return false;
        }

        $this->exited = true;

        return false;
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

    /**
     * The signal that killed the worker, once close() reaped it; null
     * for a normal exit.
     */
    public function signal(): ?int
    {
        return $this->signal;
    }

    /**
     * Reaps the worker. The status is read before proc_close(), which
     * folds a signal into the exit code; a signal is returned as the
     * code, as proc_close() alone returns it.
     */
    public function close(): int
    {
        $this->drainStdout();
        $this->drainStderr();

        fclose($this->stdout);
        fclose($this->stderr);

        $this->events->close();

        do {
            $status = $this->terminalStatus();

            if ($status === null) {
                usleep(1_000);
            }
        } while ($status === null);

        $exit = proc_close($this->process);

        if ($status['signaled']) {
            $this->signal = $status['termsig'];

            return $status['termsig'];
        }

        return $exit;
    }

    /**
     * The status of a process that is no longer running, cached: the
     * first call is the only one that carries the signal.
     *
     * @return ?array{exitcode: int, signaled: bool, termsig: int}
     */
    private function terminalStatus(): ?array
    {
        if ($this->terminal !== null) {
            return $this->terminal;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return null;
        }

        return $this->terminal = [
            'exitcode' => $status['exitcode'],
            'signaled' => $status['signaled'],
            'termsig'  => $status['termsig'],
        ];
    }
}
