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
use function getenv;
use function is_file;
use function is_string;
use function rawurlencode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;

/**
 * B7 health checks against real Chromium, semantics as oracle-pinned:
 * assertNoConsoleLogs fails only on log-type entries; JS errors are
 * pageError events, not console.error; accessibility is axe-core
 * loaded from the user's node_modules.
 */
#[CoversClass(Page::class)]
final class HealthChecksTest extends TestCase
{
    private static ?Session $session = null;

    public function testConsoleLogSemantics(): void
    {
        $page = $this->visit('<title>C</title><script>console.warn("w"); console.info("i"); console.error("e");</script>ok');

        // warn/info/error do NOT trip it — the oracle-pinned surprise
        $page->assertNoConsoleLogs()->assertNoJavaScriptErrors()->assertNoSmoke();

        $logging = $this->visit('<title>C2</title><script>console.log("plain entry")</script>ok');

        try {
            $logging->assertNoConsoleLogs();
            $this->fail('console.log should trip assertNoConsoleLogs');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('plain entry', $e->getMessage());
        }

        $logging->assertNoJavaScriptErrors(); // log-type does not make it an error
    }

    public function testJavaScriptErrorSemantics(): void
    {
        $page = $this->visit('<title>J</title><script>throw new Error("boom-sync")</script>ok');

        try {
            $page->assertNoJavaScriptErrors();
            $this->fail('an uncaught throw should trip assertNoJavaScriptErrors');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('boom-sync', $e->getMessage());
        }

        try {
            $page->assertNoSmoke();
            $this->fail('an uncaught throw should trip assertNoSmoke');
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);
        }

        $page->assertNoConsoleLogs(); // a throw is not a console log
    }

    public function testAccessibilityViaAxe(): void
    {
        // This one needs a second package beside Playwright. Without
        // the guard it errors where every sibling skips, which reads as
        // a defect rather than as a missing capability.
        $this->requireAxe();

        $clean = $this->visit('<h1>hi</h1>', '<!doctype html><html lang="en"><head><title>Ok</title></head><body>%s</body></html>');
        $clean->assertNoAccessibilityIssues();

        $broken = $this->visit('<img src="x.png"><input type="text">', '<!doctype html><html><head><title>Bad</title></head><body>%s</body></html>');

        try {
            $broken->assertNoAccessibilityIssues();
            $this->fail('missing lang/alt/label should trip assertNoAccessibilityIssues');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('lang attribute', $e->getMessage());
            $this->assertStringContainsString('dequeuniversity.com', $e->getMessage());
        }
    }

    public function testScreenshotBytesArePng(): void
    {
        $page = $this->visit('<title>S</title><h1>shot</h1>');
        $png  = $page->screenshot();

        $this->assertTrue(str_starts_with($png, "\x89PNG"), 'screenshot should be PNG bytes');
        $this->assertGreaterThan(1_000, strlen($png));
    }

    public static function tearDownAfterClass(): void
    {
        self::$session?->close();
        self::$session = null;
    }

    private function requireAxe(): void
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';

        if (!is_file($root . '/node_modules/axe-core/axe.min.js')) {
            $this->markTestSkipped('No axe-core install available beside Playwright (npm install axe-core in the browser oracle).');
        }
    }

    private function visit(string $body, string $shell = '<!doctype html><html><head></head><body>%s</body></html>'): Page
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).');
        }

        self::$session ??= Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        $html = str_contains($shell, '%s') ? sprintf($shell, $body) : $shell . $body;

        return self::$session->launch()->newContext()->newPage()
            ->navigate('data:text/html,' . rawurlencode($html));
    }
}
