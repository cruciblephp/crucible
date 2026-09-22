<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

use BadMethodCallException;
use LucianoPereira\Crucible\Exceptions\Exception;

/**
 * An entirely unconfigured method was called on a Mockery-surface
 * mock (spec §1: every call must be configured). Extends the SPL
 * BadMethodCallException because that is what upstream throws and
 * what suites catch.
 */
final class MockeryBadMethodCallException extends BadMethodCallException implements Exception {}
