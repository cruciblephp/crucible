<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Filesystem\AtomicFile;
use LucianoPereira\Crucible\Test\TestId;

use function array_keys;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
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
 * Updated whenever a coverage run happens; corrupt = empty, never
 * a failed run. Version 2 adds each source file's SHA-256 beside the
 * edges (spec/cache-formats.md); a version 1 map reads as empty.
 *
 * A partial run unions what it observed into the map (D-198). It never
 * replaces an entry, because a `--filter`ed method is indistinguishable
 * from a file that merely covered fewer lines at this granularity;
 * dropping is only safe per test id. A test file that no longer exists
 * is dropped, because its edges cannot select anything.
 */
final readonly class CoverageMap
{
    public const int VERSION = 2;

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
     * The whole map: the edges and the source hashes recorded beside them.
     *
     * @return array{edges: array<string, list<string>>, hashes: array<string, string>}
     */
    public static function read(string $file): array
    {
        if (!is_file($file)) {
            return ['edges' => [], 'hashes' => []];
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return ['edges' => [], 'hashes' => []];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            return ['edges' => [], 'hashes' => []];
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

        $hashes = [];

        foreach (is_array($decoded['hashes'] ?? null) ? $decoded['hashes'] : [] as $sourceFile => $hash) {
            if (is_string($sourceFile) && $sourceFile !== '' && is_string($hash) && $hash !== '') {
                $hashes[$sourceFile] = $hash;
            }
        }

        return ['edges' => $edges, 'hashes' => $hashes];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function load(string $file): array
    {
        return self::read($file)['edges'];
    }

    /**
     * Fold this run's observed edges into the existing map (D-198).
     *
     * A partial run must not erase what it did not execute. An entry is
     * unioned rather than replaced: at file granularity a filtered method
     * is indistinguishable from a file that covered fewer lines, and
     * dropping is only safe per test id. A test file that no longer
     * exists is dropped — its edges cannot select anything — and a hash
     * the run did not read keeps its previous value.
     *
     * @param array<string, list<string>> $edges
     * @param array<string, string>       $hashes
     * @param callable(string): bool      $exists
     */
    public static function merge(string $file, array $edges, array $hashes, callable $exists): void
    {
        $existing = self::read($file);
        $merged   = $existing['edges'];

        foreach ($edges as $testFile => $sourceFiles) {
            $list = $merged[$testFile] ?? [];

            foreach ($sourceFiles as $sourceFile) {
                if (!in_array($sourceFile, $list, true)) {
                    $list[] = $sourceFile;
                }
            }

            $merged[$testFile] = $list;
        }

        foreach (array_keys($merged) as $testFile) {
            if (!$exists($testFile)) {
                unset($merged[$testFile]);
            }
        }

        ksort($merged);

        $mergedHashes = $existing['hashes'];

        foreach ($hashes as $sourceFile => $hash) {
            $mergedHashes[$sourceFile] = $hash;
        }

        ksort($mergedHashes);

        self::save($file, $merged, $mergedHashes);
    }

    /**
     * @param array<string, list<string>> $edges
     * @param array<string, string>       $hashes source file => SHA-256 when the edges were recorded
     */
    public static function save(string $file, array $edges, array $hashes = []): void
    {
        AtomicFile::write($file, json_encode(
            ['version' => self::VERSION, 'edges' => $edges, 'hashes' => $hashes],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }
}
