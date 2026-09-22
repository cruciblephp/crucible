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
use LucianoPereira\Crucible\Reporting\Subscriber\SubscriberContract;

/** Implements the contract but carries no #[Subscriber] attribute at all. */
final class NoAttributeSubscriber implements SubscriberContract
{
    public function handle(Envelope $envelope): void {}
}
