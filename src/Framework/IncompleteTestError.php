<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Framework;

use LucianoPereira\Crucible\Exceptions\Exception;
use RuntimeException;

/**
 * Thrown by markTestIncomplete(); classified by the runner as
 * Outcome::Incomplete.
 */
final class IncompleteTestError extends RuntimeException implements Exception {}
