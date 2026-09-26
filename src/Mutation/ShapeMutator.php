<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use Override;
use PhpToken;

use function count;
use function in_array;
use function max;

use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOUBLE_ARROW;
use const T_RETURN;

/**
 * Drops one keyed element from a returned array literal (D-134):
 * `return ['id' => $id, 'email' => $email];` becomes the same array
 * without 'email'. A survivor says what the operator mutators cannot:
 * no test checks that this key is there — the gap a DTO export, a
 * resource's toArray() or an API payload keeps quietly until a consumer
 * finds it. toMatchShape() and the shape a test states are what kill it.
 *
 * Only string keys, only top-level elements, only a literal returned
 * directly: a key computed at run time, or an array built across
 * statements, is not a shape this can read.
 */
final class ShapeMutator implements Mutator
{
    #[Override]
    public function id(): string
    {
        return 'shape:drop-key';
    }

    #[Override]
    public function mutate(array $tokens): array
    {
        $mutations = [];
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_RETURN) {
                continue;
            }

            $open = $this->next($tokens, $i + 1);

            if ($open === null || $tokens[$open]->text !== '[') {
                continue;
            }

            foreach ($this->elements($tokens, $open) as [$first, $last, $key]) {
                if ($key === null) {
                    continue;
                }

                $mutations[] = new TokenMutation($first, ' ', max(1, $tokens[$key]->line), max(1, $last - $first + 1));
            }
        }

        return $mutations;
    }

    /**
     * The top-level elements of the array literal opened at $open, each as
     * [first token, last token including its trailing comma, the string
     * key token or null].
     *
     * @param list<PhpToken> $tokens
     * @param int<0, max>    $open
     *
     * @return list<array{int<0, max>, int<0, max>, ?int<0, max>}>
     */
    private function elements(array $tokens, int $open): array
    {
        $elements = [];
        $depth    = 0;
        $start    = null;
        $key      = null;
        $arrow    = false;
        $count    = count($tokens);

        for ($i = $open + 1; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if (in_array($text, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [']', ')', '}'], true)) {
                if ($depth === 0) {
                    if ($start !== null) {
                        $elements[] = [$start, $i - 1, $arrow ? $key : null];
                    }

                    return $elements;
                }

                $depth--;
            }

            if ($depth === 0 && $text === ',') {
                if ($start !== null) {
                    $elements[] = [$start, $i, $arrow ? $key : null];
                }

                [$start, $key, $arrow] = [null, null, false];

                continue;
            }

            if ($start === null && !$tokens[$i]->isIgnorable()) {
                $start = $i;
                $key   = $tokens[$i]->id === T_CONSTANT_ENCAPSED_STRING ? $i : null;
            }

            if ($depth === 0 && $tokens[$i]->id === T_DOUBLE_ARROW) {
                $arrow = true;
            }
        }

        return [];
    }

    /**
     * @param list<PhpToken> $tokens
     * @param int<0, max>    $from
     *
     * @return ?int<0, max>
     */
    private function next(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }
}
