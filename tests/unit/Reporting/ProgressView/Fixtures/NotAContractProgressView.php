<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\ProgressView\Fixtures;

use LucianoPereira\Crucible\Reporting\ProgressView\ProgressView;

/** Carries the attribute but does NOT implement ProgressViewContract. */
#[ProgressView(key: 'not-a-contract', description: 'Misconfigured — missing the interface.')]
final class NotAContractProgressView {}
