<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

/**
 * The browser tier's typed configuration. Three deterministic states:
 *
 *   - enabled === null  (default) — the tier is OFF; a browser test is
 *     a named error pointing at the config line that enables it.
 *   - enabled === true  — browser tests run; a missing Playwright
 *     install is a named, actionable error. Nothing auto-installs.
 *   - enabled === false — browser tests report as skipped,
 *     deterministically (the CI-lane-without-browsers state).
 *
 * The default state costs nothing: no npm, no downloads, no startup
 * probe — Node is never touched unless a browser session actually
 * starts.
 */
final readonly class BrowserConfiguration
{
    /**
     * @param int<1, max>           $timeoutMs      element/navigation wait budget (spec default: 5s)
     * @param non-empty-string|null $playwrightRoot directory whose node_modules provides the
     *                                              playwright CLI; null = the working directory
     */
    /**
     * @param int<1, max>           $timeoutMs      element/navigation wait budget (spec default: 5s)
     * @param non-empty-string|null $playwrightRoot directory whose node_modules provides the
     *                                              playwright CLI; null = the working directory
     * @param bool                  $headed         run with a visible browser window (the incumbent's
     *                                              --debug posture); headless is the default
     * @param non-empty-string|null $requestHandler class implementing Server\RequestHandler — the
     *                                              in-process server serves relative visit() URLs
     *                                              through it (D-065; the Laravel bridge ships one)
     */
    public function __construct(
        public ?bool $enabled = null,
        public BrowserEngine $engine = BrowserEngine::Chrome,
        public int $timeoutMs = 5_000,
        public ?string $playwrightRoot = null,
        public bool $headed = false,
        public ?string $requestHandler = null,
    ) {}
}
