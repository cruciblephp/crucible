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

/** Declares a requirement on a PHP extension that is never loaded, to test unavailability. */
#[Subscriber(
    key: 'missing-ext',
    description: 'Requires a fictional extension.',
    requires: ['ext-totally-not-a-real-extension'],
)]
final class MissingExtensionSubscriber implements SubscriberContract
{
    public function handle(Envelope $envelope): void {}
}
