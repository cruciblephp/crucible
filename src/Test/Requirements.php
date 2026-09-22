<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Test;

use LucianoPereira\Crucible\Attributes\RequiresFunction;
use LucianoPereira\Crucible\Attributes\RequiresMethod;
use LucianoPereira\Crucible\Attributes\RequiresOperatingSystem;
use LucianoPereira\Crucible\Attributes\RequiresOperatingSystemFamily;
use LucianoPereira\Crucible\Attributes\RequiresPhp;
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
use LucianoPereira\Crucible\Attributes\RequiresSetting;
use LucianoPereira\Crucible\Metadata\MetadataCollection;

use function extension_loaded;
use function function_exists;
use function ini_get;
use function method_exists;
use function phpversion;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function version_compare;

use const PHP_OS;
use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * Evaluates the #[Requires*] attribute family against the current
 * environment. Returns the reasons a test cannot run here — an empty
 * list means it can.
 */
final readonly class Requirements
{
    /**
     * @return list<non-empty-string> unmet requirements, human-readable
     */
    public function unmet(MetadataCollection $metadata): array
    {
        $unmet = [];

        foreach ($metadata->ofType(RequiresPhp::class) as $requirement) {
            if (!$this->versionSatisfies(PHP_VERSION, $requirement->versionRequirement)) {
                $unmet[] = sprintf('PHP %s is required; this is %s.', $requirement->versionRequirement, PHP_VERSION);
            }
        }

        foreach ($metadata->ofType(RequiresPhpExtension::class) as $requirement) {
            if (!extension_loaded($requirement->extension)) {
                // Phrased as the consequence — what is absent — not the
                // rule: the #[Requires*] attribute is the "is required"
                // declaration; the skip reason a report shows reads as
                // why the test could not run (D-075).
                $unmet[] = sprintf('PHP extension %s is missing.', $requirement->extension);

                continue;
            }

            $version = $requirement->versionRequirement;

            if ($version !== null && !$this->versionSatisfies((string) phpversion($requirement->extension), $version)) {
                $unmet[] = sprintf('PHP extension %s %s is required.', $requirement->extension, $version);
            }
        }

        foreach ($metadata->ofType(RequiresFunction::class) as $requirement) {
            if (!function_exists($requirement->functionName)) {
                $unmet[] = sprintf('Function %s() is missing.', $requirement->functionName);
            }
        }

        foreach ($metadata->ofType(RequiresMethod::class) as $requirement) {
            if (!method_exists($requirement->className, $requirement->methodName)) {
                $unmet[] = sprintf('Method %s::%s() is missing.', $requirement->className, $requirement->methodName);
            }
        }

        foreach ($metadata->ofType(RequiresOperatingSystem::class) as $requirement) {
            if (preg_match('/' . $requirement->regularExpression . '/i', PHP_OS) !== 1) {
                $unmet[] = sprintf('Operating system matching %s is required.', $requirement->regularExpression);
            }
        }

        foreach ($metadata->ofType(RequiresOperatingSystemFamily::class) as $requirement) {
            if ($requirement->operatingSystemFamily !== PHP_OS_FAMILY) {
                $unmet[] = sprintf('Operating system family %s is required.', $requirement->operatingSystemFamily);
            }
        }

        foreach ($metadata->ofType(RequiresSetting::class) as $requirement) {
            if (ini_get($requirement->setting) !== $requirement->value) {
                $unmet[] = sprintf('Setting "%s" must be "%s".', $requirement->setting, $requirement->value);
            }
        }

        return $unmet;
    }

    /**
     * Accepts both bare versions ('8.5') meaning >= and explicit
     * operator requirements ('< 9.0', '>= 8.5.2').
     */
    private function versionSatisfies(string $actual, string $requirement): bool
    {
        foreach (['<=', '>=', '<>', '!=', '==', '<', '>', '='] as $operator) {
            if (str_starts_with($requirement, $operator)) {
                $version = trim(substr($requirement, strlen($operator)));

                return version_compare($actual, $version, $operator);
            }
        }

        return version_compare($actual, trim($requirement), '>=');
    }
}
