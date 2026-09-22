<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use LucianoPereira\Crucible\Exceptions\Exception;
use RuntimeException;

/**
 * The target type cannot be doubled (final class, unsupported
 * construct) or the double cannot be built. The limits are documented
 * honestly, the Kahlan way: final classes cannot be extended, enums
 * and readonly-final value objects should be used directly.
 */
final class DoubleCreationException extends RuntimeException implements Exception {}
