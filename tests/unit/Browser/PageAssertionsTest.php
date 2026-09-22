<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\Playwright\Page;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function fclose;
use function fsockopen;
use function getenv;
use function is_file;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_int;
use function range;
use function rawurlencode;
use function sprintf;
use function usleep;

/**
 * The B4 assertion surface against a real page, in one session:
 * every family exercised on its passing side, one failing probe per
 * mechanism proving failures land in the assertion machinery.
 */
#[CoversClass(Page::class)]
final class PageAssertionsTest extends TestCase
{
    private static ?Session $session = null;

    public function testTheAssertionSurfaceAgainstARealPage(): void
    {
        $page = $this->pageOrSkip();

        // title + text
        $page->assertTitle('Assertions')
            ->assertTitleContains('Assert')
            ->assertSee('Welcome aboard')
            ->assertDontSee('Nowhere Text')
            ->assertSeeIn('.box', 'Boxed')
            ->assertDontSeeIn('.box', 'Welcome')
            ->assertSeeAnythingIn('.box')
            ->assertSeeNothingIn('#empty')
            ->assertCount('li', 3);

        // script + source + links
        $page->assertScript('1 + 2', 3)
            ->assertScript('document.querySelector("#terms").checked', false)
            ->assertSourceHas('data-test="mail"')
            ->assertSourceMissing('nonexistent-marker')
            ->assertSeeLink('About Us')
            ->assertDontSeeLink('Hidden Link');

        // form state
        $page->assertValue('email', 'init')
            ->assertValueIsNot('email', 'other')
            ->assertNotChecked('terms')
            ->check('terms')->assertChecked('terms')
            ->assertRadioSelected('tier', 'pro')
            ->assertRadioNotSelected('tier', 'free')
            ->assertSelected('plan', 'b')
            ->assertNotSelected('plan', 'a')
            ->assertIndeterminate('#half');

        // attributes
        $page->assertAttribute('.box', 'data-role', 'greeting')
            ->assertAttributeMissing('.box', 'data-nope')
            ->assertAttributeContains('.box', 'class', 'box')
            ->assertAttributeDoesntContain('.box', 'class', 'panel')
            ->assertAriaAttribute('.box', 'label', 'the box')
            ->assertDataAttribute('.box', 'role', 'greeting');

        // presence + interactability
        $page->assertVisible('.box')
            ->assertMissing('#hidden')
            ->assertPresent('#hidden')
            ->assertNotPresent('.never-was')
            ->assertEnabled('email')
            ->assertDisabled('frozen')
            ->assertButtonEnabled('Go')
            ->assertButtonDisabled('Stop');

        // URL family — needs a real http origin (data: has no host/path shape)
        [$server, $port] = $this->localServer();

        try {
            $page->navigate(sprintf('http://127.0.0.1:%d/shop/cart?item=7&promo#checkout', $port));
            $page->assertUrlIs('/shop/cart')
                ->assertPathIs('/shop/cart')
                ->assertPathIsNot('/other')
                ->assertPathBeginsWith('/shop')
                ->assertPathEndsWith('/cart')
                ->assertPathContains('op/ca')
                ->assertSchemeIs('http')
                ->assertSchemeIsNot('ftp')
                ->assertHostIs('127.0.0.1')
                ->assertHostIsNot('localhost')
                ->assertPortIs((string) $port)
                ->assertPortIsNot('81')
                ->assertQueryStringHas('item', '7')
                ->assertQueryStringHas('promo')
                ->assertQueryStringMissing('coupon')
                ->assertFragmentIs('checkout')
                ->assertFragmentBeginsWith('check')
                ->assertFragmentIsNot('cart');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function testFailuresLandInTheAssertionMachinery(): void
    {
        $page = $this->pageOrSkip();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected to see [Not There] on the page.');

        $page->assertSee('Not There');
    }

    public static function tearDownAfterClass(): void
    {
        self::$session?->close();
        self::$session = null;
    }

    /**
     * Starts the built-in PHP server on a free port with the fixture
     * router, waits until it accepts, returns [process, port].
     *
     * @return array{resource, int}
     */
    private function localServer(): array
    {
        $router = dirname(__DIR__, 2) . '/_fixtures/browser-router.php';

        foreach ([0, 1, 2] as $attempt) {
            $port   = random_int(49_200, 64_000) + $attempt;
            $pipes  = [];
            $server = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
                [2 => ['pipe', 'w']],
                $pipes,
            );

            if (!is_resource($server)) {
                continue;
            }

            foreach (range(1, 50) as $poll) {
                $probe = @fsockopen('127.0.0.1', $port, timeout: 0.1);

                if ($probe !== false) {
                    fclose($probe);

                    return [$server, $port];
                }

                usleep(50_000);

                if (!proc_get_status($server)['running']) {
                    break; // port taken — try the next one
                }
            }

            proc_terminate($server);
            proc_close($server);
        }

        $this->fail('Could not start the local test server.');
    }

    private function pageOrSkip(): Page
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).');
        }

        self::$session ??= Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        $html = rawurlencode(<<<'HTML'
            <title>Assertions</title>
            <h1>Welcome aboard</h1>
            <input name="email" data-test="mail" value="init">
            <input type="checkbox" id="terms" name="terms">
            <input type="checkbox" id="half">
            <input type="radio" name="tier" value="free">
            <input type="radio" name="tier" value="pro" checked>
            <select name="plan"><option value="a">Alpha</option><option value="b" selected>Beta</option></select>
            <input name="frozen" disabled>
            <button>Go</button>
            <button disabled>Stop</button>
            <div class="box" data-role="greeting" aria-label="the box">Boxed Text</div>
            <div id="empty"></div>
            <div id="hidden" style="display:none">ghost</div>
            <ul><li>a</li><li>b</li><li>c</li></ul>
            <a href="/about">About Us</a>
            <script>document.querySelector('#half').indeterminate = true;</script>
            HTML);

        return self::$session->launch()->newContext()->newPage()->navigate('data:text/html,' . $html);
    }
}
