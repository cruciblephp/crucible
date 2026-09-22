<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

/**
 * Environment simulation for one browsing context — the typed form of
 * the Pest chains (`on()->iPhone14Pro()`, `from()->losAngeles()`,
 * `inDarkMode()`, `withLocale/withTimezone/withUserAgent`,
 * `geolocation()`). Composition order at context creation: device
 * descriptor first, city preset over it, explicit fields last —
 * the most specific declaration wins.
 */
final readonly class ContextOptions
{
    /**
     * @param array{float, float}|null $geolocation [latitude, longitude]
     */
    public function __construct(
        public ?Device $device = null,
        public ?City $from = null,
        public ?bool $darkMode = null,
        public ?string $locale = null,
        public ?string $timezone = null,
        public ?string $userAgent = null,
        public ?array $geolocation = null,
    ) {}
}
