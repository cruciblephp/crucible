<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Metadata;

use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
use function basename;
use function count;
use function glob;
use function in_array;
use function is_array;
use function is_int;

/**
 * Real PHPUnit's own #[CoversClass]/#[UsesClass]/#[RequiresPhp]/
 * #[Group]/#[Depends]/#[DataProvider]/#[TestWith]/
 * #[ExcludeGlobalVariableFromBackup] and their siblings
 * (PHPUnit\Framework\Attributes\*) are, like the hook attributes
 * RealPhpUnitHookAttributes already handles, a genuinely different
 * class from Crucible's own equivalents whenever aliasing isn't
 * actually active (PhpUnitCompatibility::isLoaded() false — the
 * coexistence/migration scenario, phpunit/phpunit installed with no
 * opt-in). MetadataParser's CrucibleAttribute-instanceof scan never
 * matches them, so a project's real, un-aliased Covers, Uses,
 * Requires, Group, Depends, DataProvider, TestWith and
 * Exclude*Backup usage silently does nothing — verified directly
 * against MetadataParser first (none of these recognized on a class
 * carrying the real attributes), a genuine gap, not fidelity to some
 * PHPUnit limitation.
 *
 * Reads ReflectionAttribute::getArguments() directly rather than
 * instantiating the real attribute class — no need to touch its
 * actual public-property/getter shape at all, only the literal
 * arguments written at the attribute's use site, forwarded into
 * Crucible's own equivalent constructor (verified to match every
 * candidate's real parameter names/order). filteredArguments() drops
 * any argument the target constructor doesn't declare — the one
 * known delta is DataProvider/DataProviderExternal's extra trailing
 * validateArgumentCount, which Crucible's own equivalents don't
 * accept.
 *
 * Before/PreCondition/PostCondition/After are deliberately excluded
 * from the glob below: RealPhpUnitHookAttributes already translates
 * them via their real priority() method (they don't expose it as a
 * constructor-matching property the way this file's candidates do),
 * and running both would fire every hook twice.
 *
 * Isolated in its own file, excluded from phpstan (phpstan.neon), for
 * the same reason as RealPhpUnitHookAttributes: compiles against
 * PHPUnit\Framework\Attributes\* classes deliberately not a
 * dev-dependency of the engine.
 */
final class RealPhpUnitAttributes
{
    /**
     * candidates(), once per process: the attribute files do not change
     * during a run.
     *
     * @var ?array<string, class-string<CrucibleAttribute>>
     */
    private static ?array $candidates = null;

    private const array EXCLUDED = ['Before', 'PreCondition', 'PostCondition', 'After'];

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<CrucibleAttribute>
     */
    public static function forClass(ReflectionClass $class): array
    {
        if (PhpUnitCompatibility::isLoaded()) {
            return [];
        }

        $translated = [];

        foreach (self::candidates() as $name => $crucibleClass) {
            foreach ($class->getAttributes('PHPUnit\\Framework\\Attributes\\' . $name) as $attribute) {
                $translated[] = self::instantiate($attribute, $crucibleClass);
            }
        }

        return $translated;
    }

    /**
     * @return list<CrucibleAttribute>
     */
    public static function forMethod(ReflectionMethod $method): array
    {
        if (PhpUnitCompatibility::isLoaded()) {
            return [];
        }

        $translated = [];

        foreach (self::candidates() as $name => $crucibleClass) {
            foreach ($method->getAttributes('PHPUnit\\Framework\\Attributes\\' . $name) as $attribute) {
                $translated[] = self::instantiate($attribute, $crucibleClass);
            }
        }

        return $translated;
    }

    /**
     * Same glob-by-basename convention as
     * PhpUnitCompatibility::aliases() — every file in src/Attributes/
     * is assumed name-identical to its real PHPUnit counterpart when
     * one exists; when it doesn't (a handful of Crucible-only
     * attributes have no PHPUnit equivalent at all), getAttributes()
     * against the never-used real name simply returns nothing, so no
     * separate allowlist is needed.
     *
     * @return array<string, class-string<CrucibleAttribute>>
     */
    private static function candidates(): array
    {
        // The attribute files do not change during a run, and this is asked
        // once per method of every test class: a glob per call was hundreds
        // of thousands of them on a Pest suite over Laravel's TestCase,
        // which never finished discovering.
        if (is_array(self::$candidates)) {
            return self::$candidates;
        }

        $files = glob(__DIR__ . '/../Attributes/*.php');

        $candidates = [];

        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, self::EXCLUDED, true)) {
                continue;
            }

            /** @var class-string<CrucibleAttribute> $crucibleClass */
            $crucibleClass = 'LucianoPereira\\Crucible\\Attributes\\' . $name;

            $candidates[$name] = $crucibleClass;
        }

        return self::$candidates = $candidates;
    }

    /**
     * @param ReflectionAttribute<object>     $attribute
     * @param class-string<CrucibleAttribute> $crucibleClass
     */
    private static function instantiate(ReflectionAttribute $attribute, string $crucibleClass): CrucibleAttribute
    {
        $arguments = self::filteredArguments($attribute->getArguments(), $crucibleClass);

        return new $crucibleClass(...$arguments);
    }

    /**
     * @param array<int|string, mixed>        $arguments
     * @param class-string<CrucibleAttribute> $crucibleClass
     *
     * @return array<int|string, mixed>
     */
    private static function filteredArguments(array $arguments, string $crucibleClass): array
    {
        $parameters    = (new ReflectionMethod($crucibleClass, '__construct'))->getParameters();
        $acceptedNames = array_map(static fn($parameter) => $parameter->getName(), $parameters);
        $acceptedCount = count($parameters);

        $filtered = [];

        foreach ($arguments as $key => $value) {
            if (is_int($key)) {
                if ($key < $acceptedCount) {
                    $filtered[$key] = $value;
                }

                continue;
            }

            if (in_array($key, $acceptedNames, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
