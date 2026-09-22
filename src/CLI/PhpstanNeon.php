<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use function array_splice;
use function explode;
use function implode;
use function preg_match;
use function str_contains;
use function str_ends_with;

/**
 * Pure neon wiring for `crucible phpstan-init` (D-049): build a fresh
 * phpstan.neon from the project's own configuration, or insert the
 * one includes line into an existing file. Editing is deliberately
 * conservative — neon is indentation-sensitive and full of user
 * comments, so a full parse-and-rewrite would need a dependency and
 * would destroy formatting on round-trip. A minimal line insertion
 * covers the real shapes; anything structurally surprising returns
 * null, and the caller prints the line for the user to place — the
 * migrate-config principle: never silently lossy.
 */
final readonly class PhpstanNeon
{
    /**
     * A fresh phpstan.neon: the extension wired, the paths taken
     * from what the crucible configuration already declares.
     *
     * @param non-empty-string       $include
     * @param list<non-empty-string> $paths
     */
    public static function create(string $include, array $paths): string
    {
        $lines = [
            'includes:',
            '    - ' . $include,
            '',
            'parameters:',
            '    # Crucible\'s own position: max, honestly earned. Lower it to',
            '    # get started on an existing suite, raise it back as the',
            '    # annotations catch up.',
            '    level: max',
            '    paths:',
        ];

        foreach ($paths as $path) {
            $lines[] = '        - ' . $path;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param non-empty-string $include
     */
    public static function alreadyWired(string $existing, string $include): bool
    {
        return str_contains($existing, $include);
    }

    /**
     * The existing file with the includes entry inserted, or null
     * when the file's shape cannot be edited safely.
     *
     * @param non-empty-string $include
     */
    public static function wire(string $existing, string $include): ?string
    {
        $lines = explode("\n", $existing);

        foreach ($lines as $index => $line) {
            // The inline list form `includes: [...]` — editable only
            // by rewriting the expression; hands off.
            if (preg_match('/^includes:\s*\[/', $line) === 1) {
                return null;
            }

            if (preg_match('/^includes:\s*(#.*)?$/', $line) !== 1) {
                continue;
            }

            // Match the indentation of the first existing entry so
            // the insertion reads as if it was always there.
            $indent = '    ';

            if (isset($lines[$index + 1]) && preg_match('/^(\s+)-\s/', $lines[$index + 1], $matches) === 1) {
                $indent = $matches[1];
            }

            array_splice($lines, $index + 1, 0, [$indent . '- ' . $include]);

            return implode("\n", $lines);
        }

        // No includes section: prepend one. Top-level key order is
        // free in neon, and a leading section above existing comments
        // parses fine.
        $prefix = "includes:\n    - " . $include . "\n\n";

        return $prefix . $existing . (str_ends_with($existing, "\n") || $existing === '' ? '' : "\n");
    }
}
