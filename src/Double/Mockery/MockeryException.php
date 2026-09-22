<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

use LucianoPereira\Crucible\Exceptions\Exception;
use RuntimeException;

/**
 * The Mockery grammar's base error — aliased to \Mockery\Exception so
 * suites catching by the upstream name keep working (D-060 /
 * spec/mockery-api.md §13).
 */
class MockeryException extends RuntimeException implements Exception {}
