<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Impact\ReferenceScanner;
use PhpToken;

use function count;
use function file_get_contents;
use function is_file;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Detects references to symbols declared in other discovered files.
 * A coupled suite needs the parent's discovery order in its workers.
 */
final readonly class UnitDependencies
{
    /**
     * @param list<non-empty-string> $files as TestId carries them
     */
    public static function hasCoupling(array $files, WorkingDirectory $workingDirectory): bool
    {
        /** @var array<string, non-empty-string> $absolute input file => native absolute path */
        $absolute = [];

        foreach ($files as $file) {
            $native = WorkingDirectory::native($workingDirectory->absolute($file));

            if (is_file($native)) {
                $absolute[$file] = $native;
            }
        }

        if ($absolute === []) {
            return false;
        }

        /** @var array<string, list<non-empty-string>> $classes declared class-like => declaring files */
        $classes = [];

        /** @var array<string, list<non-empty-string>> $functions declared function name => declaring files */
        $functions = [];

        /** @var array<non-empty-string, list<string>> $references class-like names each file's code references */
        $references = [];

        /** @var array<non-empty-string, list<string>> $calls function names each file's code invokes */
        $calls = [];

        $locator = new ClassLocator();
        $scanner = new ReferenceScanner();

        foreach ($absolute as $native) {
            $source = file_get_contents($native);

            if ($source === false) {
                continue;
            }

            foreach ($locator->declarationsIn($native) as $class) {
                $classes[strtolower($class)][] = $native;
            }

            foreach (self::declaredFunctions($source) as $name) {
                $functions[strtolower($name)][] = $native;
            }

            $references[$native] = $scanner->referencesIn($source);
            $calls[$native]      = self::calledFunctions($source);
        }

        foreach ($absolute as $native) {
            foreach ($references[$native] ?? [] as $class) {
                foreach ($classes[strtolower($class)] ?? [] as $declaredIn) {
                    if ($declaredIn !== $native) {
                        return true;
                    }
                }
            }

            foreach ($calls[$native] ?? [] as $name) {
                foreach ($functions[strtolower($name)] ?? [] as $declaredIn) {
                    if ($declaredIn !== $native) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Every function the source declares at file/namespace scope -
     * the only ones a call in another file can reach by name. A
     * function nested inside a class or block is not a global name.
     *
     * @return list<non-empty-string>
     */
    private static function declaredFunctions(string $source): array
    {
        $names  = [];
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);
        $depth  = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }

            if ($depth !== 0 || !$token->is(T_FUNCTION)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is(T_STRING)) {
                    if ($tokens[$j]->text !== '') {
                        $names[] = $tokens[$j]->text;
                    }

                    break;
                }

                if (!$tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    break;
                }
            }
        }

        return $names;
    }

    /**
     * Every name the source invokes as a function call: an identifier
     * directly followed by `(` that is not a member, method or
     * declaration name. Unqualified calls resolve against the current
     * namespace then the global scope, and qualified calls carry the
     * namespace - every form ends in a bare name, which is what the
     * declared-function map is keyed on.
     *
     * @return list<non-empty-string>
     */
    private static function calledFunctions(string $source): array
    {
        $names  = [];
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                continue;
            }

            // A call is `name (`. Anything else after the name - an
            // argument list of another kind, a constant use, `::` -
            // makes it something else.
            $isCall = false;

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }

                $isCall = $tokens[$j]->text === '(';

                break;
            }

            if (!$isCall) {
                continue;
            }

            // The previous significant token decides what the name
            // is: a member or method (`->name(`, `?->name(`,
            // `::name(`), the declaration itself (`function name(`),
            // a class (`new Name(`) or a constant are not function
            // calls.
            for ($k = $i - 1; $k >= 0; $k--) {
                if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }

                if ($tokens[$k]->is([
                    T_OBJECT_OPERATOR,
                    T_NULLSAFE_OBJECT_OPERATOR,
                    T_DOUBLE_COLON,
                    T_FUNCTION,
                    T_NEW,
                    T_CONST,
                    T_CLASS,
                    T_INTERFACE,
                    T_TRAIT,
                    T_ENUM,
                ])) {
                    continue 2;
                }

                break;
            }

            $text = $token->text;
            $cut  = strrpos($text, '\\');
            $bare = $cut === false ? $text : substr($text, $cut + 1);

            if ($bare !== '') {
                $names[] = $bare;
            }
        }

        return $names;
    }
}
