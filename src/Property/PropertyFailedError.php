<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Property;

use LucianoPereira\Crucible\Assert\AssertionFailedError;

/**
 * A falsified property (D-040): an assertion failure that also
 * carries the machine-readable reproduction — the property's stable
 * key and the shrunk choice sequence — so the runner can put it on
 * the event stream and the failure database can replay it next run.
 */
final class PropertyFailedError extends AssertionFailedError
{
    /**
     * @param non-empty-string $key
     * @param list<int>        $choices the shrunk counterexample's choice sequence
     */
    public function __construct(
        string $message,
        public readonly string $key,
        public readonly array $choices,
    ) {
        parent::__construct($message);
    }
}
