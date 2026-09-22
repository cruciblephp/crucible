<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Test\TestId;

use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The observed dependency edges (D-041): which project files each
 * test FILE actually executed, derived from per-test coverage and
 * persisted in the cache directory. The G3 impact graph merges these
 * with its static edges — static analysis cannot see through dynamic
 * container lookups; execution can. File-level on both axes, the
 * Ekstazi granularity, project-relative so the file survives moves.
 * Refreshed whenever a coverage run happens; corrupt = empty, never
 * a failed run.
 */
final readonly class CoverageMap
{
    public const int VERSION = 1;

    /**
     * @param non-empty-string $root absolute project root
     *
     * @return array<string, list<string>> test file => source files, project-relative
     */
    public static function fromData(CoverageData $data, string $root): array
    {
        $prefix = $root . '/';
        $edges  = [];

        foreach ($data->tests as $testId => $files) {
            $id = TestId::fromString($testId);

            if (!$id instanceof TestId) {
                continue;
            }

            foreach (array_keys($files) as $file) {
                $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

                if ($relative !== '' && !in_array($relative, $edges[$id->file] ?? [], true)) {
                    $edges[$id->file][] = $relative;
                }
            }
        }

        ksort($edges);

        return $edges;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            return [];
        }

        $edges = [];

        foreach (is_array($decoded['edges'] ?? null) ? $decoded['edges'] : [] as $testFile => $files) {
            if (!is_string($testFile) || $testFile === '' || !is_array($files)) {
                continue;
            }

            $list = [];

            foreach ($files as $sourceFile) {
                if (is_string($sourceFile) && $sourceFile !== '') {
                    $list[] = $sourceFile;
                }
            }

            $edges[$testFile] = $list;
        }

        return $edges;
    }

    /**
     * @param array<string, list<string>> $edges
     */
    public static function save(string $file, array $edges): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($file, json_encode(
            ['version' => self::VERSION, 'edges' => $edges],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }
}
