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

use function array_keys;
use function count;
use function file_get_contents;
use function is_file;
use function strrpos;
use function substr;

/**
 * Which discovered files a unit's own file needs loaded before its
 * tests run: the suite files whose declared symbols its code
 * references.
 *
 * A suite may couple files with nothing but a call - a helper
 * function declared in one test file, called from another's body, or
 * a fixture class one file extends. The sequential process satisfies
 * that by the accident of loading every discovered file into one
 * process; a worker that discovers only its own units does not, and
 * the call dies with "Call to undefined function" in a suite that
 * passes sequentially - a parallel run failing what its sequential
 * twin passes is a regression, not isolation semantics.
 *
 * The parent scans the discovery once - tokens only, no code
 * executes - and each unit's manifest names the OTHER discovered
 * files its file uses, so the worker can require them before
 * discovery without running them. The scan covers every discovered
 * file, not only the selected units: a filtered-out file's
 * declarations still satisfy the caller, because sequential
 * discovery loads the whole suite whatever the filter removed.
 *
 * Deliberately an over-approximation, the ReferenceScanner's own
 * direction: a name that matches nothing costs nothing, a surplus
 * preload only loads a file the sequential run loaded anyway, and a
 * missed coupling is the fatal. The closure is transitive and
 * ordered - a preload whose own top level uses a third file's
 * declarations gets that file first - so declarations exist before
 * the file whose top level executes against them; a cycle falls
 * back to best effort, exactly as it does in the sequential
 * process's own file order.
 */
final readonly class UnitPreloads
{
    /**
     * The preload map over one run's discovered files.
     *
     * @param list<non-empty-string>    $files as TestId carries them, in discovery's order
     *
     * @return array<string, list<non-empty-string>> each input file => the other files to require before its own discovery, in dependency order
     */
    public static function map(array $files, WorkingDirectory $workingDirectory): array
    {
        /** @var array<string, non-empty-string> $absolute input file => native absolute path */
        $absolute = [];

        /** @var array<non-empty-string, non-empty-string> $keys native absolute path => the input file form to report */
        $keys = [];

        foreach ($files as $file) {
            $native = WorkingDirectory::native($workingDirectory->absolute($file));

            if (is_file($native)) {
                $keys[$native]   = $file;
                $absolute[$file] = $native;
            }
        }

        if ($absolute === []) {
            return [];
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
                $classes[$class][] = $native;
            }

            foreach (self::declaredFunctions($source) as $name) {
                $functions[$name][] = $native;
            }

            $references[$native] = $scanner->referencesIn($source);
            $calls[$native]      = self::calledFunctions($source);
        }

        /** @var array<non-empty-string, list<non-empty-string>> $direct file => the other files its own code needs */
        $direct = [];

        foreach ($absolute as $native) {
            /** @var array<non-empty-string, true> $needs */
            $needs = [];

            foreach ($references[$native] ?? [] as $class) {
                foreach ($classes[$class] ?? [] as $declaredIn) {
                    if ($declaredIn !== $native) {
                        $needs[$declaredIn] = true;
                    }
                }
            }

            foreach ($calls[$native] ?? [] as $name) {
                foreach ($functions[$name] ?? [] as $declaredIn) {
                    if ($declaredIn !== $native) {
                        $needs[$declaredIn] = true;
                    }
                }
            }

            $direct[$native] = array_keys($needs);
        }

        $preloads = [];

        foreach ($absolute as $file => $native) {
            /** @var array<non-empty-string, true> $visited */
            $visited = [];

            /** @var list<non-empty-string> $ordered */
            $ordered = [];

            $visit = static function (string $target) use (&$visit, &$visited, &$ordered, $direct, $native): void {
                if ($target === $native || isset($visited[$target])) {
                    return;
                }

                $visited[$target] = true;

                foreach ($direct[$target] ?? [] as $need) {
                    $visit($need);
                }

                $ordered[] = $target;
            };

            foreach ($direct[$native] ?? [] as $need) {
                $visit($need);
            }

            /** @var list<non-empty-string> $filesToLoad */
            $filesToLoad = [];

            foreach ($ordered as $target) {
                $filesToLoad[] = $keys[$target];
            }

            $preloads[$file] = $filesToLoad;
        }

        return $preloads;
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

            if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
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
