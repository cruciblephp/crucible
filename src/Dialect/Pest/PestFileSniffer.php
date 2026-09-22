<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use PhpToken;

use function count;
use function file_get_contents;
use function in_array;
use function ltrim;

use const T_DOUBLE_COLON;
use const T_FUNCTION;
use const T_NAME_FULLY_QUALIFIED;
use const T_OBJECT_OPERATOR;
use const T_STRING;
use const T_WHITESPACE;

/**
 * Whether a file calls Pest's own real vocabulary — `test()`/`it()`/
 * `describe()`/`uses()`/`dataset()`/the hook functions — as a
 * top-level statement (brace depth 0, same convention
 * `ClassLocator` uses for a top-level class). Deliberately excludes
 * Crucible-native extras (`check()`, `property()`, `table()`,
 * `visit()`, `arch()`): those already have their own explicit
 * `.crucible.php` suffix and mixing them in here would blur what this
 * check is actually verifying — that a file is written in REAL Pest's
 * documented vocabulary, the shape a real Pest project's files
 * actually have (confirmed against `spatie/laravel-data`'s real test
 * suite: `*Test.php` filenames, no `.pest.php` in sight).
 *
 * A `(` must immediately follow (skipping whitespace) for the name to
 * count as a call, and it must not be preceded by `->`/`::`/`function`
 * — a method call or a user's own re-declaration of the same name is
 * not Pest's own entry point. Token scan only, no code execution.
 */
final readonly class PestFileSniffer
{
    private const array ENTRY_FUNCTIONS = [
        'test', 'it', 'describe', 'uses', 'dataset', 'todo', 'covers', 'mutates',
        'beforeEach', 'afterEach', 'beforeAll', 'afterAll',
    ];

    public function hasTopLevelCalls(string $file): bool
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return false;
        }

        $depth  = 0;
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '{') {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                continue;
            }

            if ($depth !== 0 || $this->entryFunctionName($token) === null) {
                continue;
            }

            if ($this->isCallHere($tokens, $count, $i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bare entry-function name a token spells, or null. A
     * pint-style `\it(...)` call — the leading-backslash form
     * `native_function_invocation` produces, the same convention this
     * project's own real .pest.php files use — tokenizes as one
     * T_NAME_FULLY_QUALIFIED token (`\it`), not T_STRING; both forms
     * name the same global function.
     */
    private function entryFunctionName(PhpToken $token): ?string
    {
        if ($token->is(T_STRING)) {
            return in_array($token->text, self::ENTRY_FUNCTIONS, true) ? $token->text : null;
        }

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            $name = ltrim($token->text, '\\');

            return in_array($name, self::ENTRY_FUNCTIONS, true) ? $name : null;
        }

        return null;
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private function isCallHere(array $tokens, int $count, int $i): bool
    {
        $before = $i - 1;

        while ($before >= 0 && $tokens[$before]->is(T_WHITESPACE)) {
            $before--;
        }

        if ($before >= 0 && $tokens[$before]->is([T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
            return false;
        }

        $after = $i + 1;

        while ($after < $count && $tokens[$after]->is(T_WHITESPACE)) {
            $after++;
        }

        return $after < $count && $tokens[$after]->text === '(';
    }
}
