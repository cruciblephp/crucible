<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use PhpToken;

use function array_key_exists;
use function array_last;
use function array_merge;
use function count;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function str_contains;
use function str_starts_with;
use function trim;

use const T_AS;
use const T_CLASS;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOUBLE_COLON;
use const T_FUNCTION;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NS_SEPARATOR;
use const T_OBJECT_OPERATOR;
use const T_STRING;
use const T_USE;

/**
 * Static reading of a dialect file's `uses(...)` arguments (D-050):
 * the class and trait names a test file binds its closures to, taken
 * from the source text alone — the analysis-time mirror of what
 * PestScopes resolves at runtime by executing the file. Token-based
 * like every other scanner in this project, name resolution follows
 * PHP's own rules (leading backslash wins, then the alias map, then
 * the namespace prefix), and imports are read by PHP's grammar: lists,
 * groups, aliases (D-137). File-local `uses(...)` reads through
 * classRefs(); the directory-scoped `pest()/uses()->in()` chains a
 * Pest.php declares read through scopedRegistrations() (D-067). Both
 * are one walk over the file, differing only in what they keep.
 */
final readonly class UsesResolver
{
    /** A name as the tokenizer spells it: bare, qualified, or fully qualified. */
    private const array NAME = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * Fully-qualified names passed to top-level uses() calls, in
     * argument order — `Foo::class` references and FQ string
     * literals both count, exactly the runtime surface.
     *
     * @return list<non-empty-string>
     */
    public static function classRefs(string $source): array
    {
        $names = [];

        foreach (self::chains($source, ['uses']) as $chain) {
            $names = [...$names, ...$chain['root']];
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
     * @return list<array{names: list<non-empty-string>, globs: non-empty-list<non-empty-string>}>
     */
    public static function scopedRegistrations(string $source): array
    {
        $registrations = [];

        foreach (self::chains($source, ['pest', 'uses']) as $chain) {
            if ($chain['globs'] !== []) {
                $registrations[] = ['names' => array_merge($chain['root'], $chain['chained']), 'globs' => $chain['globs']];
            }
        }

        return $registrations;
    }

    /**
     * Every top-level call to one of $roots and its fluent chain: the
     * names its own arguments pass, the names `->extend()`, `->use()` and
     * `->assign()` pass, and the `->in()` globs — resolved against the
     * namespace and imports in force where the call stands.
     *
     * @param non-empty-list<non-empty-string> $roots
     *
     * @return list<array{root: list<non-empty-string>, chained: list<non-empty-string>, globs: list<non-empty-string>}>
     */
    private static function chains(string $source, array $roots): array
    {
        $tokens    = PhpToken::tokenize($source);
        $total     = count($tokens);
        $namespace = '';

        /** @var array<string, non-empty-string> $aliases alias => fully-qualified */
        $aliases = [];
        $chains  = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $next      = self::nextSignificant($tokens, $i + 1);
                $namespace = $next !== null && $tokens[$next]->is([T_STRING, T_NAME_QUALIFIED]) ? $tokens[$next]->text : $namespace;

                continue;
            }

            if ($token->is(T_USE)) {
                self::collectImport($tokens, $i + 1, $aliases);

                continue;
            }

            $open = self::rootCall($tokens, $i, $roots);

            if ($open === null) {
                continue;
            }

            $chain = ['root' => self::resolveAll(self::arguments($tokens, $open + 1), $namespace, $aliases), 'chained' => [], 'globs' => []];
            $index = self::closingParen($tokens, $open);

            // Walk the fluent chain: `-> name ( args )` until it ends.
            while ($index !== null) {
                $arrow  = self::nextSignificant($tokens, $index + 1);
                $method = $arrow === null || !$tokens[$arrow]->is(T_OBJECT_OPERATOR) ? null : self::nextSignificant($tokens, $arrow + 1);
                $args   = $method === null ? null : self::nextSignificant($tokens, $method + 1);

                if ($method === null || $args === null || $tokens[$args]->text !== '(') {
                    break;
                }

                if (in_array($tokens[$method]->text, ['extend', 'use', 'assign'], true)) {
                    $chain['chained'] = [...$chain['chained'], ...self::resolveAll(self::arguments($tokens, $args + 1), $namespace, $aliases)];
                } elseif ($tokens[$method]->text === 'in') {
                    $chain['globs'] = [...$chain['globs'], ...self::stringArguments($tokens, $args + 1)];
                }

                $index = self::closingParen($tokens, $args);
            }

            $chains[] = $chain;
            $i        = $index ?? $i;
        }

        return $chains;
    }

    /**
     * The index of the `(` when the token at $i calls one of $roots as
     * the dialect global: `uses(` or `\uses(`, never `->uses(`,
     * `::uses(` or the `function uses(` that declares somebody else's.
     *
     * @param array<PhpToken>                  $tokens
     * @param non-empty-list<non-empty-string> $roots
     */
    private static function rootCall(array $tokens, int $i, array $roots): ?int
    {
        $token = $tokens[$i];
        $name  = $token->is(T_NAME_FULLY_QUALIFIED) ? ltrim($token->text, '\\') : ($token->is(T_STRING) ? $token->text : null);

        if ($name === null || !in_array($name, $roots, true)) {
            return null;
        }

        $previous = self::previousSignificant($tokens, $i - 1);

        if ($previous !== null && $tokens[$previous]->is([T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
            return null;
        }

        $open = self::nextSignificant($tokens, $i + 1);

        return $open !== null && $tokens[$open]->text === '(' ? $open : null;
    }

    /**
     * Every string literal inside one balanced argument list.
     *
     * @param array<PhpToken> $tokens
     *
     * @return list<non-empty-string>
     */
    private static function stringArguments(array $tokens, int $start): array
    {
        $strings = [];

        foreach (self::argumentTokens($tokens, $start) as $token) {
            $literal = $token->is(T_CONSTANT_ENCAPSED_STRING) ? trim($token->text, "'\"") : '';

            if ($literal !== '') {
                $strings[] = $literal;
            }
        }

        return $strings;
    }

    /**
     * The `Name::class` and FQ-string arguments inside one balanced
     * argument list, unresolved.
     *
     * @param array<PhpToken> $tokens
     *
     * @return list<string>
     */
    private static function arguments(array $tokens, int $start): array
    {
        $names = [];

        foreach (self::argumentTokens($tokens, $start) as $index => $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $literal = trim($token->text, "'\"");

                if (str_contains($literal, '\\')) {
                    $names[] = '\\' . ltrim($literal, '\\');
                }

                continue;
            }

            $colons = $token->is(self::NAME) ? self::nextSignificant($tokens, $index + 1) : null;
            $class  = $colons !== null && $tokens[$colons]->is(T_DOUBLE_COLON) ? self::nextSignificant($tokens, $colons + 1) : null;

            if ($class !== null && $tokens[$class]->is(T_CLASS)) {
                $names[] = $token->text;
            }
        }

        return $names;
    }

    /**
     * The tokens of one balanced argument list, from $start (just past
     * its `(`) to its `)`, keyed by index.
     *
     * @param array<PhpToken> $tokens
     *
     * @return array<int, PhpToken>
     */
    private static function argumentTokens(array $tokens, int $start): array
    {
        $end = self::closingParen($tokens, $start - 1) ?? count($tokens);
        $in  = [];

        for ($i = $start; $i < $end; $i++) {
            $in[$i] = $tokens[$i];
        }

        return $in;
    }

    /**
     * The index of the parenthesis closing the one open at $open.
     *
     * @param array<PhpToken> $tokens
     */
    private static function closingParen(array $tokens, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $total = count($tokens); $i < $total; $i++) {
            if ($tokens[$i]->text === '(') {
                $depth++;
            } elseif ($tokens[$i]->text === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Each name resolved; one that resolves to nothing is dropped.
     *
     * @param list<string>                    $names
     * @param array<string, non-empty-string> $aliases
     *
     * @return list<non-empty-string>
     */
    private static function resolveAll(array $names, string $namespace, array $aliases): array
    {
        $resolved = [];

        foreach ($names as $name) {
            $name = self::resolve($name, $namespace, $aliases);

            if ($name !== '') {
                $resolved[] = $name;
            }
        }

        return $resolved;
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
     * One `use` statement into the alias map, by PHP's grammar: a comma
     * list (`use A, B as C;`) and group imports (`use A\{B, C as D};`),
     * each entry optionally aliased. Function and const imports never name
     * classes and are skipped, whole or inside a group. Trait-use inside
     * class bodies is not routed here: the dialect files this reads are
     * top-level scripts.
     *
     * @param array<PhpToken>                 $tokens
     * @param array<string, non-empty-string> $aliases
     */
    private static function collectImport(array $tokens, int $start, array &$aliases): void
    {
        $index = self::nextSignificant($tokens, $start);

        if ($index === null || $tokens[$index]->is([T_FUNCTION, T_CONST])) {
            return;
        }

        while ($index !== null && $tokens[$index]->is(self::NAME)) {
            $after = self::nextSignificant($tokens, $index + 1);
            $index = $after !== null && $tokens[$after]->is(T_NS_SEPARATOR)
                ? self::collectGroup($tokens, $after, ltrim($tokens[$index]->text, '\\') . '\\', $aliases)
                : self::collectEntry($tokens, $index, '', $aliases);

            if ($index === null || $tokens[$index]->text !== ',') {
                return;
            }

            $index = self::nextSignificant($tokens, $index + 1);
        }
    }

    /**
     * The `{…}` of a group import after its `Prefix\`: every class entry,
     * prefixed. The index after the closing brace, or null.
     *
     * @param array<PhpToken>                 $tokens
     * @param array<string, non-empty-string> $aliases
     */
    private static function collectGroup(array $tokens, int $separator, string $prefix, array &$aliases): ?int
    {
        $open = self::nextSignificant($tokens, $separator + 1);

        if ($open === null || $tokens[$open]->text !== '{') {
            return null;
        }

        $index = self::nextSignificant($tokens, $open + 1);

        while ($index !== null && $tokens[$index]->text !== '}') {
            // `use A\{function f, const C, B}`: only B names a class.
            $class = !$tokens[$index]->is([T_FUNCTION, T_CONST]);
            $entry = $class ? $index : self::nextSignificant($tokens, $index + 1);
            $index = $entry === null ? null : self::collectEntry($tokens, $entry, $prefix, $aliases, $class);

            if ($index !== null && $tokens[$index]->text === ',') {
                $index = self::nextSignificant($tokens, $index + 1);
            }
        }

        return $index === null ? null : self::nextSignificant($tokens, $index + 1);
    }

    /**
     * One `Name` or `Name as Alias` entry. The index after it, or null
     * when the token at $index is no name.
     *
     * @param array<PhpToken>                 $tokens
     * @param array<string, non-empty-string> $aliases
     */
    private static function collectEntry(array $tokens, int $index, string $prefix, array &$aliases, bool $record = true): ?int
    {
        if (!$tokens[$index]->is(self::NAME)) {
            return null;
        }

        $qualified = $prefix . ltrim($tokens[$index]->text, '\\');
        $alias     = array_last(explode('\\', $qualified));
        $next      = self::nextSignificant($tokens, $index + 1);

        if ($next !== null && $tokens[$next]->is(T_AS)) {
            $aliasToken = self::nextSignificant($tokens, $next + 1);

            if ($aliasToken !== null && $tokens[$aliasToken]->is(T_STRING)) {
                $alias = $tokens[$aliasToken]->text;
                $next  = self::nextSignificant($tokens, $aliasToken + 1);
            }
        }

        if ($record && $qualified !== '' && $alias !== '') {
            $aliases[$alias] = $qualified;
        }

        return $next;
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $start): ?int
    {
        for ($i = $start, $total = count($tokens); $i < $total; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private static function previousSignificant(array $tokens, int $start): ?int
    {
        for ($i = $start; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }
}
