<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

use LucianoPereira\Crucible\Browser\Playwright\Browser;
use LucianoPereira\Crucible\Browser\Playwright\BrowserContext;
use LucianoPereira\Crucible\Browser\Playwright\Page;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Browser\Server\InProcessServer;
use LucianoPereira\Crucible\Browser\Server\RequestHandler;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use LucianoPereira\Crucible\Test\TestId;
use RuntimeException;
use Throwable;

use function class_exists;
use function is_array;
use function is_dir;
use function mkdir;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * The ambient browser runtime behind `visit()` — the Snapshots
 * pattern (D-042): one static context the runner opens and closes
 * around every test attempt, because a test body cannot be handed
 * parameters. One driver session and one launched browser per
 * process (launching is the expensive part); one fresh context per
 * visit (the isolation unit); every context closed when its test
 * ends. The default-off gate stays where it always was —
 * Session::start — this class only adds the dialect-facing states:
 * explicitly-disabled means SKIP here, not error.
 */
final class Browsing
{
    private static ?BrowserConfiguration $configuration = null;

    private static ?WorkingDirectory $workingDirectory = null;
    /**
     * The configured directory, or the '/' the property used to default
     * to. A static property cannot hold a `new`, so the default moved
     * here rather than changing what an unconfigured read answers.
     */
    private static function workingDirectory(): WorkingDirectory
    {
        return self::$workingDirectory ??= new WorkingDirectory('/');
    }


    private static ?Session $session = null;

    private static ?Browser $browser = null;

    private static ?InProcessServer $server = null;

    /** @var list<BrowserContext> */
    private static array $testContexts = [];

    private static ?Page $lastPage = null;

    /** Why the last failure screenshot was not written; see below. */
    private static ?Throwable $lastScreenshotFailure = null;

    public static function configure(BrowserConfiguration $configuration, WorkingDirectory $workingDirectory): void
    {
        self::$configuration    = $configuration;
        self::$workingDirectory = $workingDirectory;
    }

    /** The ambient configuration — tests that reconfigure restore it. */
    public static function configured(): ?BrowserConfiguration
    {
        return self::$configuration;
    }

    /** Opens the per-test window (runner-owned, like Snapshots). */
    public static function begin(): void
    {
        self::$testContexts = [];
        self::$lastPage     = null;
    }

    /** Closes every context the test opened. */
    public static function end(): void
    {
        foreach (self::$testContexts as $context) {
            try {
                $context->close();
            } catch (Throwable) {
                // the browser may already be gone; teardown stays quiet
            }
        }

        self::$testContexts = [];
        self::$lastPage     = null;
    }

    /**
     * The dialect entry point: a navigated page in a fresh context —
     * or, for a URL list, one page per URL behind the fan-out surface
     * (D-064).
     *
     * @param list<non-empty-string>|string $url
     *
     * @return ($url is string ? Page : PageCollection)
     */
    public static function visit(array|string $url, ?ContextOptions $options = null): Page|PageCollection
    {
        if (is_array($url)) {
            $pages = [];

            foreach ($url as $one) {
                $pages[] = self::visit($one, $options);
            }

            if ($pages === []) {
                throw new BrowserNotEnabledException('visit([]): the URL list is empty.');
            }

            return new PageCollection($pages);
        }

        $configuration = self::$configuration ?? new BrowserConfiguration();

        if ($configuration->enabled === false) {
            throw new SkippedTestError('The browser tier is explicitly disabled (->browser(enabled: false)).');
        }

        $relative = !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && !str_starts_with($url, 'data:');

        if ($relative && $configuration->requestHandler === null) {
            throw new BrowserNotEnabledException(sprintf(
                "visit('%s'): relative URLs resolve against the in-process application server — configure ->browser(requestHandler: YourHandler::class) (the Laravel bridge ships one). Until then, visit absolute URLs.",
                $url,
            ));
        }

        // The server ideally exists BEFORE the session so the driver's
        // select loop watches it from the first frame (D-057); when a
        // session already runs (an earlier absolute visit, another
        // test), the transport late-watches it instead.
        if ($configuration->requestHandler !== null && !self::$server instanceof InProcessServer) {
            self::$server = new InProcessServer(self::handler($configuration->requestHandler));
            self::$session?->watch(self::$server);
        }

        self::$session ??= Session::start($configuration, self::workingDirectory(), self::$server);
        self::$browser ??= self::$session->launch();

        if ($relative && self::$server instanceof InProcessServer) {
            $url = rtrim(self::$server->baseUrl(), '/') . (str_starts_with($url, '/') ? $url : '/' . $url);
        }

        $context              = self::$browser->newContext($options);
        self::$testContexts[] = $context;

        $page           = $context->newPage()->navigate($url);
        self::$lastPage = $page;

        return $page;
    }

    /**
     * Resolves the configured handler class — every misconfiguration
     * is a named error at first use, never a silent 404.
     *
     * @param non-empty-string $class
     */
    private static function handler(string $class): RequestHandler
    {
        if (!class_exists($class)) {
            throw new BrowserNotEnabledException(sprintf(
                '->browser(requestHandler: %s) names a class that does not exist.',
                $class,
            ));
        }

        $handler = new $class();

        if (!$handler instanceof RequestHandler) {
            throw new BrowserNotEnabledException(sprintf(
                '->browser(requestHandler: %s): the handler must implement %s.',
                $class,
                RequestHandler::class,
            ));
        }

        return $handler;
    }

    /**
     * A failure artifact from the test's most recent page, saved
     * under tests/Browser/Screenshots — the incumbent's location.
     * Null when the test never visited anything.
     */
    public static function failureScreenshot(TestId $test): ?string
    {
        self::$lastScreenshotFailure = null;
        $page                        = self::$lastPage;

        if (!$page instanceof Page) {
            self::$lastScreenshotFailure = new RuntimeException('no page was visited by this test');

            return null;
        }

        $directory = self::workingDirectory()->path . '/tests/Browser/Screenshots';

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true)) {
            self::$lastScreenshotFailure = new RuntimeException('could not create ' . $directory);

            return null;
        }

        $slug = trim((string) preg_replace('/[^\w-]+/', '_', $test->name), '_');

        try {
            return $page->screenshotTo(sprintf('%s/%s.png', $directory, $slug === '' ? 'test' : $slug));
        } catch (Throwable $dead) {
            // A dead page must not mask the real failure — but the
            // reason must not vanish with it either. Returning a bare
            // null left a missing screenshot with nothing to diagnose:
            // a driver that died under load and a screenshot directory
            // that could not be created looked identical from here.
            self::$lastScreenshotFailure = $dead;

            return null;
        }
    }

    /**
     * Why the last failure screenshot was not written, or null when one
     * was.
     *
     * Kept as the Throwable rather than a message, because the class is
     * the part that separates "the browser session died" — a
     * BrowserProtocolException, and a precondition rather than a defect
     * — from a screenshot path this engine genuinely got wrong.
     */
    public static function lastScreenshotFailure(): ?Throwable
    {
        return self::$lastScreenshotFailure;
    }

    /** Ends the process-wide session (shutdown / between runs). */
    public static function shutdown(): void
    {
        try {
            self::$browser?->close();
            self::$session?->close();
            self::$server?->close();
        } catch (Throwable) {
            // shutdown stays quiet
        }

        self::$browser = null;
        self::$session = null;
        self::$server  = null;
    }
}
