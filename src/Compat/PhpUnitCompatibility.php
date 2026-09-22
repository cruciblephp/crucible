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
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\Crucible\Double\MockBuilder;
use LucianoPereira\Crucible\Double\Mocked;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;

use function basename;
use function class_alias;
use function class_exists;
use function glob;
use function implode;
use function sprintf;

/**
 * The PHPUnit-namespace compatibility layer, with a coexistence
 * policy for projects that still have the real PHPUnit installed:
 *
 * - Drop-in mode (auto): phpunit/phpunit is NOT installed — aliases
 *   load automatically; nothing else claims the namespace.
 * - Migration mode (explicit): phpunit/phpunit IS installed — aliases
 *   stay off unless the configuration opts in, and enabling fails
 *   fast if any real PHPUnit class is already loaded (a half-aliased
 *   process must never happen silently).
 */
final class PhpUnitCompatibility
{
    private static bool $loaded = false;

    /**
     * Deterministic default: alias automatically exactly when the
     * real package is absent.
     */
    public static function shouldAutoEnable(): bool
    {
        return !self::phpUnitIsInstalled();
    }

    public static function phpUnitIsInstalled(): bool
    {
        return class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled('phpunit/phpunit');
    }

    /**
     * Whether load() has actually aliased PHPUnit\Framework\* names
     * onto Crucible's own classes this process — the correct guard
     * for real-PHPUnit-name translators deciding whether to run
     * themselves (phpUnitIsInstalled() alone conflates "installed" with
     * "aliased", which are the same thing only in auto/drop-in mode;
     * explicit opt-in aliases even with the real package installed,
     * which phpUnitIsInstalled()-guarded translators double-handled —
     * once via the alias, once by exact real name — crashing on the
     * type mismatch between what they expected and Crucible's own
     * attribute shape the alias actually resolves to).
     */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Registers every alias, or throws before aliasing anything when
     * real PHPUnit classes are already loaded in this process.
     *
     * @throws ConfigurationException
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $aliases = self::aliases();

        $alreadyReal = [];

        foreach ($aliases as $phpunitName => $crucibleClass) {
            if (class_exists($phpunitName, false)) {
                $alreadyReal[] = $phpunitName;
            }
        }

        if ($alreadyReal !== []) {
            throw new ConfigurationException(sprintf(
                "PHPUnit classes are already loaded in this process (%s); Crucible's compatibility "
                . 'aliases cannot be installed. Ensure the bootstrap does not load PHPUnit, or run '
                . "without compatibility aliases by extending Crucible's TestCase directly.",
                implode(', ', $alreadyReal),
            ));
        }

        foreach ($aliases as $phpunitName => $crucibleClass) {
            class_alias($crucibleClass, $phpunitName);
        }

        self::$loaded = true;
    }

    /**
     * @return array<string, class-string> PHPUnit name => Crucible class
     */
    private static function aliases(): array
    {
        $aliases = [
            'PHPUnit\Framework\TestCase'             => TestCase::class,
            'PHPUnit\Framework\Assert'               => Assert::class,
            'PHPUnit\Framework\AssertionFailedError' => AssertionFailedError::class,
            // The spec's failure subclass: aliasing it to the same
            // hierarchy keeps framework catch-blocks working (found
            // by the Phase 9 Laravel gate, like the two below).
            'PHPUnit\Framework\ExpectationFailedException' => AssertionFailedError::class,
            // Frameworks subclass the constraint base for their own
            // assertions (Laravel's assertDatabaseHas, assertJson);
            // the hook surface (matches/toString/failureDescription)
            // is signature-compatible by design.
            'PHPUnit\Framework\Constraint\Constraint' => Constraint::class,
            // The double surface (D-046): mocks and stubs are one
            // concept in Crucible, so both spec interfaces alias the
            // Mocked marker and both builders alias the one builder.
            'PHPUnit\Framework\MockObject\MockObject'      => Mocked::class,
            'PHPUnit\Framework\MockObject\Stub'            => Mocked::class,
            'PHPUnit\Framework\MockObject\MockBuilder'     => MockBuilder::class,
            'PHPUnit\Framework\MockObject\TestStubBuilder' => MockBuilder::class,
        ];

        $attributeFiles = glob(__DIR__ . '/../Attributes/*.php');

        foreach ($attributeFiles === false ? [] : $attributeFiles as $file) {
            $name = basename($file, '.php');

            /** @var class-string $crucibleClass */
            $crucibleClass = 'LucianoPereira\\Crucible\\Attributes\\' . $name;

            $aliases['PHPUnit\\Framework\\Attributes\\' . $name] = $crucibleClass;
        }

        return $aliases;
    }
}
