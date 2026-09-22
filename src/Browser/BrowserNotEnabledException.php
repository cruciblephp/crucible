<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

use LucianoPereira\Crucible\Exceptions\Exception;
use RuntimeException;

/**
 * A browser session was requested while the tier is off. The browser
 * tier is never active by default — not even when the suite contains
 * browser tests — so the error names the exact config line that
 * enables it. Never a silent skip: a test that never runs and never
 * tells is worse than an error. (Explicitly disabling with
 * ->browser(enabled: false) is the deterministic-skip state instead.)
 */
final class BrowserNotEnabledException extends RuntimeException implements Exception {}
