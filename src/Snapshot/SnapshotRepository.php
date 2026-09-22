<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Snapshot;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_keys;
use function basename;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function ksort;
use function mkdir;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * Snapshot storage (D-042): one `.snap` file per test file, in a
 * `__snapshots__` directory beside it (the Jest convention — the
 * snapshots live with the tests and travel in review diffs, which is
 * the whole point of the feature). Plain text, one entry per key:
 *
 *     >>> test name » #1
 *       exported value, every line indented two spaces
 *     <<<
 *
 * The two-space indent makes the format collision-proof — a content
 * line can never read as a bare `<<<` terminator — and the parser
 * accepts fully blank lines inside an entry so editors that trim
 * trailing whitespace cannot corrupt a snapshot. Saving merges over
 * what is on disk: keys this run never visited survive, so a
 * filtered `--update-snapshots` run updates only what it ran.
 */
final class SnapshotRepository
{
    private const string HEADER = '--- crucible snapshots ---';

    /** @var array<string, array<string, string>> snap file => key => value */
    private array $entries = [];

    /** @var array<string, true> */
    private array $loaded = [];

    /** @var array<string, true> */
    private array $dirty = [];

    /**
     * @param non-empty-string $testFile         project-relative
     *
     * @return non-empty-string absolute path of the snapshot file
     */
    public static function pathFor(WorkingDirectory $workingDirectory, string $testFile): string
    {
        $name = basename($testFile);

        if (str_ends_with($name, '.php')) {
            $name = substr($name, 0, -4);
        }

        return rtrim($workingDirectory->path, '/') . '/' . dirname($testFile) . '/__snapshots__/' . $name . '.snap';
    }

    /**
     * @param non-empty-string $file
     * @param non-empty-string $key
     */
    public function get(string $file, string $key): ?string
    {
        $this->load($file);

        return $this->entries[$file][$key] ?? null;
    }

    /**
     * @param non-empty-string $file
     * @param non-empty-string $key
     */
    public function put(string $file, string $key, string $value): void
    {
        $this->load($file);

        $this->entries[$file][$key] = $value;
        $this->dirty[$file]         = true;
    }

    /**
     * Writes every dirty file, merged over the disk state.
     */
    public function flush(): void
    {
        foreach (array_keys($this->dirty) as $file) {
            $onDisk = is_file($file) ? self::parse((string) file_get_contents($file)) : [];

            $merged = [...$onDisk, ...$this->entries[$file] ?? []];

            ksort($merged);

            $directory = dirname($file);

            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }

            file_put_contents($file, self::render($merged));

            $this->entries[$file] = $merged;
        }

        $this->dirty = [];
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $entries = [];
        $key     = null;
        $lines   = [];

        foreach (explode("\n", $contents) as $line) {
            if ($key === null) {
                if (str_starts_with($line, '>>> ')) {
                    $key   = str_replace('\n', "\n", substr($line, 4));
                    $lines = [];
                }

                continue;
            }

            if ($line === '<<<') {
                if ($key !== '') {
                    $entries[$key] = implode("\n", $lines);
                }

                $key = null;

                continue;
            }

            // Content lines carry a two-space indent; a fully blank
            // line is an empty content line an editor may have
            // trimmed.
            $lines[] = str_starts_with($line, '  ') ? substr($line, 2) : '';
        }

        return $entries;
    }

    /**
     * @param array<string, string> $entries
     */
    public static function render(array $entries): string
    {
        $out = self::HEADER . "\n";

        foreach ($entries as $key => $value) {
            $out .= "\n>>> " . str_replace("\n", '\n', $key) . "\n";

            foreach (explode("\n", $value) as $line) {
                $out .= $line === '' ? "\n" : '  ' . $line . "\n";
            }

            $out .= "<<<\n";
        }

        return $out;
    }

    /**
     * @param non-empty-string $file
     */
    private function load(string $file): void
    {
        if (isset($this->loaded[$file])) {
            return;
        }

        $this->loaded[$file] = true;

        if (is_file($file)) {
            $contents = file_get_contents($file);

            $this->entries[$file] = $contents === false ? [] : self::parse($contents);
        } else {
            $this->entries[$file] = [];
        }
    }
}
