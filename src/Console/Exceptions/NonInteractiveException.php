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

/**
 * Thrown when an interactive prompt is required but the environment is not a
 * TTY and no non-interactive fallback value is available.
 *
 * Raised by {@see \LucianoPereira\Crucible\Console\Runtime\Program::run()}
 * and caught at the top of the CLI, which prints the message and exits 1. A
 * prompt says for itself whether it has a fallback: see
 * {@see \LucianoPereira\Crucible\Console\Components\Prompt::unanswerableWithoutTerminal()}.
 */
final class NonInteractiveException extends RuntimeException {}
