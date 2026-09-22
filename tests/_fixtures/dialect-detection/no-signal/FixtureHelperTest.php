<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

// No top-level class and no top-level Pest calls — neither dialect
// claims this file. It must be skipped silently, not crash.

/** @phpcpd-keep Dialect-detection fixture: loaded by path, never referenced. */
function fixtureHelperAddsOne(int $n): int
{
    return $n + 1;
}
