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
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Browser\Server\HttpResponse;
use LucianoPereira\Crucible\Browser\Server\InProcessServer;
use LucianoPereira\Crucible\Browser\Server\RequestHandler;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use Override;

use function dirname;
use function getenv;
use function is_file;
use function is_string;
use function sprintf;

/**
 * B6 acceptance: the browser's page loads are served by THIS process,
 * from the driver-wait select loop — and therefore see every state
 * mutation the test makes. The shared-state contract, proven without
 * any framework.
 */
#[CoversClass(InProcessServer::class)]
final class InProcessServerTest extends TestCase
{
    public function testServedRequestsShareTheTestProcessState(): void
    {
        $root = $this->playwrightRootOrSkip();

        $handler = new class implements RequestHandler {
            public string $greeting = 'Hello';

            /** @var list<string> */
            public array $seenPaths = [];

            #[Override]
            public function handle(HttpRequest $request): HttpResponse
            {
                $this->seenPaths[] = $request->path();

                if ($request->path() === '/favicon.ico') {
                    return HttpResponse::notFound();
                }

                $name = $request->query()['name'] ?? 'world';

                return HttpResponse::html(sprintf(
                    '<title>Served</title><h1>%s, %s</h1><a href="/next">Next</a>',
                    $this->greeting,
                    is_string($name) ? $name : 'world',
                ));
            }
        };

        $server  = new InProcessServer($handler);
        $session = Session::start(
            new BrowserConfiguration(enabled: true, playwrightRoot: $root),
            new WorkingDirectory($root),
            $server,
        );

        try {
            $browser = $session->launch();
            $page    = $browser->newContext()->newPage();

            // the page load is served while PHP waits on the goto reply
            $page->navigate($server->baseUrl() . '/greet?name=crucible');
            $page->assertTitle('Served')->assertSee('Hello, crucible');

            // state mutated in the TEST is visible to the NEXT request —
            // the whole point of the in-process design
            $handler->greeting = 'Bonjour';
            $page->click('Next');
            $page->assertSee('Bonjour, world');

            // and state mutated by REQUESTS is visible to the test
            $this->assertContains('/greet', $handler->seenPaths);
            $this->assertContains('/next', $handler->seenPaths);

            // an in-page fetch() round-trips through the same loop
            $status = $page->script(sprintf(
                'fetch("%s/api?name=ajax").then(r => r.text())',
                $server->baseUrl(),
            ));
            $this->assertIsString($status);
            $this->assertStringContainsString('Bonjour, ajax', $status);

            $browser->close();
        } finally {
            $session->close();
            $server->close();
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
