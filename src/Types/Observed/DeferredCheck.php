<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use RuntimeException;

/**
 * A check that needed a class not loaded yet, put off until the suite is over.
 *
 * @internal
 */
final class DeferredCheck extends RuntimeException {}
