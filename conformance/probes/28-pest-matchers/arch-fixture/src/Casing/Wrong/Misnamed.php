<?php

declare(strict_types=1);

namespace ArchFixture\Casing\Wrong;

/**
 * ✓ Measured 2026-09-07: NEITHER engine sees this. PSR-4 is case-sensitive
 * on the path, so `misnamed` cannot be autoloaded from `Misnamed.php` and
 * pest's layer for this namespace is empty too. See compare-arch.php's
 * UNPROVABLE list.
 */
final class misnamed {}
