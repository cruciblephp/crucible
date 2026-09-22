<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert;

use RuntimeException;

/**
 * XML that could not be parsed at all.
 *
 * Deliberately not an AssertionFailedError: unparseable input is not the
 * assertion answering "no", it is the assertion being unable to ask.
 * The distinction is observable — it ends the test as an error rather
 * than a failure, which is what the incumbent does too.
 */
final class XmlException extends RuntimeException {}
