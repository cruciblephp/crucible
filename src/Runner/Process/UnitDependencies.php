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
use function in_array;
use function is_file;
use function ltrim;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Whether a worker may discover only the files its units name, or must
 * repeat the parent's whole discovery (D-180).
 *
 * The sequential run loads every test file into one process, so a file
 * can lean on whatever another one did at load time. A worker that loads
 * only its own files sees none of it. Two checks decide, and either one
 * sends the workers to full discovery:
 *
 * - a file references a class or calls a function another file declares;
 * - a file's top level does anything but declare: a `define()`, a
 *   global, a `require`, an `ini_set()`, a top-level `const`. What one
 *   of those statements changes cannot be followed by reading names, so
 *   the rule does not try. The dialects' own registration calls
 *   (`test()`, `it()`, `describe()`, `check()` and the rest) are
 *   declarations in this sense: they register tests, as a class does.
 *
 * One coupling escapes both: a class or function another file declares,
 * named in a string and used dynamically (`$f = 'helper'; $f()`). No
 * token scan can read that name, so such a suite can still pass
 * sequentially and fail in parallel.
 */
final readonly class UnitDependencies
{
    /**
     * The top-level calls that declare tests: Pest's vocabulary as
     * PestFileSniffer reads it, `pest()`, and Crucible's own dialect.
     *
     * @var list<non-empty-string>
     */
    private const array REGISTRATION_CALLS = [
        'test', 'it', 'describe', 'uses', 'dataset', 'todo', 'covers', 'mutates',
        'beforeEach', 'afterEach', 'beforeAll', 'afterAll', 'pest',
        'check', 'property', 'table', 'visit', 'arch',
    ];

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

            if (!self::isDeclarationOnly($source)) {
                return true;
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
     * Whether the source's top level only declares: `declare`, `use`, an
     * unbraced `namespace`, class-likes, named functions, and the
     * dialects' registration calls. Anything else (an expression, a
     * `require`, a `const`, a braced namespace, inline output) answers
     * no, because what it does at load time may be what another file
     * relies on.
     */
    public static function isDeclarationOnly(string $source): bool
    {
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);
        $i      = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->is([T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY])
                || $token->text === ';'
            ) {
                $i++;

                continue;
            }

            if ($token->is(T_INLINE_HTML)) {
                if (trim($token->text) !== '') {
                    return false;
                }

                $i++;

                continue;
            }

            if ($token->is(T_ATTRIBUTE)) {
                $i = self::pastStatement($tokens, $i, untilGroupCloses: true);

                continue;
            }

            if ($token->is([T_DECLARE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $i = self::pastStatement($tokens, $i);

                continue;
            }

            if ($token->is(T_NAMESPACE)) {
                $next = self::nextSignificant($tokens, $i);

                // `namespace\name()` is a call, not a declaration, and a
                // braced namespace hides its own top level in a block.
                if ($next === null || !$tokens[$next]->is([T_STRING, T_NAME_QUALIFIED])) {
                    return false;
                }

                $after = self::nextSignificant($tokens, $next);

                if ($after === null || $tokens[$after]->text !== ';') {
                    return false;
                }

                $i = $after + 1;

                continue;
            }

            if ($token->is(T_FUNCTION)) {
                $next = self::nextSignificant($tokens, $i);

                if ($next !== null && $tokens[$next]->text === '&') {
                    $next = self::nextSignificant($tokens, $next);
                }

                if ($next === null || !$tokens[$next]->is(T_STRING)) {
                    return false;
                }

                $i = self::pastStatement($tokens, $i);

                continue;
            }

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && in_array(ltrim($token->text, '\\'), self::REGISTRATION_CALLS, true)
            ) {
                $next = self::nextSignificant($tokens, $i);

                if ($next !== null && $tokens[$next]->text === '(') {
                    $i = self::pastStatement($tokens, $i);

                    continue;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * The index after the statement starting at $start: past the `;` that
     * ends it at depth 0, or past the `}` that closes its block (a class
     * or function body). With $untilGroupCloses, past the closer of the
     * group $start opens instead (an attribute's `#[...]`).
     *
     * @param list<PhpToken> $tokens
     */
    private static function pastStatement(array $tokens, int $start, bool $untilGroupCloses = false): int
    {
        $count = count($tokens);
        $depth = 0;

        for ($j = $start; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token->is([T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0 && ($untilGroupCloses || $token->text === '}')) {
                    return $j + 1;
                }

                continue;
            }

            if ($depth === 0 && $token->text === ';') {
                return $j + 1;
            }
        }

        return $count;
    }

    /**
     * The index of the next token that is not whitespace or a comment.
     *
     * @param list<PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($j = $from + 1; $j < $count; $j++) {
            if (!$tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $j;
            }
        }

        return null;
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
