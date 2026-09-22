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
 * The double exists but is being used against its own specification:
 * configuring a method that was not doubled, or calling an
 * unconfigured method while automatic return values are disabled.
 * The test errors — the mistake is in the test, not the code under
 * test.
 */
final class DoubleConfigurationException extends RuntimeException implements Exception {}
