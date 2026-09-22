<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Opt-in bootstrap for the PHPUnit-namespace compatibility layer.
 * The coexistence policy (auto drop-in vs explicit migration mode)
 * lives in PhpUnitCompatibility; requiring this file loads the
 * aliases unconditionally — used by bootstraps that know what they
 * want, e.g. the conformance harness.
 */

use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;

PhpUnitCompatibility::load();
