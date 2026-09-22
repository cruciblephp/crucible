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
use LucianoPereira\Crucible\Reporting\ProgressView\{ProgressView, ProgressViewContract};

/** Declares a requirement on a PHP extension that is never loaded, to test unavailability. */
#[ProgressView(
    key: 'missing-ext',
    description: 'Requires a fictional extension.',
    requires: ['ext-totally-not-a-real-extension'],
)]
final readonly class MissingExtensionProgressView implements ProgressViewContract
{
    public function __construct(public mixed $stream) {}

    public function handle(Envelope $envelope): void {}
}
