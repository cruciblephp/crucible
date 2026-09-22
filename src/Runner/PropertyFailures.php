<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use function array_all;
use function array_slice;
use function array_unshift;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The property failure database (D-040, Hypothesis's example
 * database): shrunk counterexample choice sequences, keyed by test id
 * and property position, replayed before random cases on later runs —
 * a bug that once falsified is caught on case 1 forever, whatever
 * today's seed is. Versioned JSON in the cache directory; corrupt or
 * mismatched content is an empty database, never a failed run.
 * Workers read it; only the supervisor-side writer persists (the
 * D-021 single-writer rule).
 */
final readonly class PropertyFailures
{
    public const int VERSION = 1;

    private const int PER_KEY = 5;

    /**
     * @return array<string, list<list<int>>>
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

        $failures = [];

        foreach (is_array($decoded['failures'] ?? null) ? $decoded['failures'] : [] as $key => $sequences) {
            if (!is_string($key) || $key === '' || !is_array($sequences)) {
                continue;
            }

            $valid = [];

            foreach ($sequences as $sequence) {
                if (is_array($sequence) && array_all($sequence, static fn(mixed $choice): bool => is_int($choice))) {
                    /** @var list<int> $sequence */
                    $valid[] = $sequence;
                }
            }

            if ($valid !== []) {
                $failures[$key] = $valid;
            }
        }

        return $failures;
    }

    /**
     * Newest first, deduplicated, bounded per key.
     *
     * @param array<string, list<list<int>>> $existing
     * @param array<string, list<list<int>>> $fresh
     *
     * @return array<string, list<list<int>>>
     */
    public static function merge(array $existing, array $fresh): array
    {
        foreach ($fresh as $key => $sequences) {
            $kept = $existing[$key] ?? [];

            foreach ($sequences as $sequence) {
                if (!in_array($sequence, $kept, true)) {
                    array_unshift($kept, $sequence);
                }
            }

            $existing[$key] = array_slice($kept, 0, self::PER_KEY);
        }

        return $existing;
    }

    /**
     * @param array<string, list<list<int>>> $failures
     */
    public static function save(string $file, array $failures): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($file, json_encode(
            ['version' => self::VERSION, 'failures' => $failures],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }
}
