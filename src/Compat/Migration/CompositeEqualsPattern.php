<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use PhpToken;

use function count;
use function implode;
use function in_array;
use function str_contains;
use function trim;

use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOUBLE_ARROW;
use const T_DOUBLE_COLON;
use const T_OBJECT_OPERATOR;
use const T_STRING;
use const T_VARIABLE;
use const T_WHITESPACE;

/**
 * A second, separate detector alongside `KnownCouplingPatterns` for a
 * structurally different shape: `assertEquals($expected, $actual)`
 * where `$expected` was built earlier via
 * `$expected['someKey'] = [ 'a' => 1, 'class' => 'PHPUnit\Framework\TestCase', ... ]`
 * — the coupling is nested inside an array VALUE several statements
 * upstream, not in the comparison call's own arguments, so it needs a
 * different algorithm entirely, not just a different literal matcher
 * on `CouplingPattern`.
 *
 * Deliberately narrow, matching only the one real, verified shape
 * (Monolog's `IntrospectionProcessorTest`): both comparison arguments
 * must be bare variables, the backing assignment must be exactly
 * `$var['literalKey'] = [ ...short-array-syntax... ];`, and the call
 * itself must be reached through a simple `$this->`/`self::`/`static::`
 * prefix. Anything else — nested arrays, `array(...)` long syntax, a
 * third ($message) argument, no matching backward assignment — is
 * left alone; `SourceRewriter` reports "no automatic fix available"
 * for it, same honest default as everywhere else in this table.
 *
 * Never deletes the original `$expected = ...`/`$expected[...] = [...]`
 * statements: they become harmless dead code, which is a cosmetic
 * wart, not a correctness risk — deleting statements risks a dangling
 * reference if the variable is used elsewhere, which reading never
 * does. Only the `assertEquals(...)`/`assertSame(...)` call's own
 * text span is replaced, via the same splice mechanism
 * `SourceRewriter::apply()` already uses for single-call patterns.
 */
final readonly class CompositeEqualsPattern
{
    public const array METHODS = ['assertEquals', 'assertSame'];

    private const array COUPLED_VALUES = [
        'PHPUnit\Framework\TestCase',
        'runTest',
    ];

    /**
     * @param array<PhpToken> $tokens
     *
     * @return ?array{nameIndex: int, close: int, line: int, description: string, before: string, after: string}
     */
    public static function locate(array $tokens, int $fromLine, int $toLine, int $nameIndex): ?array
    {
        $token = $tokens[$nameIndex];

        if (!$token->is(T_STRING) || !in_array($token->text, self::METHODS, true)) {
            return null;
        }

        $count = count($tokens);
        $call  = self::readTwoArgCall($tokens, $count, $nameIndex);

        if ($call === null) {
            return null;
        }

        [$close, $expectedVar, $actualVar, $fullText] = $call;

        $assignment = self::findBackwardAssignment($tokens, $fromLine, $nameIndex, $expectedVar);

        if ($assignment === null) {
            return null;
        }

        [$offsetKeyRaw, $arrayOpen, $arrayClose] = $assignment;

        $prefix = self::callPrefix($tokens, $nameIndex);

        if ($prefix === null) {
            return null;
        }

        $pairs = self::parseArrayLiteral($tokens, $arrayOpen, $arrayClose);
        $kept  = [];

        foreach ($pairs as [$keyRaw, $valueRaw]) {
            if (self::isCoupledValue($tokens, $valueRaw[0], $valueRaw[1])) {
                continue;
            }

            $valueText = '';

            for ($i = $valueRaw[0]; $i <= $valueRaw[1]; $i++) {
                $valueText .= $tokens[$i]->text;
            }

            $kept[] = trim($valueText) . ', ' . $actualVar . '[' . $offsetKeyRaw . '][' . $keyRaw . ']';
        }

        if ($kept === []) {
            return null;
        }

        $statements = [];

        foreach ($kept as $i => $args) {
            $statements[] = ($i === 0 ? '' : $prefix) . 'assertSame(' . $args . ')';
        }

        return [
            'nameIndex'   => $nameIndex,
            'close'       => $close,
            'line'        => $token->line,
            'description' => 'Asserts equality against an array literal with a PHPUnit-internal value nested inside',
            'before'      => $fullText,
            'after'       => implode(';' . "\n" . '        ', $statements),
        ];
    }

    /**
     * Reads a 2-argument call (`name(arg1, arg2)`) starting at the
     * method-name token, requiring both arguments to be bare variable
     * references — nothing more complex is in scope. Returns null for
     * any other shape (a 3rd argument, a non-variable argument, an
     * unparseable call).
     *
     * @param array<PhpToken> $tokens
     *
     * @return ?array{0: int, 1: string, 2: string, 3: string} close index, first arg var text, second arg var text, full call text
     */
    private static function readTwoArgCall(array $tokens, int $count, int $name): ?array
    {
        $open = null;

        for ($i = $name + 1; $i < $count; $i++) {
            if ($tokens[$i]->text === '(') {
                $open = $i;

                break;
            }

            if (!$tokens[$i]->is(T_WHITESPACE)) {
                return null;
            }
        }

        if ($open === null) {
            return null;
        }

        $depth  = 0;
        $close  = null;
        $commas = [];

        for ($i = $open; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;

                if ($depth === 0) {
                    $close = $i;

                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $commas[] = $i;
            }
        }

        if ($close === null || count($commas) !== 1) {
            return null;
        }

        $arg1 = self::soleVariable($tokens, $open + 1, $commas[0] - 1);
        $arg2 = self::soleVariable($tokens, $commas[0] + 1, $close - 1);

        if ($arg1 === null || $arg2 === null) {
            return null;
        }

        $fullText = '';

        for ($i = $name; $i <= $close; $i++) {
            $fullText .= $tokens[$i]->text;
        }

        return [$close, $arg1, $arg2, $fullText];
    }

    /**
     * Whether [$from, $to] contains exactly one non-whitespace token
     * and it is a bare variable — no property access, no array
     * offset, no method call.
     *
     * @param array<PhpToken> $tokens
     */
    private static function soleVariable(array $tokens, int $from, int $to): ?string
    {
        $found = null;

        for ($i = $from; $i <= $to; $i++) {
            if ($tokens[$i]->is(T_WHITESPACE)) {
                continue;
            }

            if ($found !== null || !$tokens[$i]->is(T_VARIABLE)) {
                return null;
            }

            $found = $tokens[$i]->text;
        }

        return $found;
    }

    /**
     * The most recent `$expectedVar['literalKey'] = [ ... ];` before
     * the call, searched forward from $fromLine so "most recent"
     * naturally falls out of overwriting as later matches are found.
     *
     * @param array<PhpToken> $tokens
     *
     * @return ?array{0: string, 1: int, 2: int} the offset key's raw token text (with quotes), the array literal's open bracket index, its close bracket index
     */
    private static function findBackwardAssignment(array $tokens, int $fromLine, int $beforeIndex, string $expectedVar): ?array
    {
        $found = null;

        for ($i = 0; $i < $beforeIndex; $i++) {
            $token = $tokens[$i];

            if ($token->line < $fromLine || !$token->is(T_VARIABLE) || $token->text !== $expectedVar) {
                continue;
            }

            $match = self::readOffsetAssignment($tokens, $i, $beforeIndex);

            if ($match !== null) {
                $found = $match;
            }
        }

        return $found;
    }

    /**
     * @param array<PhpToken> $tokens
     *
     * @return ?array{0: string, 1: int, 2: int}
     */
    private static function readOffsetAssignment(array $tokens, int $variableIndex, int $limit): ?array
    {
        $count = count($tokens);
        $i     = $variableIndex + 1;

        if (($tokens[$i] ?? null)?->text !== '[') {
            return null;
        }

        $i++;

        while ($i < $count && $tokens[$i]->is(T_WHITESPACE)) {
            $i++;
        }

        if (($tokens[$i] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING) !== true) {
            return null;
        }

        $offsetKeyRaw = $tokens[$i]->text;
        $i++;

        while ($i < $count && $tokens[$i]->is(T_WHITESPACE)) {
            $i++;
        }

        if (($tokens[$i] ?? null)?->text !== ']') {
            return null;
        }

        $i++;

        while ($i < $count && $tokens[$i]->is(T_WHITESPACE)) {
            $i++;
        }

        if (($tokens[$i] ?? null)?->text !== '=') {
            return null;
        }

        $i++;

        while ($i < $count && $tokens[$i]->is(T_WHITESPACE)) {
            $i++;
        }

        if (($tokens[$i] ?? null)?->text !== '[') {
            return null;
        }

        $arrayOpen = $i;
        $depth     = 0;

        for (; $i < $limit && $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '[') {
                $depth++;
            } elseif ($text === ']') {
                $depth--;

                if ($depth === 0) {
                    return [$offsetKeyRaw, $arrayOpen, $i];
                }
            }
        }

        return null;
    }

    /**
     * Splits an array literal's content into raw key/value token-index
     * spans, tracking bracket/paren/brace depth to split only on
     * top-level commas and the first top-level `=>` per segment. A
     * segment with no `=>` (a list entry, not a key => value pair) is
     * skipped — this pattern only understands associative arrays.
     *
     * @param array<PhpToken> $tokens
     *
     * @return list<array{0: string, 1: array{0: int, 1: int}}> raw key text (with quotes), [value start index, value end index]
     */
    private static function parseArrayLiteral(array $tokens, int $open, int $close): array
    {
        $depth    = 0;
        $segStart = $open + 1;
        $pairs    = [];

        for ($i = $open + 1; $i <= $close; $i++) {
            $text = $tokens[$i]->text;

            if (in_array($text, ['[', '(', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($text, [']', ')', '}'], true)) {
                if ($i === $close) {
                    $pair = self::splitPair($tokens, $segStart, $i - 1);

                    if ($pair !== null) {
                        $pairs[] = $pair;
                    }

                    break;
                }

                $depth--;

                continue;
            }

            if ($text === ',' && $depth === 0) {
                $pair = self::splitPair($tokens, $segStart, $i - 1);

                if ($pair !== null) {
                    $pairs[] = $pair;
                }

                $segStart = $i + 1;
            }
        }

        return $pairs;
    }

    /**
     * @param array<PhpToken> $tokens
     *
     * @return ?array{0: string, 1: array{0: int, 1: int}}
     */
    private static function splitPair(array $tokens, int $from, int $to): ?array
    {
        $keyIndex = null;
        $arrow    = null;

        for ($i = $from; $i <= $to; $i++) {
            if ($tokens[$i]->is(T_WHITESPACE)) {
                continue;
            }

            if ($keyIndex === null) {
                if (!$tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING)) {
                    return null;
                }

                $keyIndex = $i;

                continue;
            }

            if ($tokens[$i]->is(T_DOUBLE_ARROW)) {
                $arrow = $i;

                break;
            }

            return null;
        }

        if ($keyIndex === null || $arrow === null) {
            return null;
        }

        $valueStart = null;

        for ($i = $arrow + 1; $i <= $to; $i++) {
            if (!$tokens[$i]->is(T_WHITESPACE)) {
                $valueStart = $i;

                break;
            }
        }

        if ($valueStart === null) {
            return null;
        }

        $valueEnd = $to;

        while ($valueEnd > $valueStart && $tokens[$valueEnd]->is(T_WHITESPACE)) {
            $valueEnd--;
        }

        return [$tokens[$keyIndex]->text, [$valueStart, $valueEnd]];
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private static function isCoupledValue(array $tokens, int $from, int $to): bool
    {
        if ($from !== $to || !$tokens[$from]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return false;
        }

        $literal = StringLiteral::unquote($tokens[$from]->text);

        return str_contains($literal, 'vendor/phpunit/phpunit') || in_array($literal, self::COUPLED_VALUES, true);
    }

    /**
     * The simple `$this->`/`self::`/`static::`/`ClassName::` text
     * immediately before the call's method-name token — captured so
     * every generated statement beyond the first (which reuses the
     * untouched original prefix) can carry its own copy. Returns null
     * for anything more complex, declining the whole pattern rather
     * than guessing.
     *
     * @param array<PhpToken> $tokens
     *
     * @return ?non-empty-string
     */
    private static function callPrefix(array $tokens, int $nameIndex): ?string
    {
        $i = $nameIndex - 1;

        while ($i >= 0 && $tokens[$i]->is(T_WHITESPACE)) {
            $i--;
        }

        if ($i < 0 || !$tokens[$i]->is(T_OBJECT_OPERATOR) && !$tokens[$i]->is(T_DOUBLE_COLON)) {
            return null;
        }

        $operatorIndex = $i;
        $i--;

        while ($i >= 0 && $tokens[$i]->is(T_WHITESPACE)) {
            $i--;
        }

        if ($i < 0 || !$tokens[$i]->is(T_VARIABLE) && !$tokens[$i]->is(T_STRING)) {
            return null;
        }

        $prefix = '';

        for ($j = $i; $j <= $operatorIndex; $j++) {
            $prefix .= $tokens[$j]->text;
        }

        return $prefix === '' ? null : $prefix;
    }
}
