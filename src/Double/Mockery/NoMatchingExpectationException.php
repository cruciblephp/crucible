<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

/**
 * A configured method was called with arguments no expectation
 * matches — thrown at call time (spec/mockery-api.md §3, probe-pinned
 * message shape).
 */
final class NoMatchingExpectationException extends MockeryException {}
