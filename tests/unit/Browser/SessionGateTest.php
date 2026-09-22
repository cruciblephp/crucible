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
use LucianoPereira\Crucible\Browser\BrowserEngine;
use LucianoPereira\Crucible\Browser\BrowserNotEnabledException;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Browser\PlaywrightNotInstalledException;
use LucianoPereira\Crucible\Configuration\Builder;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function sys_get_temp_dir;

#[CoversClass(Session::class)]
#[CoversClass(BrowserConfiguration::class)]
final class SessionGateTest extends TestCase
{
    public function testTheTierIsOffByDefault(): void
    {
        $configuration = (new Builder())->testSuite('unit', 'tests')->build();

        $this->assertNull($configuration->browser->enabled);
    }

    public function testDefaultStateIsANamedErrorNamingTheConfigLine(): void
    {
        $this->expectException(BrowserNotEnabledException::class);
        $this->expectExceptionMessage('->browser()');

        Session::start(new BrowserConfiguration(), new WorkingDirectory('/tmp'));
    }

    public function testExplicitlyDisabledIsItsOwnNamedState(): void
    {
        $this->expectException(BrowserNotEnabledException::class);
        $this->expectExceptionMessage('explicitly disabled');

        Session::start(new BrowserConfiguration(enabled: false), new WorkingDirectory('/tmp'));
    }

    public function testEnabledWithoutPlaywrightNamesTheExactCommands(): void
    {
        $this->expectException(PlaywrightNotInstalledException::class);
        $this->expectExceptionMessage('npm install playwright');

        Session::start(new BrowserConfiguration(enabled: true), new WorkingDirectory(sys_get_temp_dir() . '/crucible-no-playwright-here'));
    }

    public function testBuilderCarriesTheBrowserBlock(): void
    {
        $configuration = (new Builder())
            ->testSuite('unit', 'tests')
            ->browser(engine: BrowserEngine::Firefox, timeoutMs: 10_000)
            ->build();

        $this->assertTrue($configuration->browser->enabled);
        $this->assertSame(BrowserEngine::Firefox, $configuration->browser->engine);
        $this->assertSame(10_000, $configuration->browser->timeoutMs);
    }

    public function testEngineMapsToPlaywrightTypes(): void
    {
        $this->assertSame('chromium', BrowserEngine::Chrome->playwrightType());
        $this->assertSame('firefox', BrowserEngine::Firefox->playwrightType());
        $this->assertSame('webkit', BrowserEngine::Safari->playwrightType());
    }
}
