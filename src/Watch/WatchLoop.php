<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Watch;

use LucianoPereira\Crucible\Runner\Process\NullDevice;
use LucianoPereira\Crucible\Test\TestId;

use function array_any;
use function array_keys;
use function array_values;
use function count;
use function fclose;
use function feof;
use function file;
use function fread;
use function function_exists;
use function fwrite;
use function getmypid;
use function in_array;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function pcntl_async_signals;
use function pcntl_signal;
use function proc_close;
use function proc_open;
use function realpath;
use function shell_exec;
use function sprintf;
use function str_starts_with;
use function stream_isatty;
use function stream_select;
use function stream_set_blocking;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const PHP_EOL;
use const PHP_OS_FAMILY;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * The watch loop (growth G3 slice 2): poll the suite and source
 * directories, and run every iteration in a **child process** — PHP
 * cannot re-require changed files in one process (redeclare fatals),
 * so a fresh child per run is the only honest reload. The child's
 * NDJSON event stream is the result API: --log-events-json to a
 * temp file, test:finish outcomes decide the failed set. Failed
 * tests come first in every re-run (--order-by defects) unless the
 * user pinned an order.
 *
 * WatchSession decides what runs; this class only does IO.
 */
final class WatchLoop
{
    private const int POLL_MICROSECONDS = 250_000;

    private string $sttyState = '';

    /**
     * @param list<non-empty-string> $childArgv   the crucible invocation to replay, --watch removed, [0] = script path
     * @param list<non-empty-string> $directories absolute paths to watch
     * @param list<non-empty-string> $files       absolute paths to watch (the configuration file)
     */
    public function __construct(
        private readonly array $childArgv,
        private readonly array $directories,
        private readonly array $files,
        private readonly FileWatcher $watcher = new FileWatcher(),
        private readonly WatchSession $session = new WatchSession(),
        private readonly ?RetriggerListener $retrigger = null,
    ) {}

    public function run(): int
    {
        $pid         = getmypid();
        $eventFile   = sys_get_temp_dir() . '/crucible-watch-' . ($pid === false ? 0 : $pid) . '.ndjson';
        $interactive = $this->enterRawTty();

        $this->cleanUpOnSignal($eventFile);

        $snapshot = $this->watcher->snapshot($this->directories, $this->files);
        $last     = WatchRun::full('the initial run');

        $this->execute($last, $eventFile);
        $this->status($interactive);

        try {
            while (true) {
                $key = $this->waitForKey();

                if ($key === 'q') {
                    $this->out(PHP_EOL);

                    return 0;
                }

                $run = match ($key) {
                    'a'        => WatchRun::full('requested with the a key'),
                    'u'        => WatchRun::full('recording snapshots (D-042)', ['--update-snapshots']),
                    'f'        => $this->session->failedRun(),
                    "\n", "\r" => $last,
                    default    => null,
                };

                if ($key === 'f' && !$run instanceof WatchRun) {
                    $this->out(PHP_EOL . '[watch] Nothing is failing.' . PHP_EOL);
                    $this->status($interactive);

                    continue;
                }

                // A pushed change set (D-083) is authoritative: the
                // producer already resolved what changed, so it needs
                // no debounce. Absorbing the disk state keeps the same
                // edit from running a second time when the watcher
                // notices it a tick later.
                if (!$run instanceof WatchRun) {
                    $pushed = $this->retrigger?->take() ?? [];

                    if ($pushed !== []) {
                        $snapshot = $this->watcher->snapshot($this->directories, $this->files);
                        $run      = $this->session->onChange($this->resolveAll($pushed), false);
                    }
                }

                if (!$run instanceof WatchRun) {
                    $current = $this->watcher->snapshot($this->directories, $this->files);
                    $diff    = $this->watcher->diff($snapshot, $current);

                    if ($diff['changed'] === [] && $diff['deleted'] === []) {
                        continue;
                    }

                    // Debounce: editors write in bursts; wait for a
                    // quiet interval before running.
                    do {
                        usleep(150_000);

                        $settled = $this->watcher->snapshot($this->directories, $this->files);
                        $more    = $this->watcher->diff($current, $settled);
                        $current = $settled;
                    } while ($more['changed'] !== [] || $more['deleted'] !== []);

                    $diff     = $this->watcher->diff($snapshot, $current);
                    $snapshot = $current;

                    /** @var list<non-empty-string> $changed */
                    $changed = $diff['changed'];

                    $run = $this->session->onChange($changed, $diff['deleted'] !== []);
                }
                // Key-triggered runs leave the snapshot untouched: an
                // edit racing the keypress still triggers its own run
                // on the next poll rather than being swallowed.

                $last = $run;

                $this->execute($run, $eventFile);
                $this->status($interactive);
            }
        } finally {
            $this->restoreTty();

            if (is_file($eventFile)) {
                unlink($eventFile);
            }
        }
    }

    /**
     * Runs one child, feeds the outcome to the session, and runs the
     * follow-up it demands (the green-again full confirmation).
     */
    private function execute(WatchRun $run, string $eventFile): void
    {
        $this->out(sprintf(PHP_EOL . '[watch] Running: %s.' . PHP_EOL . PHP_EOL, $run->reason));

        $command = [PHP_BINARY, ...$this->childArgv];

        foreach ($run->related ?? [] as $file) {
            $command[] = '--related';
            $command[] = $file;
        }

        foreach ($run->extraArguments as $argument) {
            $command[] = $argument;
        }

        if (!$this->orderPinned()) {
            $command[] = '--order-by';
            $command[] = 'defects';
        }

        // Last occurrence wins in the child's CLI parsing, so this
        // owns the event stream even if the user passed their own.
        $command[] = '--log-events-json';
        $command[] = $eventFile;

        // The child sees a pipe, not the terminal — keep its colors on.
        if (stream_isatty(STDOUT) && !array_any($this->childArgv, static fn(string $argument): bool => str_starts_with($argument, '--colors'))) {
            $command[] = '--colors=always';
        }

        // Child output is copied through the parent rather than
        // sharing the descriptor: proc_open'd children do not share
        // the parent's file offset, so direct fd sharing scrambles
        // (and overwrites) redirected output.
        $process = proc_open($command, [0 => ['file', NullDevice::path(), 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            $this->out('[watch] Could not start the test run.' . PHP_EOL);

            return;
        }

        $this->forward($pipes);

        proc_close($process);

        $failed   = $this->failedFilesIn($eventFile);
        $followUp = $this->session->onResult($run, $failed);

        if ($followUp instanceof WatchRun) {
            $this->execute($followUp, $eventFile);
        }
    }

    /**
     * The child's event stream is the result API: files with a
     * failed or errored test:finish.
     *
     * @return list<non-empty-string>
     */
    private function failedFilesIn(string $eventFile): array
    {
        /** @var array<non-empty-string, true> $failed */
        $failed = [];

        $lines = is_file($eventFile) ? file($eventFile) : false;

        foreach ($lines === false ? [] : $lines as $line) {
            $event = json_decode($line, true);

            if (!is_array($event)
                || ($event['event'] ?? null) !== 'test:finish'
                || !in_array($event['outcome'] ?? null, ['fail', 'error'], true)
                || !is_string($event['id'] ?? null)
            ) {
                continue;
            }

            $id = TestId::fromString($event['id']);

            if ($id instanceof TestId) {
                $failed[$id->file] = true;
            }
        }

        return array_keys($failed);
    }

    private function orderPinned(): bool
    {
        return array_any($this->childArgv, static fn(string $argument): bool => str_starts_with($argument, '--order-by'));
    }

    private function status(bool $interactive): void
    {
        $state = $this->session->isRed()
            ? sprintf('%d file(s) failing', count($this->session->failedRun()->related ?? []))
            : 'all green';

        $this->out(sprintf(
            PHP_EOL . '[watch] %s — waiting for changes%s' . PHP_EOL,
            $state,
            $interactive ? '  (enter rerun · a all · f failed · u snapshots · q quit)' : '',
        ));
    }

    /**
     * Copies the child's stdout/stderr onto the parent's until both
     * close — one writer, one offset, deterministic ordering.
     *
     * @param array<int, resource> $pipes
     */
    private function forward(array $pipes): void
    {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $read   = array_values($open);
            $write  = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 200_000) === false) {
                break;
            }

            foreach ($open as $channel => $pipe) {
                $chunk = fread($pipe, 65536);

                if ($chunk !== false && $chunk !== '') {
                    fwrite($channel === 1 ? STDOUT : STDERR, $chunk);
                }

                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$channel]);
                }
            }
        }
    }

    /**
     * Straight to the STDOUT descriptor, unbuffered, so parent lines
     * and forwarded child output stay in write order everywhere.
     */
    private function out(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    /**
     * @return ?string one key, or null when the poll interval elapsed
     */
    /**
     * A producer may push either shape; the graphs downstream compare
     * absolute paths, so resolve what resolves and pass the rest through
     * untouched (a deleted file has no realpath, and `--related` will
     * resolve it against the working directory anyway).
     *
     * @param list<non-empty-string> $paths
     *
     * @return list<non-empty-string>
     */
    private function resolveAll(array $paths): array
    {
        $resolved = [];

        foreach ($paths as $path) {
            $real       = realpath($path);
            $resolved[] = $real === false ? $path : $real;
        }

        return $resolved;
    }

    private function waitForKey(): ?string
    {
        $pushed = $this->retrigger?->watchStreams() ?? [];

        // Without a tty there is no key to wait for, but a push still
        // has to wake the loop — so the endpoint's streams are selected
        // on either way, and only the STDIN half is conditional.
        if ($this->sttyState === '' && $pushed === []) {
            usleep(self::POLL_MICROSECONDS);

            return null;
        }

        $read   = $this->sttyState === '' ? $pushed : [STDIN, ...$pushed];
        $write  = null;
        $except = null;

        $ready = stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS);

        if ($ready === false || $ready < 1) {
            return null;
        }

        // Serve whatever arrived before reading a key: the pump only
        // buffers, so the push is collected on this same tick.
        $this->retrigger?->pump();

        if ($this->sttyState === '' || !in_array(STDIN, $read, true)) {
            return null;
        }

        $key = fread(STDIN, 1);

        return $key === false || $key === '' ? null : $key;
    }

    /**
     * @return bool whether interactive keys are live
     */
    /**
     * `finally` does not run when the process is signalled, and a watch
     * session is usually ended with Ctrl-C rather than `q` — which used
     * to leave the terminal with echo off, and would now also leave a
     * hot file claiming a dead session is still listening.
     *
     * Signals are the only way to hear about it, so this is the one
     * place outside mutation that touches pcntl — guarded, never
     * required: without the extension `q` still cleans up, exactly as
     * before.
     */
    private function cleanUpOnSignal(string $eventFile): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $cleanUp = function (int $signal) use ($eventFile): never {
            $this->restoreTty();
            $this->retrigger?->close();

            if (is_file($eventFile)) {
                unlink($eventFile);
            }

            exit(128 + $signal);
        };

        pcntl_signal(SIGINT, $cleanUp);
        pcntl_signal(SIGTERM, $cleanUp);
        pcntl_signal(SIGHUP, $cleanUp);
    }

    private function enterRawTty(): bool
    {
        if (PHP_OS_FAMILY === 'Windows' || !stream_isatty(STDIN)) {
            return false;
        }

        $state = shell_exec('stty -g 2>/dev/null');

        if (!is_string($state) || trim($state) === '') {
            return false;
        }

        $this->sttyState = trim($state);

        shell_exec('stty -icanon -echo 2>/dev/null');

        return true;
    }

    private function restoreTty(): void
    {
        if ($this->sttyState !== '') {
            shell_exec('stty ' . $this->sttyState . ' 2>/dev/null');

            $this->sttyState = '';
        }
    }
}
