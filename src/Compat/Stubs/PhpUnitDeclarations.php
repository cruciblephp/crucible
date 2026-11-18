<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Compat\Stubs;

use Attribute;
use BackedEnum;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use ReflectionClass;
use UnitEnum;

use function dirname;
use function implode;
use function is_int;
use function ksort;
use function sprintf;
use function strrpos;
use function substr;
use function var_export;

/**
 * The analysis-only declarations for the PHPUnit names the run-time
 * compatibility layer aliases (D-174). `class_alias()` is invisible to
 * every static analyser — no process runs — so a drop-in suite whose
 * tests extend `PHPUnit\Framework\TestCase` analysed as 2,738 errors
 * the moment `phpunit/phpunit` left the tree (ticket #2 item 5). This
 * generator turns the alias map into real declarations an analyser
 * can parse: a class per name, extending the Crucible class the alias
 * answers, so the whole public API is inherited and stays current
 * without rendering a single signature.
 *
 * The one thing PHP forbids — extending a `final` or `readonly` class
 * — the declarations file still writes, deliberately: a stub is
 * parsed, never autoloaded or executed, so the runtime rule never
 * fires, and an analysis-only child is exactly what the name needs to
 * be (the incumbent's own attributes, which are `final`, read the
 * same way through their children). Enums are the one shape that
 * cannot extend, so an enum target is re-declared with its cases —
 * the copy answers the aliased name; the Crucible original stays what
 * a parameter such as `#[Todo]`'s status actually takes, so a status
 * passed under the aliased name is the one place the declarations
 * file is looser than the alias: use the Crucible name there.
 */
final class PhpUnitDeclarations
{
    /**
     * The single-bit attribute targets, in the order the incumbent
     * writes them; IS_REPEATABLE follows.
     */
    private const array TARGET_BITS = [
        Attribute::TARGET_CLASS          => '\Attribute::TARGET_CLASS',
        Attribute::TARGET_METHOD         => '\Attribute::TARGET_METHOD',
        Attribute::TARGET_PROPERTY       => '\Attribute::TARGET_PROPERTY',
        Attribute::TARGET_PARAMETER      => '\Attribute::TARGET_PARAMETER',
        Attribute::TARGET_CLASS_CONSTANT => '\Attribute::TARGET_CLASS_CONSTANT',
        Attribute::TARGET_FUNCTION       => '\Attribute::TARGET_FUNCTION',
    ];

    /**
     * The declarations, sorted by namespace then name, so two runs on
     * the same checkout produce the same bytes.
     */
    public static function generate(): string
    {
        /** @var array<string, array<string, class-string>> $byNamespace */
        $byNamespace = [];

        foreach (PhpUnitCompatibility::aliases() as $phpunitName => $target) {
            $byNamespace[self::namespaceOf($phpunitName)][self::shortName($phpunitName)] = $target;
        }

        ksort($byNamespace);

        $blocks = [];

        foreach ($byNamespace as $namespace => $targets) {
            ksort($targets);

            $block = 'namespace ' . $namespace . ';' . "\n\n";

            foreach ($targets as $shortName => $target) {
                $block .= self::declaration($shortName, $target) . "\n";
            }

            $blocks[] = $block;
        }

        return self::header() . "\n" . implode("\n", $blocks);
    }

    /**
     * The shipped file, `stubs/phpunit.php` in the package: what a
     * consumer's analyser is pointed at, and what the drift test
     * regenerates and compares byte for byte.
     *
     * @return non-empty-string
     */
    public static function file(): string
    {
        return dirname(__DIR__, 3) . '/stubs/phpunit.php';
    }

    /**
     * @param class-string $target
     */
    private static function declaration(string $shortName, string $target): string
    {
        $reflection = new ReflectionClass($target);

        if ($reflection->isEnum()) {
            return self::enumDeclaration($shortName, $reflection);
        }

        $marker = self::attributeMarker($reflection);

        if ($reflection->isInterface()) {
            $declaration = 'interface ' . $shortName . ' extends \\' . $target . ' {}';

            return $marker === '' ? $declaration : $marker . "\n" . $declaration;
        }

        $prefix = ($reflection->isAbstract() ? 'abstract ' : '')
            . ($reflection->isReadOnly() ? 'readonly ' : '');

        $declaration = $prefix . 'class ' . $shortName . ' extends \\' . $target . ' {}';

        return $marker === '' ? $declaration : $marker . "\n" . $declaration;
    }

    /**
     * An enum cannot be extended, so the copy IS the declaration: the
     * same cases, the same backing type. The cases arrive as the
     * enum's class constants — every value a case instance — and the
     * first backed value names the backing type, the way the
     * declaration spell it.
     *
     * @param ReflectionClass<object> $reflection
     */
    private static function enumDeclaration(string $shortName, ReflectionClass $reflection): string
    {
        $cases   = [];
        $backing = null;

        foreach ($reflection->getConstants() as $value) {
            if (!$value instanceof UnitEnum) {
                continue;
            }

            if ($value instanceof BackedEnum) {
                $backing ??= is_int($value->value) ? 'int' : 'string';

                $cases[] = sprintf('    case %s = %s;', $value->name, var_export($value->value, true));

                continue;
            }

            $cases[] = '    case ' . $value->name . ';';
        }

        return 'enum ' . $shortName . ($backing === null ? '' : ': ' . $backing) . "\n{\n"
            . implode("\n", $cases)
            . "\n}";
    }

    /**
     * The `#[\Attribute(...)]` marker the declarations carry for the
     * aliased attributes, with the target's own flags: what makes
     * `#[Group('db')]` on a method parse as a valid usage of the
     * aliased name.
     *
     * @param ReflectionClass<object> $reflection
     */
    private static function attributeMarker(ReflectionClass $reflection): string
    {
        $attributes = $reflection->getAttributes(Attribute::class);

        if ($attributes === []) {
            return '';
        }

        $flags = $attributes[0]->getArguments()[0] ?? 0;

        if (!is_int($flags) || $flags === 0) {
            return '#[\Attribute]';
        }

        $parts = [];

        foreach (self::TARGET_BITS as $bit => $name) {
            if (($flags & $bit) !== 0) {
                $parts[] = $name;
            }
        }

        if (($flags & Attribute::IS_REPEATABLE) !== 0) {
            $parts[] = '\Attribute::IS_REPEATABLE';
        }

        return '#[\Attribute(' . implode(' | ', $parts) . ')]';
    }

    /**
     * The file's own explanation, so a reader who finds it in vendor/
     * knows what it is for and who generated it.
     */
    private static function header(): string
    {
        return <<<'PHP_WRAP'
        <?php
        
        declare(strict_types=1);
        
        /*
         * Analysis-only declarations for the PHPUnit names Crucible's
         * run-time compatibility layer aliases (PhpUnitCompatibility::aliases()).
         * Generated by LucianoPereira\Crucible\Compat\Stubs\PhpUnitDeclarations -
         * do not edit; regenerate instead.
         *
         * Point an analyser that cannot load a PHPStan extension at this file:
         *
         * - Mago, mago.toml:   [source] includes = ["vendor/cruciblephp/crucible/stubs/phpunit.php"]
         * - Psalm, psalm.xml:  <stubs><stub name="vendor/cruciblephp/crucible/stubs/phpunit.php"/></stubs>
         *
         * PHPStan needs nothing here: its analysis-time aliases load through
         * phpstan/bootstrap.php, and a class both declared and aliased would
         * collide.
         *
         * Each declaration extends the Crucible class the run-time alias
         * answers, so the public API is inherited and stays current. The
         * file is parsed, never autoloaded, which is also why final and
         * readonly targets have children here - PHP's runtime rule never
         * fires on a file no process runs. `TodoStatus` is the one
         * re-declaration: an enum cannot extend, and the copy answers the
         * aliased name - but `#[Todo]`'s status parameter takes the
         * Crucible original, so pass it that name.
         *
         * Fidelity note: these are declarations, not the aliases themselves -
         * `PHPUnit\Framework\TestCase` accepts Crucible's TestCase as its
         * parent, not the other way around. Analysis only cares that the
         * name resolves with the right API, which extending gives for free.
         */
        
        PHP_WRAP;
    }

    /**
     * @param non-empty-string $name a fully-qualified alias name
     */
    private static function namespaceOf(string $name): string
    {
        $cut = strrpos($name, '\\');

        return $cut === false ? '' : substr($name, 0, $cut);
    }

    /**
     * @param non-empty-string $name a fully-qualified alias name
     */
    private static function shortName(string $name): string
    {
        $cut = strrpos($name, '\\');

        return $cut === false ? $name : substr($name, $cut + 1);
    }
}
