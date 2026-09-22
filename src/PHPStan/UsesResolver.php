<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use function array_key_exists;
use function array_last;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function ltrim;
use function str_contains;
use function str_starts_with;
use function token_get_all;
use function trim;

use const T_CLASS;
use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_FUNCTION;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_OBJECT_OPERATOR;
use const T_STRING;
use const T_USE;
use const T_WHITESPACE;

/**
 * Static reading of a dialect file's `uses(...)` arguments (D-050):
 * the class and trait names a test file binds its closures to, taken
 * from the source text alone — the analysis-time mirror of what
 * PestScopes resolves at runtime by executing the file. Token-based
 * like every other scanner in this project, name resolution follows
 * PHP's own rules (leading backslash wins, then the alias map, then
 * the namespace prefix). File-local `uses(...)` reads through
 * classRefs(); the directory-scoped `pest()/uses()->in()` chains a
 * Pest.php declares read through scopedRegistrations() (D-067).
 */
final readonly class UsesResolver
{
    /**
     * Fully-qualified names passed to top-level uses() calls, in
     * argument order — `Foo::class` references and FQ string
     * literals both count, exactly the runtime surface.
     *
     * @return list<non-empty-string>
     */
    public static function classRefs(string $source): array
    {
        $tokens    = token_get_all($source);
        $total     = count($tokens);
        $namespace = '';

        /** @var array<string, non-empty-string> $aliases alias => fully-qualified */
        $aliases = [];

        $names = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $next = self::nextSignificant($tokens, $i + 1, $total);

                if ($next !== null && is_array($tokens[$next]) && ($tokens[$next][0] === T_STRING || $tokens[$next][0] === T_NAME_QUALIFIED)) {
                    $namespace = $tokens[$next][1];
                }

                continue;
            }

            if ($token[0] === T_USE) {
                self::collectImport($tokens, $i + 1, $total, $aliases);

                continue;
            }

            // Both spellings call the same global: uses(...) and the
            // fully-qualified \uses(...) the tokenizer names apart.
            $isUses = ($token[0] === T_STRING && $token[1] === 'uses')
                || ($token[0] === T_NAME_FULLY_QUALIFIED && $token[1] === '\uses');

            if (!$isUses) {
                continue;
            }

            // A method or static call spelled `->uses(` / `::uses(`
            // is somebody else's API, not the dialect global.
            $previous = self::previousSignificant($tokens, $i - 1);

            if ($previous !== null && is_array($tokens[$previous])
                && (in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true))
            ) {
                continue;
            }

            $open = self::nextSignificant($tokens, $i + 1, $total);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            foreach (self::arguments($tokens, $open + 1, $total) as $name) {
                $resolved = self::resolve($name, $namespace, $aliases);

                if ($resolved !== '') {
                    $names[] = $resolved;
                }
            }
        }

        return $names;
    }

    /**
     * The scoped registrations a Pest.php declares (D-067, closing
     * the D-050 leftover): every `pest()`/`uses()` chain carrying an
     * `->in(...)`, read as the class/trait names its extend()/use()/
     * assign()/initial arguments collect plus the in() glob strings —
     * the analysis-time mirror of ScopeRegistration. Chains without
     * an in() are bare (hooks only, classes inert — the D-033 rule)
     * and are not returned.
     *
     * @return list<array{names: list<non-empty-string>, globs: non-empty-list<string>}>
     */
    public static function scopedRegistrations(string $source): array
    {
        $tokens    = token_get_all($source);
        $total     = count($tokens);
        $namespace = '';

        /** @var array<string, non-empty-string> $aliases */
        $aliases = [];

        $registrations = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $next = self::nextSignificant($tokens, $i + 1, $total);

                if ($next !== null && is_array($tokens[$next]) && ($tokens[$next][0] === T_STRING || $tokens[$next][0] === T_NAME_QUALIFIED)) {
                    $namespace = $tokens[$next][1];
                }

                continue;
            }

            if ($token[0] === T_USE) {
                self::collectImport($tokens, $i + 1, $total, $aliases);

                continue;
            }

            $isChainRoot = ($token[0] === T_STRING && in_array($token[1], ['pest', 'uses'], true))
                || ($token[0] === T_NAME_FULLY_QUALIFIED && in_array($token[1], ['\pest', '\uses'], true));

            if (!$isChainRoot) {
                continue;
            }

            $previous = self::previousSignificant($tokens, $i - 1);

            if ($previous !== null && is_array($tokens[$previous])
                && in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)
            ) {
                continue;
            }

            $open = self::nextSignificant($tokens, $i + 1, $total);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            // The root call's own arguments count (the legacy
            // uses(Class, Trait)->in() spelling).
            $names = [];

            foreach (self::arguments($tokens, $open + 1, $total) as $name) {
                $resolved = self::resolve($name, $namespace, $aliases);

                if ($resolved !== '') {
                    $names[] = $resolved;
                }
            }

            $index = self::closingParen($tokens, $open, $total);
            $globs = [];

            // Walk the fluent chain: `-> name ( args )` until `;`.
            while ($index !== null) {
                $arrow = self::nextSignificant($tokens, $index + 1, $total);

                if ($arrow === null || !is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_OBJECT_OPERATOR) {
                    break;
                }

                $method = self::nextSignificant($tokens, $arrow + 1, $total);

                if ($method === null || !is_array($tokens[$method])) {
                    break;
                }

                $methodName = $tokens[$method][1];
                $openArgs   = self::nextSignificant($tokens, $method + 1, $total);

                if ($openArgs === null || $tokens[$openArgs] !== '(') {
                    break;
                }

                if (in_array($methodName, ['extend', 'use', 'assign'], true)) {
                    foreach (self::arguments($tokens, $openArgs + 1, $total) as $name) {
                        $resolved = self::resolve($name, $namespace, $aliases);

                        if ($resolved !== '') {
                            $names[] = $resolved;
                        }
                    }
                }

                if ($methodName === 'in') {
                    foreach (self::stringArguments($tokens, $openArgs + 1, $total) as $glob) {
                        $globs[] = $glob;
                    }
                }

                $index = self::closingParen($tokens, $openArgs, $total);
            }

            if ($globs !== []) {
                $registrations[] = ['names' => $names, 'globs' => $globs];
            }

            $i = $index ?? $i;
        }

        return $registrations;
    }

    /**
     * Every string literal inside one balanced argument list.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<non-empty-string>
     */
    private static function stringArguments(array $tokens, int $start, int $total): array
    {
        $depth   = 1;
        $strings = [];

        for ($i = $start; $i < $total && $depth > 0; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            } elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = trim($token[1], "'\"");

                if ($literal !== '') {
                    $strings[] = $literal;
                }
            }
        }

        return $strings;
    }

    /**
     * The index of the parenthesis closing the one open at $open.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function closingParen(array $tokens, int $open, int $total): ?int
    {
        $depth = 0;

        for ($i = $open; $i < $total; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The `Name::class` and FQ-string arguments inside one balanced
     * argument list, unresolved.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private static function arguments(array $tokens, int $start, int $total): array
    {
        $depth = 1;
        $names = [];

        for ($i = $start; $i < $total && $depth > 0; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $depth++;

                continue;
            }

            if ($token === ')') {
                $depth--;

                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = trim($token[1], "'\"");

                if ($literal !== '' && str_contains($literal, '\\')) {
                    $names[] = '\\' . ltrim($literal, '\\');
                }

                continue;
            }

            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $doubleColon = self::nextSignificant($tokens, $i + 1, $total);

            if ($doubleColon === null || !is_array($tokens[$doubleColon]) || $tokens[$doubleColon][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $classKeyword = self::nextSignificant($tokens, $doubleColon + 1, $total);

            if ($classKeyword !== null && is_array($tokens[$classKeyword]) && $tokens[$classKeyword][0] === T_CLASS) {
                $names[] = $token[1];
                $i       = $classKeyword;
            }
        }

        return $names;
    }

    /**
     * PHP's own resolution rules: a leading backslash wins, then the
     * alias map rewrites the first segment, then the namespace
     * prefixes bare names.
     *
     * @param array<string, non-empty-string> $aliases
     */
    private static function resolve(string $name, string $namespace, array $aliases): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);

        if (array_key_exists($segments[0], $aliases)) {
            $segments[0] = $aliases[$segments[0]];

            return implode('\\', $segments);
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * One `use A\B\C;` / `use A\B as C;` import into the alias map.
     * Function and const imports, and trait-use inside class bodies,
     * are skipped by shape: a trait use has no qualified name after
     * it or sits inside braces the caller never routes here — the
     * dialect files this reads are top-level scripts.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, non-empty-string>                     $aliases
     */
    private static function collectImport(array $tokens, int $start, int $total, array &$aliases): void
    {
        $index = self::nextSignificant($tokens, $start, $total);

        if ($index === null || !is_array($tokens[$index])) {
            return;
        }

        $token = $tokens[$index];

        // `use function ...` / `use const ...` never name classes.
        if ($token[0] === T_FUNCTION || $token[1] === 'const') {
            return;
        }

        if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
            return;
        }

        $qualified = ltrim($token[1], '\\');
        $segments  = explode('\\', $qualified);
        $alias     = array_last($segments);

        $next = self::nextSignificant($tokens, $index + 1, $total);

        if ($next !== null && is_array($tokens[$next]) && $tokens[$next][1] === 'as') {
            $aliasToken = self::nextSignificant($tokens, $next + 1, $total);

            if ($aliasToken !== null && is_array($tokens[$aliasToken]) && $tokens[$aliasToken][0] === T_STRING) {
                $alias = $tokens[$aliasToken][1];
            }
        }

        if ($qualified !== '' && $alias !== '') {
            $aliases[$alias] = $qualified;
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $start, int $total): ?int
    {
        for ($i = $start; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousSignificant(array $tokens, int $start): ?int
    {
        for ($i = $start; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
