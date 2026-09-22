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
use LucianoPereira\Crucible\Browser\InertiaRecorder;
use LucianoPereira\Crucible\Browser\Playwright\Page;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Browser\Server\HttpResponse;
use LucianoPereira\Crucible\Browser\Server\InProcessServer;
use LucianoPereira\Crucible\Browser\Server\RequestHandler;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Inertia awareness proven against the **real `@inertiajs/core`** —
 * bundled from the package itself into `inertia-oracle/`, the way
 * `browser-oracle/` holds a real Playwright. A hand-written page that
 * merely dispatched `inertia:navigate` would test the imitation, and
 * the interesting failures all live in the library's actual behavior:
 * that a client-side visit never updates `data-page`, and that the only
 * honest source of the current page is the event the library fires.
 */
#[CoversClass(Page::class)]
#[CoversClass(InertiaRecorder::class)]
final class InertiaTest extends TestCase
{
    private static ?Session $session = null;

    private static ?InProcessServer $server = null;

    public static function tearDownAfterClass(): void
    {
        self::$server?->close();
        self::$session?->close();
        self::$server  = null;
        self::$session = null;
    }

    public function testTheServerRenderedPageIsReadFromTheDom(): void
    {
        $page = $this->visit();

        $page->assertInertiaComponent('Home/Index');
        $page->assertInertiaProp('user.name', 'Ada');
        $page->assertInertiaProp('count', 2);
    }

    public function testAClientSideVisitIsSeenThoughDataPageNeverChanges(): void
    {
        // The case that makes the recorder necessary. Inertia swaps the
        // page in its own adapter; the server-rendered `data-page`
        // attribute still says Home/Index afterwards, so anything
        // reading only the DOM would assert the wrong component.
        $page = $this->visit();

        $page->script('window.Inertia.router.visit("/users")');
        $page->waitForNetworkIdle(0.3, 5.0);

        $page->assertInertiaComponent('Users/Index');
        $page->assertInertiaProp('users.0.name', 'Grace');

        $page->assertScript(
            'JSON.parse(document.getElementById("app").getAttribute("data-page")).component',
            'Home/Index',
        );
    }

    public function testANonInertiaPageIsNamedNotMistakenForAFailedMatch(): void
    {
        $page = $this->visit('/plain');

        $this->assertNull($page->inertiaPage());
    }

    private function visit(string $path = '/'): Page
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';
        $repo = dirname(__DIR__, 3);

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).');
        }

        if (!is_file($repo . '/inertia-oracle/inertia.js')) {
            $this->markTestSkipped('No Inertia oracle built — see ORACLES.md.');
        }

        self::$server ??= new InProcessServer(new InertiaApp($repo . '/inertia-oracle/inertia.js'));
        self::$session ??= Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));
        self::$session->watch(self::$server);

        $context = self::$session->launch()->newContext();
        $context->addInitScript(InertiaRecorder::script());

        return $context->newPage()->navigate(self::$server->baseUrl() . $path);
    }
}

/**
 * The smallest server that speaks Inertia's actual protocol: an HTML
 * shell carrying the initial page in `data-page`, and an `X-Inertia`
 * JSON response for a client-side visit.
 */
final readonly class InertiaApp implements RequestHandler
{
    public function __construct(private string $bundle) {}

    public function handle(HttpRequest $request): HttpResponse
    {
        return match ($request->path()) {
            '/inertia.js' => new HttpResponse(
                (string) file_get_contents($this->bundle),
                200,
                'application/javascript',
            ),
            '/users' => $this->users($request),
            '/plain' => HttpResponse::html('<!doctype html><html lang="en"><head><title>P</title></head><body><h1>plain</h1></body></html>'),
            default  => $this->home(),
        };
    }

    private function users(HttpRequest $request): HttpResponse
    {
        $page = [
            'component' => 'Users/Index',
            'props'     => ['users' => [['name' => 'Grace'], ['name' => 'Alan']]],
            'url'       => '/users',
            'version'   => '1',
        ];

        // Inertia only treats a response as a visit when it says so.
        if ($request->header('x-inertia') !== null) {
            return new HttpResponse(
                json_encode($page, JSON_THROW_ON_ERROR),
                200,
                'application/json',
                ['X-Inertia' => 'true', 'Vary' => 'X-Inertia'],
            );
        }

        return HttpResponse::html('<!doctype html><html lang="en"><body>direct</body></html>');
    }

    private function home(): HttpResponse
    {
        $page = json_encode([
            'component' => 'Home/Index',
            'props'     => ['user' => ['name' => 'Ada'], 'count' => 2],
            'url'       => '/',
            'version'   => '1',
        ], JSON_THROW_ON_ERROR);

        return HttpResponse::html(sprintf(
            '<!doctype html><html lang="en"><head><title>Inertia</title></head><body>'
            . '<div id="app" data-page=\'%s\'></div>'
            . '<script src="/inertia.js"></script>'
            . '<script>'
            . 'const initial = JSON.parse(document.getElementById("app").getAttribute("data-page"));'
            . 'window.Inertia.router.init({'
            . '  initialPage: initial,'
            . '  resolveComponent: (name) => Promise.resolve(name),'
            . '  swapComponent: async ({ component }) => {'
            . '    document.getElementById("app").setAttribute("data-rendered", component);'
            . '  },'
            . '});'
            . '</script></body></html>',
            $page,
        ));
    }
}
