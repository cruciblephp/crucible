<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Property;

use RuntimeException;

/**
 * A generator gave up on the current choice stream — a suchThat()
 * filter ran out of attempts. The property runner discards the case
 * and draws a fresh one; the shrinker treats the candidate as
 * invalid. Never a test outcome by itself.
 */
final class CannotGenerate extends RuntimeException {}
