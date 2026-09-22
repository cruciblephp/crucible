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
 * The tier is enabled but the Playwright CLI is not where the
 * configuration points. The message carries the exact commands to
 * run; nothing is ever downloaded or installed automatically —
 * ~150MB of browsers is the user's explicit decision.
 */
final class PlaywrightNotInstalledException extends RuntimeException implements Exception {}
