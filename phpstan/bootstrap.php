<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Analysis-time mirror of the runtime coexistence policy (D-019):
 * PHPStan executes bootstrapFiles, so the same aliases that make a
 * PHPUnit-shaped suite run on Crucible also make it analyzable — the
 * class_alias calls register with PHPStan's reflection.
 *
 * FINDING (D-049): the runtime policy check (Composer's
 * InstalledVersions) is meaningless here — inside phpstan.phar it
 * answers for the phar's own bundled vendor tree and reports
 * phpunit/phpunit installed. At analysis time the only trustworthy
 * signal is loadability: if the real PHPUnit is autoloadable from
 * the analyzed project, aliasing would collide, so we never do it;
 * if it is not, this process is Crucible's drop-in world.
 */

use LucianoPereira\Crucible\Compat\MockeryCompatibility;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;

if (!\class_exists(\PHPUnit\Framework\TestCase::class)) {
    PhpUnitCompatibility::load();
}

// Same loadability rule for the Mockery names (D-060): alias for
// analysis exactly when the real mockery/mockery is not autoloadable.
if (!\class_exists(\Mockery::class)) {
    MockeryCompatibility::load();
}
