<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use PhpToken;

use function array_values;
use function count;
use function in_array;
use function preg_match;

/**
 * Puts the {@see Observer}'s probes into a source, token by token, every
 * line where it was: a call to `enter()` opening each named function, each
 * `return EXPR` passed through `returned()`, and the body wrapped so a call
 * that throws is told apart from one that returns. A generator is left as
 * it is, and so is a by-reference return's value.
 */
final class ProbeRewriter
{
    private const string OBSERVER = '\\' . Observer::class;

    /** @var list<PhpToken> */
    private array $tokens = [];

    /** @var array<int, string> token index => text put before it */
    private array $before = [];

    /** @var array<int, string> token index => text put after it */
    private array $after = [];

    public function rewrite(string $source): string
    {
        $this->tokens = array_values(PhpToken::tokenize($source));
        $this->clear();
        $count = count($this->tokens);

        for ($i = 0, $previous = null; $i < $count; $i++) {
            $token = $this->tokens[$i];

            // `use function name;` imports a function, it declares none.
            if ($token->is(T_FUNCTION) && $previous?->is(T_USE) !== true) {
                $i = $this->function($i);
            }

            if (!$token->isIgnorable()) {
                $previous = $token;
            }
        }

        $out = '';

        foreach ($this->tokens as $i => $token) {
            $out .= ($this->before[$i] ?? '') . $token->text . ($this->after[$i] ?? '');
        }

        return $out;
    }

    private function clear(): void
    {
        $this->before = [];
        $this->after  = [];
    }

    /** Probes the function whose keyword is at $at; the index to scan on from. */
    private function function(int $at): int
    {
        $next  = $this->significant($at + 1);
        $byRef = $this->tokens[$next]->text === '&';

        if ($byRef) {
            $next = $this->significant($next + 1);
        }

        // A closure or arrow function is no function a docblock describes.
        $named = preg_match('/^[A-Za-z_]\w*$/', $this->tokens[$next]->text) === 1;
        $close = $this->matching($this->find($next, '('));
        $body  = $close + 1;

        while ($this->tokens[$body]->text !== '{' && $this->tokens[$body]->text !== ';') {
            $body++;
        }

        if ($this->tokens[$body]->text === ';') {
            return $body;
        }

        $end = $this->matching($body);

        if (!$named) {
            return $end;
        }

        for ($k = $body; $k < $end; $k++) {
            if ($this->tokens[$k]->is([T_YIELD, T_YIELD_FROM])) {
                return $end;
            }
        }

        $this->after[$body] = ' ' . self::OBSERVER . '::enter(__CLASS__, __FUNCTION__, \\func_get_args()); try {';
        $this->before[$end] = '} catch (\\Throwable $__crucibleThrown) { ' . self::OBSERVER . '::threw(); throw $__crucibleThrown; } finally { ' . self::OBSERVER . '::leave(); } ';

        if (!$byRef) {
            $this->returns($body + 1, $end);
        }

        return $end;
    }

    private function returns(int $from, int $to): void
    {
        for ($k = $from; $k < $to; $k++) {
            $token = $this->tokens[$k];

            // A nested function's returns are its own.
            if ($token->is(T_FUNCTION)) {
                $body = $this->matching($this->find($k, '(')) + 1;

                while ($this->tokens[$body]->text !== '{' && $this->tokens[$body]->text !== ';') {
                    $body++;
                }

                $k = $this->tokens[$body]->text === '{' ? $this->matching($body) : $body;

                continue;
            }

            if (!$token->is(T_RETURN)) {
                continue;
            }

            $start = $this->significant($k + 1);

            if ($this->tokens[$start]->text === ';') {
                continue;
            }

            $stop                 = $this->statementEnd($start);
            $this->before[$start] = self::OBSERVER . '::returned((';
            $this->before[$stop]  = '))';
            $k                    = $stop;
        }
    }

    private function statementEnd(int $from): int
    {
        $depth = 0;

        for ($k = $from; ; $k++) {
            $text = $this->tokens[$k]->text;

            if (in_array($text, ['(', '[', '{'], true) || $this->tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ';' && $depth === 0) {
                return $k;
            }
        }
    }

    private function significant(int $from): int
    {
        while ($this->tokens[$from]->isIgnorable()) {
            $from++;
        }

        return $from;
    }

    private function find(int $from, string $text): int
    {
        while ($this->tokens[$from]->text !== $text) {
            $from++;
        }

        return $from;
    }

    private function matching(int $open): int
    {
        $depth = 0;

        for ($k = $open; ; $k++) {
            $text = $this->tokens[$k]->text;

            if (in_array($text, ['(', '[', '{'], true) || $this->tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ((in_array($text, [')', ']', '}'], true)) && --$depth === 0) {
                return $k;
            }
        }
    }
}
