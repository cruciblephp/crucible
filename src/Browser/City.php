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
 * The city presets of the Pest browser surface: one preset configures
 * geolocation, timezone, and locale together (`from()->losAngeles()`
 * in the incumbent's grammar; B1 pinned the 13 names).
 */
enum City
{
    case Amsterdam;

    case Berlin;

    case Chicago;

    case Houston;

    case London;

    case LosAngeles;

    case Miami;

    case NewYork;

    case Paris;

    case Tokyo;

    case Toronto;

    case SanFrancisco;

    case Sydney;

    /**
     * @return array{latitude: float, longitude: float, timezone: non-empty-string, locale: non-empty-string}
     */
    public function simulation(): array
    {
        [$latitude, $longitude, $timezone, $locale] = match ($this) {
            self::Amsterdam    => [52.3676, 4.9041, 'Europe/Amsterdam', 'nl-NL'],
            self::Berlin       => [52.5200, 13.4050, 'Europe/Berlin', 'de-DE'],
            self::Chicago      => [41.8781, -87.6298, 'America/Chicago', 'en-US'],
            self::Houston      => [29.7604, -95.3698, 'America/Chicago', 'en-US'],
            self::London       => [51.5074, -0.1278, 'Europe/London', 'en-GB'],
            self::LosAngeles   => [34.0522, -118.2437, 'America/Los_Angeles', 'en-US'],
            self::Miami        => [25.7617, -80.1918, 'America/New_York', 'en-US'],
            self::NewYork      => [40.7128, -74.0060, 'America/New_York', 'en-US'],
            self::Paris        => [48.8566, 2.3522, 'Europe/Paris', 'fr-FR'],
            self::Tokyo        => [35.6762, 139.6503, 'Asia/Tokyo', 'ja-JP'],
            self::Toronto      => [43.6532, -79.3832, 'America/Toronto', 'en-CA'],
            self::SanFrancisco => [37.7749, -122.4194, 'America/Los_Angeles', 'en-US'],
            self::Sydney       => [-33.8688, 151.2093, 'Australia/Sydney', 'en-AU'],
        };

        return ['latitude' => $latitude, 'longitude' => $longitude, 'timezone' => $timezone, 'locale' => $locale];
    }
}
