<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use AllowDynamicProperties;
use LucianoPereira\Crucible\Framework\TestCase;

/**
 * The default binding target for pest-dialect closures when a file
 * declares no uses(): $this inside test() bodies is a plain Crucible
 * TestCase, so the assert* surface and expectException machinery are
 * in reach the way the pest spec promises. Dynamic properties are
 * allowed because sharing state via `$this->foo = ...` in beforeEach
 * is the dialect's documented idiom. Deliberately not final:
 * pest()->use(Trait::class) composes traits by extending the binding
 * class (TraitComposer).
 */
#[AllowDynamicProperties]
class PestTestCase extends TestCase {}
