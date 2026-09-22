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
 * Thrown by markTestSkipped() and by unmet #[Requires*] requirements;
 * classified by the runner as Outcome::Skipped.
 */
final class SkippedTestError extends RuntimeException implements Exception {}
