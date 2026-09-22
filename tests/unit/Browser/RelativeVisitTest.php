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
use LucianoPereira\Crucible\Browser\BrowserNotEnabledException;
use LucianoPereira\Crucible\Browser\Browsing;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Browser\Server\HttpResponse;
use LucianoPereira\Crucible\Browser\Server\RequestHandler;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function getcwd;
use function getenv;
use function is_file;
use function is_string;
use function sprintf;

/** The fixture application: one process, one mutable world. */
final class SharedWorldHandler implements RequestHandler
{
    public static string $greeting = 'unset';

    public function handle(HttpRequest $request): HttpResponse
    {
        if ($request->path() === '/greet') {
            return HttpResponse::html(sprintf('<title>Greet</title><h1>%s</h1>', self::$greeting));
        }

        return HttpResponse::notFound();
    }
}

/**
 * D-065: relative visit() URLs resolve against the in-process server
 * behind the configured ->browser(requestHandler:) — and the served
 * requests see the test's own mutations (the D-057 shared-state
 * contract, now through the dialect's front door).
 */
#[CoversClass(Browsing::class)]
final class RelativeVisitTest extends TestCase
{
    private static ?BrowserConfiguration $ambient = null;

    private static string $ambientDirectory = '/';

    protected function setUp(): void
    {
        // The runner configured the ambient tier once at startup —
        // reconfiguring for a scenario must put it back.
        self::$ambient          = Browsing::configured();
        self::$ambientDirectory = (string) getcwd();
    }

    private function restoreAmbient(): void
    {
        $directory = self::$ambientDirectory !== '' ? self::$ambientDirectory : '/';

        Browsing::configure(self::$ambient ?? new BrowserConfiguration(), new WorkingDirectory($directory));
    }

    public function testWithoutAHandlerARelativeVisitIsANamedError(): void
    {
        Browsing::configure(new BrowserConfiguration(enabled: true), new WorkingDirectory('/tmp'));

        try {
            $this->expectException(BrowserNotEnabledException::class);
            $this->expectExceptionMessage('requestHandler');

            Browsing::visit('/dashboard');
        } finally {
            $this->restoreAmbient();
        }
    }

    public function testAMisconfiguredHandlerClassIsANamedError(): void
    {
        $root = $this->playwrightRootOrSkip();

        Browsing::configure(new BrowserConfiguration(enabled: true, playwrightRoot: $root, requestHandler: 'No\Such\Handler'), new WorkingDirectory($root));

        try {
            $this->expectException(BrowserNotEnabledException::class);
            $this->expectExceptionMessage('does not exist');

            Browsing::visit('/x');
        } finally {
            $this->restoreAmbient();
        }
    }

    public function testRelativeVisitsServeThroughTheHandlerAndShareTheWorld(): void
    {
        $root = $this->playwrightRootOrSkip();

        Browsing::configure(
            new BrowserConfiguration(enabled: true, playwrightRoot: $root, requestHandler: SharedWorldHandler::class),
            new WorkingDirectory($root),
        );

        try {
            Browsing::begin();

            SharedWorldHandler::$greeting = 'hello from the test';

            $page = Browsing::visit('/greet');
            $page->assertTitle('Greet')->assertSee('hello from the test');

            // The next visit sees the NEXT mutation — one process,
            // one world (D-057's contract through visit()).
            SharedWorldHandler::$greeting = 'mutated between visits';
            Browsing::visit('/greet')->assertSee('mutated between visits');
        } finally {
            Browsing::end();
            Browsing::shutdown();
            $this->restoreAmbient();
        }
    }

    /**
     * @return non-empty-string
     */
    private function playwrightRootOrSkip(): string
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).');
        }

        return $root;
    }
}
