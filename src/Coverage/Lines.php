<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function array_keys;
use function is_array;
use function is_int;
use function is_string;

/**
 * Normalizes a driver's raw collection into the typed line map —
 * both extensions emit this shape already, but "the extension said
 * so" is not a type. The cost is one pass over the same data
 * record() iterates anyway.
 */
final readonly class Lines
{
    /**
     * @param array<mixed> $collected
     *
     * @return array<string, array<int, int>>
     */
    public static function normalize(array $collected): array
    {
        $normalized = [];

        foreach ($collected as $file => $lines) {
            if (!is_string($file) || $file === '' || !is_array($lines)) {
                continue;
            }

            // Under branch analysis (D-062) xdebug nests the line map
            // beside the function analysis; the flat shape stays flat.
            if (is_array($lines['lines'] ?? null)) {
                $lines = $lines['lines'];
            }

            $typed = [];

            foreach ($lines as $line => $value) {
                if (is_int($line) && is_int($value)) {
                    $typed[$line] = $value;
                }
            }

            foreach (self::redundantBraces($file, $typed) as $line) {
                unset($typed[$line]);
            }

            if ($typed !== []) {
                $normalized[$file] = $typed;
            }
        }

        return $normalized;
    }

    /**
     * The closing braces that measure nothing.
     *
     * Xdebug reports a method's closing brace as executable, because the
     * implicit return lives there, and reports it executed. Nobody wrote
     * a statement on that line, so counting it makes a two-line body
     * measure three and inflates every ratio derived from it.
     *
     * It is only redundant when the method has something else to
     * measure. A body that is empty — or only a comment — has no other
     * executable line, and there the brace is the sole evidence the
     * method ran, so it stays. That is the incumbent's boundary too,
     * arrived at by comparing both reports over the same fixture rather
     * than by reading its analyser.
     *
     * @param array<int, int> $lines
     *
     * @return list<int>
     */
    private static function redundantBraces(string $file, array $lines): array
    {
        if ($file === '' || $lines === []) {
            return [];
        }

        $analysis = SourceAnalysis::of($file);

        if ($analysis->braceLines === []) {
            return [];
        }

        $redundant = [];

        foreach ($analysis->classes as $class) {
            foreach ($class->methods as $method) {
                $brace = $method->endLine;

                if (!isset($analysis->braceLines[$brace]) || !isset($lines[$brace])) {
                    continue;
                }

                foreach (array_keys($lines) as $line) {
                    if ($line >= $method->startLine && $line < $brace) {
                        $redundant[] = $brace;

                        break;
                    }
                }
            }
        }

        return $redundant;
    }
}
