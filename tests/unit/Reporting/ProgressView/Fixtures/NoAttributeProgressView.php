<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\ProgressView\Fixtures;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Reporting\ProgressView\ProgressViewContract;

/** Implements the contract but carries no #[ProgressView] attribute at all. */
final class NoAttributeProgressView implements ProgressViewContract
{
    public function handle(Envelope $envelope): void {}
}
