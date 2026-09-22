<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Exceptions;

use RuntimeException;

/** Thrown when the terminal cannot be driven (e.g. `stty` is unavailable). */
final class TerminalException extends RuntimeException {}
