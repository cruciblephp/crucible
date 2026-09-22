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
use function in_array;
use function trim;
use function usort;

use const T_CONSTANT_ENCAPSED_STRING;
use const T_FUNCTION;
use const T_STRING;
use const T_WHITESPACE;

/**
 * Token-based locate-and-replace for exactly the shape
 * `KnownCouplingPatterns` describes: an instance/static call to a
 * known assertion method whose first argument is a plain string
 * literal. Mirrors `InlineSnapshotWriter`'s token approach (no AST
 * library — Rector is dev-only, not available to end-user projects)
 * rather than inventing a new one.
 *
 * @phpstan-type MatchInfo array{line: int, description: string, before: string, after: string}
 */
final readonly class SourceRewriter
{
    /**
     * Finds a method by name and returns its declaration-to-closing-
     * brace line span — no class resolution or autoloading needed
     * (`compat-check` only has a file path and a method name from a
     * JUnit/NDJSON key, never a loadable class). Distinguishes a real
     * method declaration from an unrelated call to the same name
     * elsewhere in the file by requiring the `function` keyword
     * (skipping whitespace and an optional by-ref `&`) immediately
     * before it. Returns null for an abstract/interface method (no
     * body) or if the method name is not found at all.
     *
     * @return ?array{0: int, 1: int} start line (the `function` keyword), end line (the closing brace)
     */
    public static function locateMethod(string $source, string $method): ?array
    {
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_STRING) || $tokens[$i]->text !== $method) {
                continue;
            }

            $j = $i - 1;

            while ($j >= 0 && ($tokens[$j]->is(T_WHITESPACE) || $tokens[$j]->text === '&')) {
                $j--;
            }

            if ($j < 0 || !$tokens[$j]->is(T_FUNCTION)) {
                continue;
            }

            $span = self::methodBodySpan($tokens, $count, $i);

            if ($span !== null) {
                return [$tokens[$i]->line, $span];
            }
        }

        return null;
    }

    /**
     * From the method-name token, skips the parameter list (and any
     * return type) to find the body's opening brace, then tracks
     * nesting to its matching close. Returns null for a body-less
     * declaration (abstract/interface method, ends in `;`).
     *
     * @param array<PhpToken> $tokens
     */
    private static function methodBodySpan(array $tokens, int $count, int $name): ?int
    {
        $parenDepth = 0;
        $seenParen  = false;
        $braceDepth = 0;

        for ($k = $name + 1; $k < $count; $k++) {
            $text = $tokens[$k]->text;

            if ($text === '(') {
                $parenDepth++;
                $seenParen = true;

                continue;
            }

            if ($text === ')') {
                $parenDepth--;

                continue;
            }

            if (!$seenParen || $parenDepth > 0) {
                continue;
            }

            if ($text === ';' && $braceDepth === 0) {
                return null;
            }

            if ($text === '{') {
                $braceDepth++;

                continue;
            }

            if ($text === '}') {
                $braceDepth--;

                if ($braceDepth === 0) {
                    return $tokens[$k]->line;
                }
            }
        }

        return null;
    }

    /**
     * What would be rewritten in [$fromLine, $toLine], without
     * touching the source — used to show the proposed fix before
     * asking for confirmation.
     *
     * @param list<CouplingPattern> $patterns
     *
     * @return list<MatchInfo>
     */
    public static function preview(string $source, int $fromLine, int $toLine, array $patterns): array
    {
        $matches = [];

        foreach (self::locateCalls(PhpToken::tokenize($source), $fromLine, $toLine, $patterns) as $call) {
            $matches[] = self::asMatchInfo($call);
        }

        return $matches;
    }

    /**
     * Rewrites every match found in [$fromLine, $toLine]. Returns the
     * source unchanged, with an empty match list, when nothing in
     * range matches a known pattern — a defensive no-op, never a
     * corrupting guess.
     *
     * @param list<CouplingPattern> $patterns
     *
     * @return array{source: string, matches: list<MatchInfo>}
     */
    public static function apply(string $source, int $fromLine, int $toLine, array $patterns): array
    {
        $tokens = PhpToken::tokenize($source);
        $calls  = self::locateCalls($tokens, $fromLine, $toLine, $patterns);

        if ($calls === []) {
            return ['source' => $source, 'matches' => []];
        }

        // Highest close-index first, same reasoning as
        // InlineSnapshotWriter: applying edits back-to-front keeps
        // earlier, not-yet-applied spans' token indices valid.
        usort($calls, static fn(array $a, array $b): int => $b['close'] <=> $a['close']);

        $skipTo = [];
        $insert = [];

        foreach ($calls as $call) {
            $skipTo[$call['nameIndex']] = $call['close'];
            $insert[$call['nameIndex']] = $call['after'];
        }

        $out   = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (isset($insert[$i])) {
                $out .= $insert[$i];
                $i = $skipTo[$i];

                continue;
            }

            $out .= $tokens[$i]->text;
        }

        $matches = [];

        foreach ($calls as $call) {
            $matches[] = self::asMatchInfo($call);
        }

        return ['source' => $out, 'matches' => $matches];
    }

    /**
     * @param array{nameIndex: int, close: int, line: int, description: string, before: string, after: string} $call
     *
     * @return MatchInfo
     */
    private static function asMatchInfo(array $call): array
    {
        return [
            'line'        => $call['line'],
            'description' => $call['description'],
            'before'      => $call['before'],
            'after'       => $call['after'],
        ];
    }

    /**
     * @param array<PhpToken>  $tokens
     * @param list<CouplingPattern> $patterns
     *
     * @return list<array{nameIndex: int, close: int, line: int, description: string, before: string, after: string}>
     */
    private static function locateCalls(array $tokens, int $fromLine, int $toLine, array $patterns): array
    {
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!$token->is(T_STRING) || $token->line < $fromLine || $token->line > $toLine) {
                continue;
            }

            if (in_array($token->text, CompositeEqualsPattern::METHODS, true)) {
                $composite = CompositeEqualsPattern::locate($tokens, $fromLine, $toLine, $i);

                if ($composite !== null) {
                    $found[] = $composite;
                }

                continue;
            }

            $applicable = [];

            foreach ($patterns as $pattern) {
                if (in_array($token->text, $pattern->methods, true)) {
                    $applicable[] = $pattern;
                }
            }

            if ($applicable === []) {
                continue;
            }

            $call = self::readCall($tokens, $count, $i);

            if ($call === null) {
                continue;
            }

            [$close, $firstArgLiteral, $restArgsRaw, $fullText] = $call;

            if ($firstArgLiteral === null) {
                continue;
            }

            $literal     = StringLiteral::unquote($firstArgLiteral);
            $restTrimmed = trim($restArgsRaw);

            foreach ($applicable as $pattern) {
                if (!($pattern->matchesLiteral)($literal, $restTrimmed)) {
                    continue;
                }

                $found[] = [
                    'nameIndex'   => $i,
                    'close'       => $close,
                    'line'        => $token->line,
                    'description' => $pattern->description,
                    'before'      => $fullText,
                    'after'       => ($pattern->rewrite)($literal, $restTrimmed),
                ];

                break;
            }
        }

        return $found;
    }

    /**
     * Reads a call starting at the method-name token $name: the
     * opening paren must follow across only whitespace, tracks
     * nesting to find the matching close, and records the first
     * top-level comma to split the first argument from the rest.
     * Returns null on any shape this cannot confidently parse — a
     * defensive no-op, never a corrupting guess.
     *
     * @param array<PhpToken> $tokens
     *
     * @return ?array{0: int, 1: ?string, 2: string, 3: string} close index, first-arg raw token text (if a plain string literal), rest-of-args raw text, full call text
     */
    private static function readCall(array $tokens, int $count, int $name): ?array
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

        $depth      = 0;
        $close      = null;
        $firstComma = null;

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
            } elseif ($text === ',' && $depth === 1 && $firstComma === null) {
                $firstComma = $i;
            }
        }

        if ($close === null) {
            return null;
        }

        $firstArgLiteral = null;

        for ($i = $open + 1; $i < ($firstComma ?? $close); $i++) {
            if ($tokens[$i]->is(T_WHITESPACE)) {
                continue;
            }

            if ($tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING)) {
                $firstArgLiteral = $tokens[$i]->text;
            }

            break;
        }

        $restArgsRaw = '';

        if ($firstComma !== null) {
            for ($i = $firstComma + 1; $i < $close; $i++) {
                $restArgsRaw .= $tokens[$i]->text;
            }
        }

        $fullText = '';

        for ($i = $name; $i <= $close; $i++) {
            $fullText .= $tokens[$i]->text;
        }

        return [$close, $firstArgLiteral, $restArgsRaw, $fullText];
    }
}
