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
 * The device presets of the Pest browser surface (spec/pest-api.md §6,
 * B1 oracle pins). Where Playwright's own registry (207 descriptors,
 * arriving free on connect) has the device, the registry name is used —
 * real user agents included. Where it does not (MacBooks, Surfaces,
 * several Galaxies), Crucible defines the viewport/scale/touch shape and
 * deliberately invents no user-agent string: an honest viewport, not a
 * fake identity.
 */
enum Device
{
    case Desktop;

    case Mobile;

    case MacBook16;

    case MacBook14;

    case MacBookAir;

    case IPhone15Pro;

    case IPhone15;

    case IPhone14Pro;

    case IPhoneSE;

    case IPadPro;

    case IPadMini;

    case Pixel8;

    case Pixel7;

    case Pixel6a;

    case GalaxyS24Ultra;

    case GalaxyS23;

    case GalaxyS22;

    case GalaxyNote20;

    case GalaxyTabS8;

    case SurfacePro9;

    case SurfaceLaptop5;

    case OnePlus11;

    case Xiaomi13;

    case HuaweiP50;

    /** The Playwright registry name, when the registry has this device. */
    public function registryName(): ?string
    {
        return match ($this) {
            self::Desktop        => 'Desktop Chrome',
            self::IPhone15Pro    => 'iPhone 15 Pro',
            self::IPhone15       => 'iPhone 15',
            self::IPhone14Pro    => 'iPhone 14 Pro',
            self::IPhoneSE       => 'iPhone SE (3rd gen)',
            self::IPadPro        => 'iPad Pro 11',
            self::IPadMini       => 'iPad Mini',
            self::Pixel8         => 'Pixel 8',
            self::Pixel7         => 'Pixel 7',
            self::Pixel6a        => 'Pixel 6a',
            self::GalaxyS24Ultra => 'Galaxy S24',
            default              => null,
        };
    }

    /**
     * Crucible-defined descriptor for devices the registry lacks —
     * viewport truth only, never an invented user agent.
     *
     * @return array<string, mixed>
     */
    public function descriptor(): array
    {
        [$width, $height, $scale, $mobile] = match ($this) {
            self::Mobile         => [390, 844, 3.0, true],
            self::MacBook16      => [1_728, 1_117, 2.0, false],
            self::MacBook14      => [1_512, 982, 2.0, false],
            self::MacBookAir     => [1_280, 832, 2.0, false],
            self::GalaxyS23      => [360, 780, 3.0, true],
            self::GalaxyS22      => [360, 780, 3.0, true],
            self::GalaxyNote20   => [412, 915, 2.6, true],
            self::GalaxyTabS8    => [753, 1_205, 2.4, true],
            self::SurfacePro9    => [1_440, 960, 2.0, true],
            self::SurfaceLaptop5 => [1_536, 1_024, 1.5, false],
            self::OnePlus11      => [412, 919, 3.5, true],
            self::Xiaomi13       => [393, 851, 3.0, true],
            self::HuaweiP50      => [360, 780, 3.0, true],
            default              => [1_280, 720, 1.0, false],
        };

        return [
            'viewport'          => ['width' => $width, 'height' => $height],
            'deviceScaleFactor' => $scale,
            'isMobile'          => $mobile,
            'hasTouch'          => $mobile,
        ];
    }
}
