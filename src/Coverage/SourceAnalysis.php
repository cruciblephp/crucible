<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use PhpToken;

use function array_diff_key;
use function array_keys;
use function array_pop;
use function count;
use function file_get_contents;
use function filemtime;
use function in_array;
use function is_array;
use function is_file;
use function ltrim;
use function substr_count;
use function token_get_all;

/**
 * The class and method structure of a source file, with each method's
 * cyclomatic complexity — what a line-coverage map cannot say on its
 * own, and what the CRAP index and the per-class report views need.
 *
 * Read from tokens, not reflection: the report runs in the parent after
 * a run whose workers loaded the code, so nothing about which classes
 * happen to be declared here is a safe basis for a report. Tokens are
 * also the only source that stays correct for code that never ran.
 *
 * Complexity is the standard decision-point count: one, plus every
 * branch a path could take.
 */
final readonly class SourceAnalysis
{
    /**
     * Every token that opens another independent path through a method,
     * matching the spec's set exactly (sebastian/complexity): `??` is
     * not a branch in it, `match` counts once per *arm* rather than once
     * for the construct, and a do-while's trailing `while` is the same
     * loop already counted at `do`.
     */
    private const array DECISIONS = [
        T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH,
        T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR,
    ];

    /** What a `?` follows when it opens a ternary rather than a nullable type. */
    private const array ENDS_AN_EXPRESSION = [
        T_STRING, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
        T_ARRAY, T_STRING_VARNAME, T_NUM_STRING, T_ENCAPSED_AND_WHITESPACE,
        T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
        T_CLASS_C, T_FUNC_C, T_METHOD_C, T_LINE, T_FILE, T_DIR, T_NS_C,
    ];

    /**
     * @param array<string, SourceClass> $classes keyed by fully qualified name
     */
    /**
     * @param array<string, SourceClass> $classes    keyed by fully qualified name
     * @param array{total: int, comments: int, code: int} $lines the file's line counts, as the XML report's <lines> metric wants them
     * @param array<int, true> $braceLines lines holding nothing but a closing brace, keyed by line
     */
    private function __construct(
        public array $classes,
        public array $lines = ['total' => 0, 'comments' => 0, 'code' => 0],
        public array $braceLines = [],
    ) {}

    /**
     * @param non-empty-string $file
     */
    public static function of(string $file): self
    {
        if (!is_file($file)) {
            return new self([]);
        }

        // Every report that needs method structure asks for the same
        // files, and a run may ask several times over. Keyed by mtime
        // rather than path alone: an edited file is a different file.
        $key    = $file . '@' . filemtime($file);
        $cached = SourceAnalysisCache::get($key);

        if ($cached instanceof self) {
            return $cached;
        }

        $source   = file_get_contents($file);
        $analysis = $source === false ? new self([]) : self::parse($source);

        SourceAnalysisCache::put($key, $analysis);

        return $analysis;
    }

    /**
     * The spec's --warm-coverage-cache: parse now, so the run that
     * needs the structure does not pay for it. Returns how many files
     * were analysed.
     *
     * @param list<non-empty-string> $files
     */
    public static function warm(array $files): int
    {
        $warmed = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                self::of($file);
                $warmed++;
            }
        }

        return $warmed;
    }

    public static function parse(string $source): self
    {
        /** @var list<array{int, string, int}|string> $tokens */
        $tokens = token_get_all($source);

        $namespace = '';
        $classes   = [];

        /** @var ?array{name: string, namespace: string, methods: array<string, SourceMethod>, depth: int} $current */
        $current = null;

        /** @var ?array{name: string, start: int, complexity: int, depth: int, static: bool, visibility: string} $method */
        $method = null;

        $depth     = 0;
        $modifiers = ['static' => false, 'visibility' => 'public'];

        /** @var list<array{depth: int, brackets: int}> $matchDepths brace and bracket depth inside each open match block */
        $matchDepths = [];

        // A `match` seen but not yet opened, and a `do` whose trailing
        // `while` closes the same loop rather than starting another.
        $pendingMatch = false;
        $pendingDo    = 0;

        // An arrow function's `=>` separates its body; written inside a
        // match it is not another arm.
        $pendingFn = 0;

        // `[` depth, so the `=>` of an array literal written inside a
        // match arm is not mistaken for another arm; `(` depth, so the
        // named argument `default:` is not mistaken for a case label.
        $brackets = 0;
        $parens   = 0;
        $counter  = count($tokens);

        for ($i = 0; $i < $counter; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $depth++;

                if ($pendingMatch) {
                    $matchDepths[] = ['depth' => $depth, 'brackets' => $brackets];
                    $pendingMatch  = false;
                }

                continue;
            }

            if ($token === '}') {
                if ($matchDepths !== [] && $matchDepths[count($matchDepths) - 1]['depth'] === $depth) {
                    array_pop($matchDepths);
                }

                $depth--;

                if ($method !== null && $depth === $method['depth']) {
                    $line = self::lineOf($tokens, $i);

                    /** @var array{name: string, namespace: string, methods: array<string, SourceMethod>, depth: int} $current */
                    $current['methods'][$method['name']] = new SourceMethod(
                        $method['name'],
                        $method['start'],
                        $line,
                        $method['complexity'],
                        $method['visibility'],
                        $method['static'],
                    );

                    $method = null;
                }

                if ($current !== null && $method === null && $depth === $current['depth']) {
                    $classes[self::qualify($current['namespace'], $current['name'])] = new SourceClass(
                        $current['name'],
                        $current['namespace'],
                        $current['methods'],
                    );

                    $current = null;
                }

                continue;
            }

            if ($token === '[') {
                $brackets++;

                continue;
            }

            if ($token === ']') {
                $brackets--;

                continue;
            }

            if ($token === '(') {
                $parens++;

                continue;
            }

            if ($token === ')') {
                $parens--;

                continue;
            }

            if (!is_array($token)) {
                // `?` opens a ternary when it follows a complete
                // expression, and marks a nullable type when it does
                // not — `?int $x` against `$a ? $b : $c`.
                if ($token === '?' && $method !== null && self::endsAnExpression($tokens, $i)) {
                    $method['complexity']++;
                }

                continue;
            }

            if ($method !== null && in_array($token[0], self::DECISIONS, true) && !self::isMemberName($tokens, $i)) {
                // The `while` of a do-while closes the loop `do` opened.
                if ($token[0] === T_WHILE && $pendingDo > 0) {
                    $pendingDo--;

                    continue;
                }

                $method['complexity']++;

                continue;
            }

            // An attribute opens with "#[" as one token and closes with a
            // bare "]", so its opener has to be counted the same way.
            if ($token[0] === T_ATTRIBUTE) {
                $brackets++;

                continue;
            }

            // String interpolation opens a brace with a token but closes
            // it with a bare "}", so the opener has to be counted or the
            // depth drifts negative and every later boundary is wrong.
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }

            if ($token[0] === T_DO) {
                $pendingDo++;

                continue;
            }

            if ($token[0] === T_MATCH) {
                $pendingMatch = true;

                continue;
            }

            if ($token[0] === T_FN) {
                $pendingFn++;

                continue;
            }

            // One arm, one path — but only the arms of the match itself,
            // never the `=>` of an array literal written inside one.
            if ($token[0] === T_DOUBLE_ARROW && $pendingFn > 0) {
                $pendingFn--;

                continue;
            }

            if ($token[0] === T_DOUBLE_ARROW
                && $method !== null
                && $matchDepths !== []
                && $matchDepths[count($matchDepths) - 1]['depth'] === $depth
                && $matchDepths[count($matchDepths) - 1]['brackets'] === $brackets) {
                $method['complexity']++;

                continue;
            }

            // A switch's `default` is a case like any other; a match's
            // is an arm, already counted by its `=>`; and one inside
            // parentheses is the named argument `default:`, not a label.
            if ($token[0] === T_DEFAULT
                && $method !== null
                && $parens === 0
                && !self::isMemberName($tokens, $i)
                && ($matchDepths === [] || $matchDepths[count($matchDepths) - 1]['depth'] !== $depth)) {
                $method['complexity']++;

                continue;
            }

            switch ($token[0]) {
                case T_NAMESPACE:
                    $namespace = self::nameAfter($tokens, $i);

                    break;

                case T_CLASS:
                case T_TRAIT:
                case T_INTERFACE:
                case T_ENUM:
                    // An anonymous class has no name to key a report by.
                    $name = self::nameAfter($tokens, $i);

                    if ($name !== '' && $current === null) {
                        $current = ['name' => $name, 'namespace' => $namespace, 'methods' => [], 'depth' => $depth];
                    }

                    break;

                case T_STATIC:
                    $modifiers['static'] = true;

                    break;

                case T_PUBLIC:
                case T_PROTECTED:
                case T_PRIVATE:
                    $modifiers['visibility'] = $token[1];

                    break;

                case T_FUNCTION:
                    $name = self::nameAfter($tokens, $i);

                    if ($name !== '' && $current !== null && $method === null) {
                        $method = [
                            'name'       => $name,
                            'start'      => $token[2],
                            'complexity' => 1,
                            'depth'      => $depth,
                            'static'     => $modifiers['static'],
                            'visibility' => $modifiers['visibility'],
                        ];
                    }

                    $modifiers = ['static' => false, 'visibility' => 'public'];

                    break;

                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                    break;

                default:
                    // Anything else ends a modifier run that named no
                    // function: a property, a constant, a return type.
                    if (!in_array($token[0], [T_READONLY, T_ABSTRACT, T_FINAL], true)) {
                        $modifiers = ['static' => false, 'visibility' => 'public'];
                    }
            }
        }

        $braceLines = self::braceLines($source);

        return new self(self::extendToBraces($classes, $braceLines), self::lineCounts($source, $tokens), $braceLines);
    }

    /**
     * A method ends at its closing brace, not at its last statement.
     *
     * The token walk cannot see it: token_get_all hands back a bare
     * string for `}` with no line attached, so lineOf() reports the last
     * token that had one. The implicit return lives on that brace, the
     * incumbent reports it as the method's end, and for a body that is
     * only a comment it is the sole line coverage can measure — so a
     * method whose range stops short reports itself untested.
     *
     * @param array<string, SourceClass> $classes
     * @param array<int, true>           $braceLines
     *
     * @return array<string, SourceClass>
     */
    private static function extendToBraces(array $classes, array $braceLines): array
    {
        $extended = [];

        foreach ($classes as $qualified => $class) {
            $methods = [];

            foreach ($class->methods as $name => $method) {
                $closing = null;

                foreach (array_keys($braceLines) as $line) {
                    if ($line > $method->endLine && ($closing === null || $line < $closing)) {
                        $closing = $line;
                    }
                }

                $methods[$name] = $closing === null ? $method : new SourceMethod(
                    $method->name,
                    $method->startLine,
                    $closing,
                    $method->complexity,
                    $method->visibility,
                    $method->static,
                );
            }

            $extended[$qualified] = new SourceClass($class->name, $class->namespace, $methods);
        }

        return $extended;
    }

    /**
     * Lines whose only code is a closing brace.
     *
     * Xdebug reports a function's closing brace as executable — the
     * implicit return lives there — and reports it executed. Nobody
     * wrote a statement on that line, so counting it makes a two-line
     * body measure three and inflates every ratio derived from it.
     * PhpToken rather than token_get_all: the latter hands back a bare
     * string for `}` with no line number attached.
     *
     * @return array<int, true>
     */
    private static function braceLines(string $source): array
    {
        $significant = [];
        $braces      = [];

        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML])) {
                continue;
            }

            $significant[$token->line] = ($significant[$token->line] ?? 0) + 1;

            if ($token->text === '}') {
                $braces[$token->line] = ($braces[$token->line] ?? 0) + 1;
            }
        }

        $lines = [];

        foreach ($braces as $line => $count) {
            if ($count === $significant[$line]) {
                $lines[$line] = true;
            }
        }

        return $lines;
    }

    /**
     * Total, comment and code lines — counted from the same token pass
     * rather than a second one. A line holding both code and a trailing
     * comment counts as code, which is what the incumbent's metric does.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{total: int, comments: int, code: int}
     */
    private static function lineCounts(string $source, array $tokens): array
    {
        // A trailing newline opens a last line, and it counts: the
        // incumbent reports 49 for a 48-newline file and every coverage
        // percentage is a ratio against this number.
        $total = $source === '' ? 0 : substr_count($source, "\n") + 1;

        /** @var array<int, true> $commentLines */
        $commentLines = [];

        /** @var array<int, true> $codeLines */
        $codeLines = [];

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $lines = substr_count($token[1], "\n");

            for ($line = $token[2]; $line <= $token[2] + $lines; $line++) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $commentLines[$line] = true;
                } elseif (!in_array($token[0], [T_WHITESPACE, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    $codeLines[$line] = true;
                }
            }
        }

        // A line that is both is code: the comment rode along with it.
        $comments = count(array_diff_key($commentLines, $codeLines));

        return ['total' => $total, 'comments' => $comments, 'code' => count($codeLines)];
    }

    /**
     * PHP lets a reserved word name a member, so `Suite::for(...)` and
     * `$x->match()` carry control-structure tokens that open no branch.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isMemberName(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                return false;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return in_array(
                $token[0],
                [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_CONST],
                true,
            );
        }

        return false;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function endsAnExpression(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                return in_array($token, [')', ']', '}'], true);
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return in_array($token[0], self::ENDS_AN_EXPRESSION, true);
        }

        return false;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function nameAfter(array $tokens, int $index): string
    {
        $counter = count($tokens);
        for ($i = $index + 1; $i < $counter; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                // `function (` is a closure, `class {` an anonymous one:
                // neither is something a report can name.
                return '';
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true)) {
                continue;
            }

            if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED) {
                return $token[1];
            }

            // `function &foo()` returns by reference; `namespace\` is a
            // relative name, not a declaration.
            return '';
        }

        return '';
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function lineOf(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token)) {
                return $token[2];
            }
        }

        return 0;
    }

    private static function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . ltrim($name, '\\');
    }
}
