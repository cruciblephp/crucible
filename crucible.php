<?php

declare(strict_types=1);
/*
 * Crucible's own test suite runs on Crucible (self-hosting since Phase 4).
 */

use LucianoPereira\Crucible\Configuration\Crucible;

return Crucible::configure()
    ->bootstrap('vendor/autoload.php')
    ->testSuite('unit', 'tests/unit')
    // The documented examples are executable: they run in the ordinary
    // suite, so every example in MANUAL.md is proven rather than asserted.
    ->testSuite('examples', 'examples')
    ->source(include: ['src'])
    // The browser tier, pointed at the gitignored oracle install;
    // tests that visit() skip themselves when it is absent.
    ->browser(playwrightRoot: __DIR__ . '/browser-oracle');
