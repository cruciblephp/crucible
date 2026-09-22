<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\LivewireSnapshot;
use LucianoPereira\Crucible\Browser\Playwright\Page;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function ctype_digit;
use function dirname;
use function fclose;
use function fsockopen;
use function getenv;
use function is_dir;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function microtime;
use function parse_url;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_socket_get_name;
use function stream_socket_server;
use function usleep;

use const PHP_URL_PORT;

/**
 * Livewire round-trip helpers proven against a **real Livewire 4 app**
 * (`livewire-oracle/`), booted by the test itself.
 *
 * It has to be a whole application, not a bundled asset: the cycle
 * `wireClick()` waits for is an HTTP request to Livewire's own update
 * endpoint, which recomputes the component server-side and rewrites the
 * snapshot. Imitating that markup would test the imitation, and would
 * never have revealed the finding this design rests on — that Livewire
 * updates `wire:snapshot` in the DOM, exactly opposite to Inertia.
 */
#[CoversClass(Page::class)]
#[CoversClass(LivewireSnapshot::class)]
final class LivewireTest extends TestCase
{
    /**
     * Asked of the OS per run, not fixed. A fixed port is worse than it
     * looks here: a server orphaned by an interrupted run still holds
     * it, `artisan serve` then fails to bind while proc_open still
     * succeeds, and the readiness poll below is answered by the *stale*
     * server — so the tests pass or hang against yesterday's app
     * instead of this one. Cost me an hour of a run that never ended.
     */
    private static ?int $port = null;

    private static ?Session $session = null;

    /** @var ?resource */
    private static $server;

    public static function tearDownAfterClass(): void
    {
        self::$session?->close();
        self::$session = null;

        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function testAComponentsStateIsReadFromItsSnapshot(): void
    {
        $page = $this->visit();

        $this->assertSame(['count' => 0, 'label' => 'untouched'], $page->wire());

        $page->assertWireSet('count', 0);
        $page->assertWireSet('label', 'untouched');
    }

    public function testARoundTripIsWaitedForAndChangesTheState(): void
    {
        // The whole helper in one line: clicking starts an HTTP update
        // cycle, and asserting before it lands is the race.
        $page = $this->visit();

        $page->wireClick('Add one');

        $page->assertWireSet('count', 1);
        $page->assertWireSet('label', 'incremented');
        $page->assertSeeIn('#count', '1');
    }

    public function testAComponentCanBeAddressedByName(): void
    {
        $page = $this->visit();

        $this->assertSame(0, $page->wire('counter')['count'] ?? null);
        $this->assertNull($page->wire('no-such-component'), 'An absent component reads as null, not as a failed match.');
    }

    private function visit(): Page
    {
        $repo = dirname(__DIR__, 3);
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : $repo . '/browser-oracle';

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available.');
        }

        if (!is_dir($repo . '/livewire-oracle/vendor/livewire/livewire')) {
            $this->markTestSkipped('No Livewire oracle installed — see ORACLES.md.');
        }

        $this->boot($repo . '/livewire-oracle');

        self::$session ??= Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        return self::$session->launch()->newContext()->newPage()
            ->navigate(sprintf('http://127.0.0.1:%d/', self::$port));
    }

    /** Starts the oracle once for the class, and waits for it to answer. */
    private function boot(string $application): void
    {
        if (is_resource(self::$server)) {
            return;
        }

        self::$port = $this->freePort();

        if (self::$port === null) {
            $this->markTestSkipped('No free port for the Livewire oracle (set CRUCIBLE_LIVEWIRE_PORT to choose one).');
        }

        $process = proc_open(
            ['php', 'artisan', 'serve', '--port=' . self::$port, '--no-reload'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $application,
        );

        if (!is_resource($process)) {
            $this->markTestSkipped('Could not start the Livewire oracle.');
        }

        self::$server = $process;

        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $code, $error, 0.2);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        $this->markTestSkipped('The Livewire oracle did not start listening.');
    }

    /**
     * CRUCIBLE_LIVEWIRE_PORT when the environment demands a fixed one —
     * a container publishing a port, a firewall rule — and otherwise
     * whatever the OS says is free right now, bound and released rather
     * than guessed.
     *
     * No literal fallback on purpose. A hard-wired number is what made
     * an interrupted run poison the next one, and guessing another would
     * only move the collision; null here means the caller skips, which
     * is a result rather than a hang.
     */
    private function freePort(): ?int
    {
        $configured = getenv('CRUCIBLE_LIVEWIRE_PORT');

        if (is_string($configured) && ctype_digit($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $code, $error);

        if (!is_resource($probe)) {
            return null;
        }

        $name = stream_socket_get_name($probe, false);
        fclose($probe);

        $port = $name === false ? null : parse_url('tcp://' . $name, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }
}
