<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function hash_file;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rtrim;
use function sort;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Per-file invalidation for the dependency graph, keyed on content hash.
 *
 * Without it every run re-reads and re-scans every source file to find
 * its references; the graph memoizes only within one process, so nothing
 * survives to the next. This persists each file's resolved dependency
 * list against a hash of its contents, and a file whose bytes have not
 * changed is replayed instead of re-scanned.
 *
 * **The invariant:** the closure a run computes with this index is
 * byte-for-byte the one it computes without it. Incrementality changes
 * only how much work is skipped, never the answer. A selection feature
 * that cannot state that property is not trustworthy — its failure mode
 * is skipping a test that would have failed, which is invisible.
 *
 * **Everything stored is relative to the project root**, keys and
 * dependency lists alike, and so is everything the fingerprint is taken
 * over. An index is written on one machine and read on another — the
 * CI cache being restored into a checkout at a different absolute path
 * is the normal case, not the exotic one. Keyed on absolute paths the
 * file would load, match nothing, and silently degrade every run to a
 * cold one: no wrong answer, just the feature quietly not working
 * exactly where it was built to work.
 *
 * That is also why the index is keyed `{dir}/{fingerprint}.deps.json`,
 * one file per configuration. A dependency list is *not* a pure function
 * of the file's bytes: it also depends on the autoload map that resolves
 * a class name to a path, and on which `Pest.php` / `Datasets/*.php`
 * sit above the file (D-033). Adding a `Pest.php` changes what a test
 * file depends on without touching that file, so a cache keyed on
 * content alone would answer confidently from stale data. The
 * fingerprint carries that context, so a configuration change lands on
 * a different index rather than silently reusing the old one.
 */
final class DependencyIndex
{
    private readonly string $directory;

    /** @var array<string, array{hash: string, dependencies: list<string>}> */
    private array $stored = [];

    /** @var array<string, array{hash: string, dependencies: list<string>}> */
    private array $fresh = [];

    private readonly string $root;

    /**
     * @param non-empty-string $directory
     * @param non-empty-string $fingerprint everything but file content that the answer depends on
     * @param string           $projectRoot paths are stored relative to this, so the index survives being moved
     */
    public function __construct(string $directory, private readonly string $fingerprint, string $projectRoot = '')
    {
        $this->directory = rtrim($directory, '/');
        $this->root      = $projectRoot === '' ? '' : rtrim($projectRoot, '/') . '/';
    }

    /**
     * Project-relative when the path is inside the root, unchanged when
     * it is not. A dependency in a sibling checkout has no relative form
     * to give, and inventing one with `../..` would key the index on the
     * layout around the project rather than the project.
     */
    private function relative(string $path): string
    {
        return $this->root !== '' && str_starts_with($path, $this->root)
            ? substr($path, strlen($this->root))
            : $path;
    }

    private function absolute(string $path): string
    {
        return $this->root !== '' && !str_starts_with($path, '/') ? $this->root . $path : $path;
    }

    public function load(): void
    {
        $this->stored = [];
        $this->fresh  = [];

        $path = $this->path();

        if (!is_file($path)) {
            return;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $file => $entry) {
            if (!is_string($file) || !is_array($entry) || !isset($entry['hash'], $entry['dependencies'])) {
                continue;
            }

            $hash         = $entry['hash'];
            $dependencies = $entry['dependencies'];

            if (!is_string($hash) || !is_array($dependencies)) {
                continue;
            }

            $paths = [];

            foreach ($dependencies as $dependency) {
                if (is_string($dependency)) {
                    $paths[] = $this->absolute($dependency);
                }
            }

            $this->stored[$this->absolute($file)] = ['hash' => $hash, 'dependencies' => $paths];
        }
    }

    /**
     * The stored dependencies for $file when its bytes still hash to what
     * was recorded, otherwise null — added, changed, or never seen.
     *
     * @return ?list<string>
     */
    public function reuse(string $file): ?array
    {
        $entry = $this->stored[$file] ?? null;

        if ($entry === null) {
            return null;
        }

        $hash = $this->hash($file);

        if ($hash === null || $hash !== $entry['hash']) {
            return null;
        }

        $this->fresh[$file] = $entry;

        return $entry['dependencies'];
    }

    /**
     * @param list<string> $dependencies
     */
    public function record(string $file, array $dependencies): void
    {
        $hash = $this->hash($file);

        if ($hash === null) {
            return;
        }

        $this->fresh[$file] = ['hash' => $hash, 'dependencies' => $dependencies];
    }

    /**
     * Persist what this run actually touched. Entries for files the run
     * never asked about are dropped rather than carried forward, so a
     * deleted file cannot linger in the index and answer for itself.
     */
    public function save(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            return;
        }

        $portable = [];

        foreach ($this->fresh as $file => $entry) {
            $paths = [$file, ...$entry['dependencies']];

            // An entry naming anything outside the project root cannot be
            // made portable, so it is not written at all. Storing it with
            // the absolute path left in would replay THIS machine's path
            // on a machine that restores the cache, and a changed file
            // there would never match it — a test silently not selected,
            // which is the one failure this index must never cause.
            //
            // It is not hypothetical: a composer path repository, which
            // is how monorepos link local packages, resolves outside the
            // root. Dropping the entry costs a rescan; keeping it costs
            // correctness.
            //
            // With no root configured the index makes no portability
            // claim, so there is nothing to protect and entries are kept.
            foreach ($paths as $path) {
                if ($this->root !== '' && !str_starts_with($path, $this->root)) {
                    continue 2;
                }
            }

            $portable[$this->relative($file)] = [
                'hash'         => $entry['hash'],
                'dependencies' => array_map($this->relative(...), $entry['dependencies']),
            ];
        }

        file_put_contents(
            $this->path(),
            json_encode($portable, JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    private function hash(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $hash = hash_file('xxh128', $file);

        return $hash === false ? null : $hash;
    }

    /** @return non-empty-string */
    private function path(): string
    {
        return $this->directory . '/' . $this->fingerprint . '.deps.json';
    }

    /**
     * The context a dependency answer depends on beyond file content: the
     * autoload map that turns a class name into a path, and the suite
     * configuration files whose mere presence adds edges (D-033).
     *
     * @param list<string> $suiteConfiguration absolute paths; recorded relative, so the fingerprint is the same on every machine
     *
     * @return non-empty-string
     */
    public static function fingerprintOf(string $projectRoot, array $suiteConfiguration): string
    {
        $parts = [];
        $root  = rtrim($projectRoot, '/');

        foreach (['composer.lock', 'composer.json'] as $manifest) {
            $path = $root . '/' . $manifest;

            if (is_file($path)) {
                $hash = hash_file('xxh128', $path);

                if ($hash !== false) {
                    $parts[] = $manifest . ':' . $hash;
                }
            }
        }

        // The paths, not their contents: a Pest.php that CHANGES already
        // invalidates through its own content hash, because it is itself
        // a graph node. What content hashing cannot see is one appearing
        // or disappearing above a file that did not change.
        $prefix = $root . '/';

        foreach ($suiteConfiguration as $path) {
            $parts[] = 'suite:' . (str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path);
        }

        return hash('xxh128', implode("\n", $parts));
    }

    /**
     * Every suite-configuration file under the project root, sorted — the
     * set the fingerprint is taken over.
     *
     * @return list<string> absolute paths
     */
    public static function suiteConfigurationIn(string $projectRoot): array
    {
        $root  = rtrim($projectRoot, '/');
        $found = [];

        $patterns = [
            '/Pest.php', '/*/Pest.php', '/*/*/Pest.php', '/*/*/*/Pest.php',
            '/Datasets/*.php', '/*/Datasets/*.php', '/*/*/Datasets/*.php',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($root . $pattern);

            foreach ($matches === false ? [] : $matches as $path) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }
}
