<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Subscriber\Fixtures;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Reporting\Subscriber\{Subscriber, SubscriberContract};

#[Subscriber(
    key: 'valid',
    description: 'A valid fixture subscriber.',
    comment: 'Used to test the happy path.',
    requires: [],
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output path'],
        ['name' => 'width', 'type' => 'int', 'required' => false, 'default' => 80, 'description' => 'Width'],
    ],
)]
final readonly class ValidSubscriber implements SubscriberContract
{
    public function __construct(
        public string $output,
        public int $width = 80,
    ) {}

    public function handle(Envelope $envelope): void {}
}
