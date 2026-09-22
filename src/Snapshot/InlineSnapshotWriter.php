<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Snapshot;

use PhpToken;

use function array_map;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_file;
use function str_contains;
use function str_replace;
use function trim;
use function usort;

use const T_STRING;
use const T_WHITESPACE;

/**
 * The inline-snapshot rewrite engine (D-076): the expected value lives
 * in the test source as the argument to `toMatchInlineSnapshot()` /
 * `assertMatchesInlineSnapshot()`, and under `--update-snapshots` the
 * exported value is written back into that call. Rewriting is
 * sequential-run only (Snapshots gates it) — never from a worker, so no
 * two processes touch one source file.
 *
 * Edits are buffered and applied per file at run end, highest line
 * first: a multi-line nowdoc adds lines, so rewriting bottom-up keeps
 * every not-yet-applied call's captured line valid. The reassembly is
 * exact — token text is concatenated verbatim, only the one argument
 * span swapped — so a file with no recorded snapshot is byte-untouched.
 */
final class InlineSnapshotWriter
{
    /** @var array<non-empty-string, list<array{line: int, value: string}>> pending rewrites by file */
    private static array $pending = [];

    /**
     * Queue a rewrite: at $file:$line, put $exported into the inline
     * snapshot call's argument.
     *
     * @param non-empty-string $file
     */
    public static function record(string $file, int $line, string $exported): void
    {
        self::$pending[$file][] = ['line' => $line, 'value' => $exported];
    }

    public static function hasPending(): bool
    {
        return self::$pending !== [];
    }

    public static function reset(): void
    {
        self::$pending = [];
    }

    /**
     * Apply every queued rewrite and clear the buffer. Returns how many
     * calls were rewritten. Per file, highest line first so a nowdoc's
     * added lines never invalidate an earlier call's captured position.
     */
    public static function flush(): int
    {
        $applied = 0;

        foreach (self::$pending as $file => $intents) {
            if (!is_file($file)) {
                continue;
            }

            $source = (string) file_get_contents($file);
            usort($intents, static fn(array $a, array $b): int => $b['line'] <=> $a['line']);

            foreach ($intents as $intent) {
                $rewritten = self::rewrite($source, $intent['line'], $intent['value']);

                if ($rewritten !== $source) {
                    $source = $rewritten;
                    $applied++;
                }
            }

            file_put_contents($file, $source);
        }

        self::$pending = [];

        return $applied;
    }

    /**
     * Put $exported into the inline-snapshot call whose method name
     * sits at or below $line, writing the value into the snapshot
     * argument — the whole argument for `toMatchInlineSnapshot` (the
     * value comes from `expect()`), the SECOND argument for
     * `assertMatchesInlineSnapshot` (the first is the value under test,
     * preserved). The source is returned unchanged when no such call is
     * found — a defensive no-op, never a corrupting guess.
     */
    public static function rewrite(string $source, int $line, string $exported): string
    {
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);

        // Locate the call and how many leading arguments to preserve
        // before the snapshot slot.
        $name     = null;
        $preserve = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!$token->is(T_STRING) || $token->line < $line) {
                continue;
            }

            if ($token->text === 'toMatchInlineSnapshot') {
                $name     = $i;
                $preserve = 0;

                break;
            }

            if ($token->text === 'assertMatchesInlineSnapshot') {
                $name     = $i;
                $preserve = 1;

                break;
            }
        }

        if ($name === null) {
            return $source;
        }

        // The opening paren must follow the name across only whitespace.
        $open = null;

        for ($i = $name + 1; $i < $count; $i++) {
            if ($tokens[$i]->text === '(') {
                $open = $i;

                break;
            }

            if (!$tokens[$i]->is(T_WHITESPACE)) {
                break;
            }
        }

        if ($open === null) {
            return $source;
        }

        // Match the closing paren, tracking nesting, and record every
        // top-level comma (heredocs and strings are single tokens, so
        // their contents cannot mislead the depth count).
        $depth  = 0;
        $close  = null;
        $commas = [];

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
            } elseif ($text === ',' && $depth === 1) {
                $commas[] = $i;
            }
        }

        if ($close === null) {
            return $source;
        }

        $literal = self::literal($exported);

        // Where the snapshot argument goes: replace the whole span when
        // nothing precedes it, replace after the Kth top-level comma when
        // a snapshot slot already exists, or append after the last
        // preserved argument when one does not yet.
        if ($preserve === 0) {
            $keepThrough = $open;
            $insert      = $literal;
        } elseif (count($commas) >= $preserve) {
            $keepThrough = $commas[$preserve - 1];
            $insert      = ' ' . $literal;
        } else {
            $keepThrough = $close - 1;
            $insert      = ', ' . $literal;
        }

        $out = '';

        for ($i = 0; $i < $count; $i++) {
            if ($i <= $keepThrough) {
                $out .= $tokens[$i]->text;
            }

            if ($i === $keepThrough) {
                $out .= $insert;
            }

            if ($i >= $close) {
                $out .= $tokens[$i]->text;
            }
        }

        return $out;
    }

    /**
     * The value as a PHP source literal: a single-quoted string when it
     * is one line, a nowdoc when it spans several (no interpolation,
     * round-trips exactly, and reads as itself in a diff). The nowdoc
     * marker is lengthened until no content line collides with it, so a
     * value that happens to contain the marker cannot close it early.
     */
    public static function literal(string $value): string
    {
        if (!str_contains($value, "\n")) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }

        $marker = 'SNAPSHOT';
        $lines  = array_map(trim(...), explode("\n", $value));

        while (in_array($marker, $lines, true)) {
            $marker .= '_';
        }

        return "<<<'" . $marker . "'\n" . $value . "\n" . $marker;
    }
}
