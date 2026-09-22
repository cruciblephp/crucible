<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\PhpUnit;

use PhpToken;

use function array_first;
use function count;
use function file_get_contents;

/**
 * Finds the fully qualified name of the first class declared in a
 * file, by token scan — no code execution, no side effects, and
 * anonymous classes (`new class`) are not mistaken for declarations.
 * Only counts a class at brace depth 0 (true file/namespace scope): a
 * `class` keyword found inside any enclosing block — a function body,
 * a closure, an `if` — is not a real top-level declaration, even
 * though it is completely legal PHP (a named fixture class scoped to
 * one test body is a real, working idiom, not a hypothetical case).
 */
final readonly class ClassLocator
{
    /**
     * The first declared class, or null. Convenience over classesIn().
     *
     * @return ?class-string
     */
    public function classIn(string $file): ?string
    {
        return array_first($this->classesIn($file));
    }

    /**
     * Every class declared at file/namespace scope, in declaration
     * order — never one nested inside a function, closure, or other
     * block.
     *
     * @return list<class-string>
     */
    public function classesIn(string $file): array
    {
        return $this->declarationsIn($file, [T_CLASS]);
    }

    /**
     * Every class, interface, trait and enum the file declares at
     * file/namespace scope.
     *
     * `classesIn()` answers a narrower question on purpose — "which
     * test class does this file hold" — and its callers depend on that.
     * An architecture rule asks the wider one: a rule about interfaces
     * cannot be written if interfaces are not in the universe it
     * reasons over.
     *
     * @param list<int> $kinds
     *
     * @return list<class-string>
     */
    public function declarationsIn(string $file, array $kinds = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return [];
        }

        $classes   = [];
        $namespace = '';
        $depth     = 0;
        $tokens    = PhpToken::tokenize($source);
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }

            if ($token->is(T_NAMESPACE)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace = $tokens[$j]->text . '\\';

                        break;
                    }

                    if ($tokens[$j]->text === ';' || $tokens[$j]->text === '{') {
                        break;
                    }
                }
            }

            if ($depth !== 0 || !$token->is($kinds)) {
                continue;
            }

            // `new class` (anonymous) and `Foo::class` are not declarations.
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }

                if ($tokens[$j]->is([T_NEW, T_DOUBLE_COLON])) {
                    continue 2;
                }

                break;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is(T_STRING)) {
                    /** @var class-string $className */
                    $className = $namespace . $tokens[$j]->text;
                    $classes[] = $className;

                    break;
                }

                if (!$tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    break;
                }
            }
        }

        return $classes;
    }
}
