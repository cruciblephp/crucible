<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Watch;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function in_array;
use function is_dir;
use function is_file;
use function str_starts_with;

/**
 * Polling file watcher: mtime+size snapshots over the watched roots,
 * diffed between polls. No inotify/FSEvents extension dependency —
 * a scan of a test suite's directories is a few milliseconds, and
 * polling is the same everywhere (deterministic, like everything
 * else here). Hidden directories, vendor/, and node_modules/ (the
 * dependency trees of both stacks, once a Vitest suite is watched)
 * are never descended into.
 */
final readonly class FileWatcher
{
    /**
     * @param list<non-empty-string> $directories absolute
     * @param list<non-empty-string> $files       absolute
     *
     * @return array<string, string> path → signature
     */
    public function snapshot(array $directories, array $files): array
    {
        $seen = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                    static fn(SplFileInfo $entry): bool => !str_starts_with($entry->getFilename(), '.')
                        && (!$entry->isDir() || !in_array($entry->getFilename(), ['vendor', 'node_modules'], true)),
                ),
            );

            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                if ($entry->isFile()) {
                    // Keyed in the OS's own separator: the iterator joins
                    // with it onto whatever the caller passed, so a
                    // '/'-joined directory would give mixed keys on Windows.
                    $seen[WorkingDirectory::native($entry->getPathname())] = $entry->getMTime() . '|' . $entry->getSize();
                }
            }
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                $info = new SplFileInfo($file);

                $seen[WorkingDirectory::native($file)] = $info->getMTime() . '|' . $info->getSize();
            }
        }

        return $seen;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     *
     * @return array{changed: list<string>, deleted: list<string>} new files count as changed
     */
    public function diff(array $before, array $after): array
    {
        $changed = [];
        $deleted = [];

        foreach ($after as $path => $signature) {
            if (($before[$path] ?? null) !== $signature) {
                $changed[] = $path;
            }
        }

        foreach (array_keys($before) as $path) {
            if (!isset($after[$path])) {
                $deleted[] = $path;
            }
        }

        return ['changed' => $changed, 'deleted' => $deleted];
    }
}
