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
 * The Playwright driver broke the wire contract (short frame, invalid
 * JSON, an error reply, or an unexpected exit). The message carries
 * the driver's own words plus its stderr tail when the process died —
 * the diagnostics a user needs to tell "my selector is wrong" from
 * "the driver crashed".
 */
final class BrowserProtocolException extends RuntimeException implements Exception {}
