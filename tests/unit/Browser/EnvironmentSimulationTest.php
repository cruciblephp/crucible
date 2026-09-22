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
use LucianoPereira\Crucible\Browser\City;
use LucianoPereira\Crucible\Browser\ContextOptions;
use LucianoPereira\Crucible\Browser\Device;
use LucianoPereira\Crucible\Browser\Playwright\Browser;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function getenv;
use function is_file;
use function is_string;

/**
 * B5 against a real browser: one context per emulated fact, each
 * verified through what the page itself observes.
 */
#[CoversClass(Browser::class)]
#[CoversClass(Device::class)]
#[CoversClass(City::class)]
#[CoversClass(ContextOptions::class)]
final class EnvironmentSimulationTest extends TestCase
{
    public function testDeviceCityDarkModeAndOverrides(): void
    {
        $root = $this->playwrightRootOrSkip();

        $session = Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        try {
            $browser = $session->launch();

            // registry device: real descriptor incl. user agent
            $iphone = $browser->newContext(new ContextOptions(device: Device::IPhone14Pro))->newPage();
            $iphone->navigate('data:text/html,<meta name="viewport" content="width=device-width"><title>D</title>ok');
            $this->assertSame(393, $iphone->script('innerWidth'));
            $userAgent = $iphone->script('navigator.userAgent');
            $this->assertIsString($userAgent);
            $this->assertStringContainsString('iPhone', $userAgent);
            $this->assertSame(true, $iphone->script('navigator.maxTouchPoints > 0'));

            // Crucible-defined device: viewport truth, no invented identity
            $mac = $browser->newContext(new ContextOptions(device: Device::MacBook14))->newPage();
            $mac->navigate('data:text/html,<title>M</title>ok');
            $this->assertSame(1_512, $mac->script('innerWidth'));
            $this->assertSame(2, $mac->script('devicePixelRatio'));

            // city preset: timezone + locale + geolocation together
            $paris = $browser->newContext(new ContextOptions(from: City::Paris))->newPage();
            $paris->navigate('data:text/html,<title>P</title>ok');
            $this->assertSame('Europe/Paris', $paris->script('Intl.DateTimeFormat().resolvedOptions().timeZone'));
            $this->assertSame('fr-FR', $paris->script('navigator.language'));

            // dark mode + explicit overrides beat the city preset
            $dark = $browser->newContext(new ContextOptions(
                from: City::Paris,
                darkMode: true,
                locale: 'ja-JP',
                timezone: 'Asia/Tokyo',
                userAgent: 'CrucibleProbe/1.0',
            ))->newPage();
            $dark->navigate('data:text/html,<title>K</title>ok');
            $this->assertSame(true, $dark->script('matchMedia("(prefers-color-scheme: dark)").matches'));
            $this->assertSame('ja-JP', $dark->script('navigator.language'));
            $this->assertSame('Asia/Tokyo', $dark->script('Intl.DateTimeFormat().resolvedOptions().timeZone'));
            $this->assertSame('CrucibleProbe/1.0', $dark->script('navigator.userAgent'));

            $browser->close();
        } finally {
            $session->close();
        }
    }

    public function testEveryPresetProducesAContext(): void
    {
        $root = $this->playwrightRootOrSkip();

        $session = Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        try {
            $browser = $session->launch();

            foreach (Device::cases() as $device) {
                $page = $browser->newContext(new ContextOptions(device: $device))->newPage();
                $page->navigate('data:text/html,<title>ok</title>');
                $this->assertSame('ok', $page->title());
            }

            foreach (City::cases() as $city) {
                $simulation = $city->simulation();
                $page       = $browser->newContext(new ContextOptions(from: $city))->newPage();
                $page->navigate('data:text/html,<title>ok</title>');
                $this->assertSame($simulation['timezone'], $page->script('Intl.DateTimeFormat().resolvedOptions().timeZone'));
            }

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
