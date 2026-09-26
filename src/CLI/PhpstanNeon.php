<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_splice;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
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
     * @param ?non-empty-string      $include null = the installer wires it
     * @param list<non-empty-string> $paths
     */
    public static function create(?string $include, array $paths): string
    {
        $lines = [
            ...self::includes($include === null ? [] : [$include]),
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
     * A configuration Crucible writes for its own analysis — type tests
     * (D-130), lint-inline's shadow files (D-052) — never for a person to
     * edit: the extension include, then each parameter, a list parameter
     * as a neon list. An empty list is left out; PHPStan reads an empty
     * `paths:` as null and refuses it.
     *
     * @param list<string>                                  $includes   none when phpstan/extension-installer loads the extension
     * @param array<non-empty-string, string|list<string>> $parameters
     * @param list<class-string>                            $rules      rules registered on their own, outside any include
     */
    public static function analysis(array $includes, array $parameters, array $rules = []): string
    {
        $lines = [...self::includes($includes), 'parameters:'];

        foreach ($parameters as $name => $value) {
            if (!is_array($value)) {
                $lines[] = '    ' . $name . ': ' . $value;

                continue;
            }

            if ($value !== []) {
                $lines[] = '    ' . $name . ':';

                foreach ($value as $item) {
                    $lines[] = '        - ' . $item;
                }
            }
        }

        if ($rules !== []) {
            $lines = [...$lines, '', 'rules:'];

            foreach ($rules as $rule) {
                $lines[] = '    - ' . $rule;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The include block. Crucible's extension is left out when
     * phpstan/extension-installer loads it (D-127) — an include written
     * as well loads it twice.
     *
     * @param list<string> $includes
     *
     * @return list<string>
     */
    private static function includes(array $includes): array
    {
        if ($includes === []) {
            return [];
        }

        $lines = ['includes:'];

        foreach ($includes as $include) {
            $lines[] = '    - ' . $include;
        }

        return [...$lines, ''];
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

    /**
     * Whether phpstan/extension-installer is installed and has not been
     * told to skip Crucible (its `ignore` list in the project's
     * composer.json). When it has, the installer loads extension.neon
     * (D-127), and a configuration that includes it as well is refused
     * by PHPStan: a file included twice.
     *
     * The working directory's vendor/, not Composer\InstalledVersions:
     * that answers for the autoloader this process started from, which
     * is the project's only when Crucible runs from the project's own
     * vendor/bin. The question here is about the project being analysed.
     */
    public static function installerLoadsExtension(WorkingDirectory $workingDirectory): bool
    {
        if (!is_dir($workingDirectory->path . '/vendor/phpstan/extension-installer')) {
            return false;
        }

        $manifest = is_file($workingDirectory->path . '/composer.json')
            ? json_decode((string) file_get_contents($workingDirectory->path . '/composer.json'), true)
            : null;
        $extra     = is_array($manifest) && is_array($manifest['extra'] ?? null) ? $manifest['extra'] : [];
        $installer = is_array($extra['phpstan/extension-installer'] ?? null) ? $extra['phpstan/extension-installer'] : [];
        $ignored   = is_array($installer['ignore'] ?? null) ? $installer['ignore'] : [];

        return !in_array('cruciblephp/crucible', $ignored, true);
    }
}
