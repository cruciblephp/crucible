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
use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\Browsing;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Test\TestId;
use RuntimeException;

use function dirname;
use function file_exists;
use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The ambient runtime behind visit(): the three-state gate at the
 * dialect tier (disabled = SKIP), relative-URL refusal, and the
 * failure-screenshot artifact.
 */
#[CoversClass(Browsing::class)]
final class BrowsingTest extends TestCase
{
    public function testExplicitlyDisabledMeansSkip(): void
    {
        Browsing::configure(new BrowserConfiguration(enabled: false), new WorkingDirectory('/tmp'));
        Browsing::begin();

        try {
            Browsing::visit('https://example.test/');
            $this->fail('a disabled tier should skip the visiting test');
        } catch (SkippedTestError $e) {
            // caught by hand: the runner honors a thrown skip over
            // expectException, which is exactly the behavior under test
            $this->assertStringContainsString('explicitly disabled', $e->getMessage());
        } finally {
            Browsing::end();
            $this->restoreSuiteConfiguration();
        }
    }

    public function testRelativeUrlsAreANamedRefusal(): void
    {
        Browsing::configure(new BrowserConfiguration(enabled: true), new WorkingDirectory('/tmp'));
        Browsing::begin();

        try {
            $this->expectException(BrowserNotEnabledException::class);
            $this->expectExceptionMessage('relative URLs');

            Browsing::visit('/dashboard');
        } finally {
            Browsing::end();
            $this->restoreSuiteConfiguration();
        }
    }

    public function testFailureScreenshotIsNullWithoutAVisit(): void
    {
        Browsing::begin();

        $this->assertNull(Browsing::failureScreenshot(new TestId('t.php', 'name')));

        Browsing::end();
    }

    public function testFailureScreenshotWritesThePng(): void
    {
        $root = $this->playwrightRootOrSkip();
        $work = sys_get_temp_dir() . '/crucible-browsing-' . uniqid();

        Browsing::configure(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($work));
        Browsing::begin();

        try {
            Browsing::visit('data:text/html,<title>Shot</title><h1>x</h1>');
            $path = Browsing::failureScreenshot(new TestId('t.php', 'it saves a shot'));

            // ✓ Measured 2026-09-16: the intermittent failure here was
            // NOT the connection dying — it answered
            // `Protocol error (Page.captureScreenshot): Unable to
            // capture screenshot`, which is Chromium declining to paint
            // a frame under load through a live session. Page::screenshot()
            // retries that case now, so this path is the backstop for
            // when the retries are also starved, and no longer the
            // expected outcome of a loaded run.
            if ($path === null) {
                $why = Browsing::lastScreenshotFailure();

                if ($why instanceof BrowserProtocolException) {
                    $this->markTestSkipped('the browser could not paint a frame: ' . $why->getMessage());
                }

                $this->fail('no screenshot was written: ' . ($why?->getMessage() ?? 'no reason was recorded'));
            }

            $this->assertTrue(file_exists($path));
            $this->assertStringContainsString('tests/Browser/Screenshots/it_saves_a_shot.png', $path);
            $this->assertTrue(str_starts_with((string) file_get_contents($path), "\x89PNG"));

            unlink($path);
        } finally {
            Browsing::end();
            $this->restoreSuiteConfiguration();
        }
    }

    public function testAMissingScreenshotSaysWhyAndOnlyADeadSessionIsAPrecondition(): void
    {
        $work = sys_get_temp_dir() . '/crucible-browsing-' . uniqid();

        Browsing::configure(new BrowserConfiguration(enabled: true, playwrightRoot: $work), new WorkingDirectory($work));
        Browsing::begin();

        try {
            // Nothing was visited, so there is no page to shoot. The
            // engine still returns null — a dead page must never mask
            // the real failure — but the reason no longer vanishes with
            // it: a missing screenshot used to be indistinguishable
            // from a screenshot directory that could not be created.
            self::assertNull(Browsing::failureScreenshot(new TestId('t.php', 'nothing visited')));

            $why = Browsing::lastScreenshotFailure();
            self::assertInstanceOf(RuntimeException::class, $why);
            self::assertStringContainsString('no page was visited', $why->getMessage());

            // And it is NOT a protocol failure, which is the whole
            // discrimination: only a session that died is a missing
            // precondition. Everything else is this engine answering
            // wrongly, and stays a failure.
            self::assertNotInstanceOf(BrowserProtocolException::class, $why);
        } finally {
            Browsing::end();
            $this->restoreSuiteConfiguration();
        }
    }

    private function restoreSuiteConfiguration(): void
    {
        // Put the ambient state back the way the Application wired it
        // for this very suite run — these tests reconfigure a global.
        Browsing::configure(
            new BrowserConfiguration(enabled: true, playwrightRoot: dirname(__DIR__, 3) . '/browser-oracle'),
            new WorkingDirectory(dirname(__DIR__, 3)),
        );
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
