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
 * A count expectation settled unmet at close (every count violation
 * settles at close in this grammar — oracle-pinned, spec §5).
 *
 * Extends the Mockery exception taxonomy, NOT AssertionFailedError:
 * the conformance lane proved (D-066) that the incumbent stack —
 * real mockery 1.6.12 on PHPUnit 13, integration trait included —
 * settles an unmet expectation as an ERRORED test, never a failure.
 * D-060 guessed failure; the oracle overruled it.
 */
final class InvalidCountException extends MockeryException {}
