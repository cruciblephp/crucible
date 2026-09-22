<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

use LucianoPereira\Crucible\Browser\BrowserProtocolException;

use function is_array;
use function is_string;
use function sprintf;

/**
 * One node of the driver's guid-addressed object tree, as announced
 * by a __create__ event: its type, its parent, and the initializer
 * payload (where the driver publishes things like the Playwright
 * root's browser-type guids and a page's main frame).
 */
final readonly class RemoteObject
{
    /**
     * @param array<string, mixed> $initializer
     */
    public function __construct(
        public string $guid,
        public string $type,
        public string $parentGuid,
        public array $initializer,
    ) {}

    /**
     * The guid of an object referenced from this initializer
     * (initializers point at other objects as {"key": {"guid": ...}}).
     */
    public function referencedGuid(string $key): string
    {
        $entry = $this->initializer[$key] ?? null;

        if (is_array($entry) && is_string($entry['guid'] ?? null)) {
            return $entry['guid'];
        }

        throw new BrowserProtocolException(sprintf(
            'Object %s (%s) does not reference "%s" in its initializer.',
            $this->guid,
            $this->type,
            $key,
        ));
    }
}
