<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat;

use Composer\InstalledVersions;
use LucianoPereira\Crucible\Double\Mockery\InvalidCountException;
use LucianoPereira\Crucible\Double\Mockery\MockeryApi;
use LucianoPereira\Crucible\Double\Mockery\MockeryBadMethodCallException;
use LucianoPereira\Crucible\Double\Mockery\MockeryException;
use LucianoPereira\Crucible\Double\Mockery\MockeryExpectation;
use LucianoPereira\Crucible\Double\Mockery\MockeryMock;
use LucianoPereira\Crucible\Double\Mockery\MockeryPHPUnitIntegration;
use LucianoPereira\Crucible\Double\Mockery\NoMatchingExpectationException;

use function class_alias;
use function class_exists;
use function interface_exists;

/**
 * The Mockery-name aliases, on the D-019 coexistence principle: they
 * install automatically exactly when the real mockery/mockery is
 * absent, and never mask an installed package. Scope per the
 * 2026-07-16 decision (spec/mockery-api.md): the Pest mocking chapter
 * — M2 core grammar + M3 matchers; the rest answers with named
 * errors from the aliased classes themselves.
 */
final class MockeryCompatibility
{
    private static bool $loaded = false;

    public static function shouldAutoEnable(): bool
    {
        return !self::mockeryIsInstalled();
    }

    public static function mockeryIsInstalled(): bool
    {
        return class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled('mockery/mockery');
    }

    public static function load(): void
    {
        if (self::$loaded || class_exists('Mockery', false)) {
            return; // a real Mockery in the process is never masked
        }

        class_alias(MockeryApi::class, 'Mockery');
        class_alias(MockeryExpectation::class, 'Mockery\Expectation');
        class_alias(MockeryException::class, 'Mockery\Exception');
        class_alias(InvalidCountException::class, 'Mockery\Exception\InvalidCountException');
        class_alias(NoMatchingExpectationException::class, 'Mockery\Exception\NoMatchingExpectationException');
        class_alias(MockeryBadMethodCallException::class, 'Mockery\Exception\BadMethodCallException');
        class_alias(MockeryException::class, 'Mockery\Exception\RuntimeException');

        if (!interface_exists('Mockery\MockInterface', false)) {
            class_alias(MockeryMock::class, 'Mockery\MockInterface');
            class_alias(MockeryMock::class, 'Mockery\LegacyMockInterface');
        }

        // The integration trait's name (D-066): suites use it so the
        // host settles expectations as failures — Crucible's native
        // settlement already does; the name just has to exist.
        class_alias(MockeryPHPUnitIntegration::class, 'Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration');

        self::$loaded = true;
    }
}
