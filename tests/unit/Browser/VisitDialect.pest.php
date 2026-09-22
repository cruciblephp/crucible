<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * B8: the visit() grammar in the pest dialect — the spec's own
 * example shape (spec/pest-api.md §6), fluent chain and all.
 */

function browserOracleReady(): bool
{
    return \is_file(\dirname(__DIR__, 3) . '/browser-oracle/node_modules/.bin/playwright');
}

\it('may visit a page and chain assertions', function (): void {
    $page = \visit('data:text/html,' . \rawurlencode('<title>Dialect</title><h1>Welcome</h1><button onclick="document.title=\'Pressed\'">Go</button>'));

    $page->assertSee('Welcome')
        ->assertTitle('Dialect')
        ->press('Go')
        ->assertTitle('Pressed');
})->skip(fn(): bool => !\browserOracleReady(), 'No Playwright install available.');

\it('refuses relative urls until a server bridge exists', function (): void {
    \expect(fn(): mixed => \visit('/dashboard'))
        ->toThrow(LucianoPereira\Crucible\Browser\BrowserNotEnabledException::class, 'relative URLs');
})->skip(fn(): bool => !\browserOracleReady(), 'No Playwright install available.');
