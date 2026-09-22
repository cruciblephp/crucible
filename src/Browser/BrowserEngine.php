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
 * The browser a test drives, in the Pest spec's vocabulary
 * (chrome/firefox/safari). Each maps onto the Playwright browser
 * type that implements it — "safari" is Playwright's WebKit build,
 * exactly as in the incumbent.
 */
enum BrowserEngine: string
{
    case Chrome = 'chrome';

    case Firefox = 'firefox';

    case Safari = 'safari';

    /**
     * The key under which the Playwright root object's initializer
     * carries this engine's BrowserType guid.
     */
    public function playwrightType(): string
    {
        return match ($this) {
            self::Chrome  => 'chromium',
            self::Firefox => 'firefox',
            self::Safari  => 'webkit',
        };
    }
}
