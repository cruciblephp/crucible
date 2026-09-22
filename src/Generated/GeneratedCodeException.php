<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Generated;

use LucianoPereira\Crucible\Exceptions\Exception;
use RuntimeException;

/**
 * Crucible generated source PHP could not parse.
 *
 * Always a defect in the generator, never in the user's code: the
 * source was assembled here. The original ParseError is the previous
 * exception, and the message carries the numbered source, because a
 * parse error's line number refers to text nobody can otherwise see.
 */
final class GeneratedCodeException extends RuntimeException implements Exception {}
