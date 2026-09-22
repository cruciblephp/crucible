<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

use Attribute;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;

/**
 * Opts a test (or a whole class) out of the expectation-less-mock
 * advisory (D-046): a mock that ends the test without any expects()
 * normally triggers a notice suggesting a stub instead — this
 * attribute says the mock is intentional.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class AllowMockObjectsWithoutExpectations implements CrucibleAttribute {}
