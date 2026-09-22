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
use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\Playwright\Connection;
use LucianoPereira\Crucible\Browser\Playwright\Page;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Browser\Server\HttpResponse;
use LucianoPereira\Crucible\Browser\Server\InProcessServer;
use LucianoPereira\Crucible\Browser\Server\RequestHandler;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function getenv;
use function is_file;
use function is_string;
use function sprintf;

/**
 * The network-settle primitive (D-084): the thing every framework
 * round-trip needs, proven against a real browser talking to a real
 * server — a `performance` heuristic or a mocked protocol would prove
 * nothing about whether a click's request cycle has actually landed.
 */
#[CoversClass(Page::class)]
#[CoversClass(Connection::class)]
final class NetworkIdleTest extends TestCase
{
    private static ?Session $session = null;

    private static ?InProcessServer $server = null;

    private static ?NetworkIdlePages $handler = null;

    public static function tearDownAfterClass(): void
    {
        self::$server?->close();
        self::$session?->close();
        self::$server  = null;
        self::$session = null;
    }

    public function testAPageThatSettlesReturnsOnceItIsQuiet(): void
    {
        $page = $this->visit('<h1>quiet</h1>');

        $page->waitForNetworkIdle(0.2, 5.0);

        $this->assertSame('quiet', $page->text('h1'));
    }

    public function testAWaitOutlastsARequestThatHasNotStartedYet(): void
    {
        // The case a bare "nothing in flight right now" check gets
        // wrong: the fetch begins 150 ms AFTER load, so an idle check
        // taken at load time would sail straight past it.
        $page = $this->visit(
            '<h1>late</h1><script>'
            . 'setTimeout(() => fetch("/late.json").then(r => r.text()).then(t => { window.LANDED = t; }), 150);'
            . '</script>',
        );

        $page->waitForNetworkIdle(0.4, 5.0);

        $this->assertSame('landed', $page->script('window.LANDED ?? null'));
    }

    public function testAPageThatNeverGoesQuietIsANamedFailure(): void
    {
        // Silence is never assumed: a page that keeps talking must say
        // so, not quietly pass as settled.
        $page = $this->visit(
            '<h1>chatty</h1><script>setInterval(() => fetch("/late.json"), 30);</script>',
        );

        $this->expectException(BrowserProtocolException::class);
        $this->expectExceptionMessageMatches('/never went quiet/');

        $page->waitForNetworkIdle(0.5, 1.5);
    }

    public function testAFailedRequestStillEndsTheWait(): void
    {
        // Built on request/requestFinished/requestFailed rather than
        // responses: a request that never yields a body would otherwise
        // hang the wait until its timeout.
        $page = $this->visit(
            '<h1>broken</h1><script>fetch("/nope").catch(() => { window.FAILED = 1; });</script>',
        );

        $page->waitForNetworkIdle(0.2, 5.0);

        $this->assertSame('broken', $page->text('h1'));
    }

    private function visit(string $body): Page
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).');
        }

        self::$handler ??= new NetworkIdlePages();
        self::$handler->body = $body;
        self::$server ??= new InProcessServer(self::$handler);

        self::$session ??= Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));
        self::$session->watch(self::$server);

        return self::$session->launch()->newContext()->newPage()->navigate(self::$server->baseUrl() . '/');
    }
}

/**
 * The pages this test serves: one shell whose body each case sets, plus
 * the two endpoints those shells talk to — a success and a 404, because
 * a failed request must end a wait just as a successful one does.
 */
final class NetworkIdlePages implements RequestHandler
{
    public string $body = '';

    public function handle(HttpRequest $request): HttpResponse
    {
        return match ($request->path()) {
            '/late.json' => new HttpResponse('landed', 200, 'text/plain'),
            '/nope'      => new HttpResponse('gone', 404, 'text/plain'),
            default      => HttpResponse::html(sprintf(
                '<!doctype html><html lang="en"><head><title>N</title></head><body>%s</body></html>',
                $this->body,
            )),
        };
    }
}
