<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\PlaywrightNotInstalledException;
use LucianoPereira\Crucible\Browser\Pumpable;
use Override;

use function array_merge;
use function error_clear_last;
use function error_get_last;
use function fclose;
use function feof;
use function fread;
use function getenv;
use function in_array;
use function is_file;
use function is_numeric;
use function is_resource;
use function is_string;
use function microtime;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;

use const DIRECTORY_SEPARATOR;

/**
 * The default transport: `playwright run-driver` spawned over stdio
 * pipes, frames per FrameStream/FrameBuffer. The same channel every
 * non-JS Playwright language port uses — no websocket, no Node-side
 * client, no PHP dependency. receive() is a select loop over the
 * driver pipe AND any watched Pumpable streams (the in-process HTTP
 * server): while PHP waits for a navigation reply, the browser's
 * page requests are served from this very loop.
 */
final class DriverTransport implements Transport
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private readonly FrameBuffer $frames;

    /** @var list<Pumpable> */
    private array $pumps = [];

    private bool $closed = false;

    /**
     * How long a single driver reply may take before the wait is called
     * a failure. Generous, because a legitimate navigation or download
     * can be slow; finite, because the alternative is what this replaced
     * — a run that never ends and reports nothing. CRUCIBLE_BROWSER_TIMEOUT
     * overrides it; the caller's own waits (waitForNetworkIdle and
     * friends) are the tight bounds, this is the backstop under them.
     */
    private readonly float $replyTimeout;

    /**
     * @param non-empty-string $playwrightRoot directory whose node_modules provides the playwright CLI
     * @param ?float           $replyTimeout   seconds; null reads CRUCIBLE_BROWSER_TIMEOUT, default 120
     */
    public function __construct(string $playwrightRoot, ?float $replyTimeout = null)
    {
        $configured         = getenv('CRUCIBLE_BROWSER_TIMEOUT');
        $this->replyTimeout = $replyTimeout ?? (is_string($configured) && is_numeric($configured) && (float) $configured > 0
            ? (float) $configured
            : 120.0);

        $binary = $playwrightRoot . DIRECTORY_SEPARATOR . 'node_modules'
            . DIRECTORY_SEPARATOR . '.bin' . DIRECTORY_SEPARATOR . 'playwright';

        if (!is_file($binary)) {
            throw new PlaywrightNotInstalledException(sprintf(
                "Playwright is not installed under %s.\nThe browser tier never installs anything itself; run:\n\n    npm install playwright\n    npx playwright install\n\nor point the configuration at an existing install with ->browser(playwrightRoot: '...').",
                $playwrightRoot,
            ));
        }

        $pipes   = [];
        $process = proc_open(
            [$binary, 'run-driver'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $playwrightRoot,
        );

        if (!is_resource($process)) {
            throw new BrowserProtocolException(sprintf('Could not start the Playwright driver (%s run-driver).', $binary));
        }

        [$this->stdin, $this->stdout, $this->stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        $this->process                               = $process;
        $this->frames                                = new FrameBuffer();
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    /** Serve this alongside driver waits (the in-process server). */
    public function watch(Pumpable $pump): void
    {
        $this->pumps[] = $pump;
    }

    #[Override]
    public function send(array $message): void
    {
        FrameStream::write($this->stdin, $message);
    }

    #[Override]
    public function receive(): array
    {
        $frame = $this->frames->next();

        if ($frame !== null) {
            return $frame;
        }

        $deadline = microtime(true) + $this->replyTimeout;

        while (true) {
            if (microtime(true) > $deadline) {
                throw $this->died(sprintf(
                    'the driver sent nothing for %.0f seconds (raise CRUCIBLE_BROWSER_TIMEOUT if that is legitimately slow)',
                    $this->replyTimeout,
                ));
            }

            $read = [$this->stdout];

            foreach ($this->pumps as $pump) {
                $read = array_merge($read, $pump->watchStreams());
            }

            $write  = null;
            $except = null;

            error_clear_last();

            if (@stream_select($read, $write, $except, 1) === false) {
                // ⚠ A signal interrupting select is not a dead driver.
                // EINTR means ask again, and the deadline above still
                // bounds how long asking may go on. Treating it as death
                // killed every browser test the moment anything in the
                // process took a timer.
                if ($this->interrupted()) {
                    continue;
                }

                throw $this->died('select() failed on the driver pipe');
            }

            if (in_array($this->stdout, $read, true)) {
                $chunk = fread($this->stdout, 65_536);

                if (($chunk === false || $chunk === '') && feof($this->stdout)) {
                    throw $this->died('the driver stream ended early');
                }

                if ($chunk !== false && $chunk !== '') {
                    $this->frames->append($chunk);
                }
            }

            foreach ($this->pumps as $pump) {
                $pump->pump();
            }

            $frame = $this->frames->next();

            if ($frame !== null) {
                return $frame;
            }
        }
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        fclose($this->stdin);
        fclose($this->stdout);
        fclose($this->stderr);
        proc_terminate($this->process);
        proc_close($this->process);
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Whether the last suppressed warning was EINTR rather than a real failure. */
    private function interrupted(): bool
    {
        $last = error_get_last();

        return $last !== null && str_contains($last['message'], 'Interrupted system call');
    }

    private function died(string $what): BrowserProtocolException
    {
        $stderr  = stream_get_contents($this->stderr);
        $message = sprintf('Driver transport failed: %s.', $what);

        return new BrowserProtocolException(
            $stderr === false || $stderr === '' ? $message : sprintf("%s\nDriver stderr:\n%s", $message, $stderr),
        );
    }
}
