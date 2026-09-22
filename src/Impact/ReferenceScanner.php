<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use PhpToken;

use function array_keys;
use function count;
use function ctype_upper;
use function ltrim;
use function rtrim;
use function str_contains;
use function strpos;
use function substr;

/**
 * Static reference extraction for impact selection (growth G3): the
 * class-like names a PHP file mentions, resolved against its own
 * namespace and use-imports into fully qualified candidates.
 *
 * Deliberately an over-approximation — a candidate that is not a real
 * class simply resolves to no file downstream, and a surplus edge only
 * ever selects more tests, never fewer. That is the Ekstazi safety
 * direction: when in doubt, run it.
 */
final class ReferenceScanner
{
    /**
     * Tokens after which an identifier is a declaration or a member
     * name, not a type reference.
     */
    private const array NOT_A_REFERENCE_AFTER = [
        T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CONST,
        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
        T_AS, T_GOTO, T_NAMESPACE,
    ];

    private string $namespace = '';

    /** @var array<string, non-empty-string> */
    private array $aliases = [];

    /** @var array<non-empty-string, true> */
    private array $candidates = [];

    /**
     * Fully qualified class-name candidates the source references.
     *
     * @return list<non-empty-string>
     */
    public function referencesIn(string $source): array
    {
        $this->namespace  = '';
        $this->aliases    = [];
        $this->candidates = [];

        $tokens   = PhpToken::tokenize($source);
        $count    = count($tokens);
        $previous = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($token->is(T_NAMESPACE) && isset($tokens[$i + 2]) && $tokens[$i + 2]->is([T_NAME_QUALIFIED, T_STRING])) {
                $this->namespace = $tokens[$i + 2]->text;
            }

            if ($token->is(T_USE)) {
                $i = $this->consumeUse($tokens, $i, $count);

                $previous = $token;

                continue;
            }

            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])
                && (!$previous instanceof PhpToken || !$previous->is(self::NOT_A_REFERENCE_AFTER))
            ) {
                $this->collect($token);
            }

            $previous = $token;
        }

        return array_keys($this->candidates);
    }

    /**
     * One use statement: `use A\B\C;`, `use A\B\C as D;`, group form
     * `use A\B\{C, D as E};`. Registers aliases and records the
     * imported names as references (importing without using is rare,
     * and the surplus edge is safe). `use function` / `use const`
     * imports and closure `use (...)` capture lists are consumed
     * without effect — functions and constants have no classmap to
     * resolve against (a documented blind spot). A trait `use` inside
     * a class body parses identically but resolves namespace-relative,
     * so unqualified names also register a namespace-prefixed
     * candidate — for a real import that surplus candidate resolves
     * to nothing, which is the safe direction.
     *
     * @param array<PhpToken> $tokens
     */
    private function consumeUse(array $tokens, int $at, int $count): int
    {
        $i = $at + 1;

        // Skip whitespace to see what kind of use this is.
        while ($i < $count && $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $i++;
        }

        if ($i >= $count || $tokens[$i]->text === '(') {
            return $i; // closure capture list — leave it to the main loop
        }

        $classLike = !$tokens[$i]->is([T_FUNCTION, T_CONST]);
        $prefix    = '';
        $name      = '';
        $alias     = '';
        $inAlias   = false;
        $grouped   = false;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FUNCTION, T_CONST])) {
                continue;
            }

            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
                if ($inAlias) {
                    $alias = $token->text;
                } else {
                    $name .= $token->text;
                }

                continue;
            }

            // The group form tokenizes its prefix separator alone:
            // `use App\{...}` is T_STRING, T_NS_SEPARATOR, '{'.
            if ($token->is(T_NS_SEPARATOR)) {
                $name .= '\\';

                continue;
            }

            if ($token->is(T_AS)) {
                $inAlias = true;

                continue;
            }

            switch ($token->text) {
                case '{':
                    $grouped = true;
                    $prefix  = rtrim($name, '\\') . '\\';
                    $name    = '';

                    break;
                case ',':
                case '}':
                    $this->registerImport($classLike, $prefix, $name, $alias);
                    $name    = '';
                    $alias   = '';
                    $inAlias = false;

                    break;
                case ';':
                    $this->registerImport($classLike, $prefix, $name, $alias);

                    return $i;
                default:
                    // Anything unexpected: bail out of the statement.
                    return $grouped ? $i : $i - 1;
            }
        }

        return $count - 1;
    }

    private function registerImport(bool $classLike, string $prefix, string $name, string $alias): void
    {
        if ($name === '' || !$classLike) {
            return;
        }

        $qualified = ltrim($prefix . $name, '\\');

        if ($qualified === '') {
            return;
        }

        // The trait-use reading: inside a class body the same syntax
        // resolves namespace-relative.
        if ($this->namespace !== '' && $prefix === '' && !str_contains($name, '\\')) {
            $this->candidates[$this->namespace . '\\' . $name] = true;
        }

        $short = $alias !== '' ? $alias : $name;

        while (($cut = strpos($short, '\\')) !== false) {
            $short = substr($short, $cut + 1);
        }

        if ($short !== '') {
            $this->aliases[$short] = $qualified;
        }

        $this->candidates[$qualified] = true;
    }

    private function collect(PhpToken $token): void
    {
        $text = $token->text;

        if ($text === '') {
            return;
        }

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            $qualified = ltrim($text, '\\');

            if ($qualified !== '') {
                $this->candidates[$qualified] = true;
            }

            return;
        }

        // Bare identifiers: only UpperCamel ones can plausibly be
        // class references (keywords, function calls, and constants
        // that slip through resolve to no file anyway).
        if (!$token->is(T_NAME_QUALIFIED) && !ctype_upper($text[0])) {
            return;
        }

        $separator = strpos($text, '\\');
        $first     = $separator === false ? $text : substr($text, 0, $separator);
        $rest      = $separator === false ? '' : substr($text, $separator);

        if (isset($this->aliases[$first])) {
            $this->candidates[$this->aliases[$first] . $rest] = true;

            return;
        }

        // PHP resolves unqualified class names against the namespace
        // only — no global fallback inside a namespace (that rule is
        // for functions, and functions are not tracked).
        if ($this->namespace !== '') {
            $this->candidates[$this->namespace . '\\' . $text] = true;
        } else {
            $this->candidates[$text] = true;
        }
    }
}
