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

#[ProgressView(
    key: 'valid',
    description: 'A valid fixture progress view.',
    comment: 'Used to test the happy path.',
    requires: [],
    params: [
        ['name' => 'label', 'type' => 'string', 'required' => true, 'description' => 'A label'],
        ['name' => 'width', 'type' => 'int', 'required' => false, 'default' => 80, 'description' => 'Width'],
    ],
)]
final readonly class ValidProgressView implements ProgressViewContract
{
    public function __construct(
        public mixed $stream,
        public string $label,
        public int $width = 80,
    ) {}

    public function handle(Envelope $envelope): void {}
}
