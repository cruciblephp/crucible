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
 * Scripts that must run BEFORE the page's own code — the seam a
 * framework-protocol recorder needs, since events fired during boot are
 * gone by the time an assertion could subscribe.
 *
 * Pinned as a test rather than a throwaway probe: a `data:` URL runs
 * neither init scripts nor network events, so a scratch script "proves"
 * the feature is missing when it is only mis-addressed. The harness
 * serves real HTTP, which is the difference.
 */
#[CoversClass(Page::class)]
final class InitScriptTest extends TestCase
{
    private static ?Session $session = null;

    private static ?InProcessServer $server = null;

    private static ?InitScriptPages $handler = null;

    public static function tearDownAfterClass(): void
    {
        self::$server?->close();
        self::$session?->close();
        self::$server  = null;
        self::$session = null;
    }

    public function testAnInitScriptOnTheContextRunsBeforePageScripts(): void
    {
        $session = $this->session();
        $context = $session->launch()->newContext();

        $context->addInitScript('window.__CRUCIBLE_EARLY = "installed";');

        $page = $context->newPage()->navigate($this->url());

        // The page's own script records what it saw at parse time: if the
        // init script had run late, the page would have seen undefined.
        $this->assertSame('installed', $page->script('window.__SEEN_BY_PAGE ?? null'));
        $this->assertSame('installed', $page->script('window.__CRUCIBLE_EARLY ?? null'));
    }

    public function testAnInitScriptAppliesToEveryPageInTheContext(): void
    {
        $session = $this->session();
        $context = $session->launch()->newContext();

        $context->addInitScript('window.__CRUCIBLE_EARLY = "installed";');

        $context->newPage()->navigate($this->url());
        $second = $context->newPage()->navigate($this->url());

        $this->assertSame('installed', $second->script('window.__SEEN_BY_PAGE ?? null'));
    }

    private function url(): string
    {
        self::$handler ??= new InitScriptPages();
        self::$server ??= new InProcessServer(self::$handler);

        self::$session?->watch(self::$server);

        return self::$server->baseUrl() . '/';
    }

    private function session(): Session
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).');
        }

        self::$session ??= Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        return self::$session;
    }
}

/** One page whose inline script records whatever the init script left. */
final class InitScriptPages implements RequestHandler
{
    public function handle(HttpRequest $request): HttpResponse
    {
        return HttpResponse::html(sprintf(
            '<!doctype html><html lang="en"><head><title>I</title></head><body>%s</body></html>',
            '<h1>init</h1><script>window.__SEEN_BY_PAGE = window.__CRUCIBLE_EARLY ?? null;</script>',
        ));
    }
}
