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
use LucianoPereira\Crucible\Browser\Playwright\Connection;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function getenv;
use function is_file;
use function is_string;
use function rawurlencode;

/**
 * The B2 acceptance path against a real driver: launch -> context ->
 * page -> navigate -> title -> close. Needs a Playwright install; the
 * gitignored browser-oracle reference provides one on dev machines
 * (CRUCIBLE_PLAYWRIGHT_ROOT overrides). Skips deterministically when
 * absent — same policy as the conformance suite's phpunit-main.
 */
#[CoversClass(Session::class)]
#[CoversClass(Connection::class)]
final class DriverSessionTest extends TestCase
{
    public function testDrivesARealBrowserEndToEnd(): void
    {
        $root = $this->playwrightRootOrSkip();

        $session = Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        try {
            $browser = $session->launch();
            $page    = $browser->newContext()->newPage();
            $page->navigate('data:text/html,<title>Crucible-Driven</title><h1>hello</h1>');

            $this->assertSame('Crucible-Driven', $page->title());

            $browser->close();
        } finally {
            $session->close();
        }
    }

    public function testTheInteractionSurfaceAgainstARealPage(): void
    {
        $root = $this->playwrightRootOrSkip();

        $html = rawurlencode(<<<'HTML'
            <title>Interactions</title>
            <input name="email" data-test="mail" value="init">
            <input type="checkbox" id="terms">
            <select name="plan"><option value="a">Alpha</option><option value="b">Beta</option></select>
            <button id="btn" onclick="document.title='Clicked'">Press Me</button>
            <div class="box" data-role="greeting">Boxed Text</div>
            HTML);

        $session = Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        try {
            $browser = $session->launch();
            $page    = $browser->newContext()->newPage();
            $page->navigate('data:text/html,' . $html);

            $page->press('Press Me');
            $this->assertSame('Clicked', $page->title());

            $page->fill('email', 'nuno@laravel.com');
            $this->assertSame('nuno@laravel.com', $page->value('email'));

            $page->append('email', '!');
            $this->assertSame('nuno@laravel.com!', $page->value('email'));

            $page->clear('email');
            $this->assertSame('', $page->value('email'));

            $page->check('terms');
            $this->assertTrue($page->script('document.querySelector("#terms").checked'));
            $page->uncheck('terms');
            $this->assertFalse($page->script('document.querySelector("#terms").checked'));

            $page->select('plan', 'Beta');
            $this->assertSame('b', $page->value('plan'));

            $this->assertSame('Boxed Text', $page->text('.box'));
            $this->assertSame('greeting', $page->attribute('.box', 'data-role'));
            $this->assertNull($page->attribute('.box', 'missing'));

            $page->resize(500, 400);
            $this->assertSame(['w' => 500, 'h' => 400], $page->script('({w: innerWidth, h: innerHeight})'));

            $page->waitFor('@mail');
            $this->assertStringStartsWith('data:text/html', $page->url());
            $this->assertStringContainsString('data-test="mail"', $page->content());

            $browser->close();
        } finally {
            $session->close();
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
