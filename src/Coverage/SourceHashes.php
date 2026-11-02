<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Coverage;

use function hash_file;
use function is_array;
use function is_file;
use function is_string;
use function ksort;

/**
 * The SHA-256 of each file a coverage map names, taken when the map is
 * written, so a reader can tell a file whose lines have moved since from
 * one the map still describes.
 */
final class SourceHashes
{
    /**
     * @param non-empty-string $root     absolute project root
     * @param iterable<string> $relative project-relative files
     *
     * @return array<string, string> relative file => hex digest; a file that cannot be read has none
     */
    public static function of(string $root, iterable $relative): array
    {
        $hashes = [];

        foreach ($relative as $file) {
            if ($file === '' || isset($hashes[$file])) {
                continue;
            }

            $absolute = $root . '/' . $file;
            $digest   = is_file($absolute) ? hash_file('sha256', $absolute) : false;

            if ($digest !== false) {
                $hashes[$file] = $digest;
            }
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * The files whose content differs now from the digest recorded, a
     * deleted file included.
     *
     * @param non-empty-string      $root
     * @param array<string, string> $hashes
     *
     * @return list<string>
     */
    public static function changed(string $root, array $hashes): array
    {
        $changed = [];

        foreach ($hashes as $file => $digest) {
            $absolute = $root . '/' . $file;

            if (!is_file($absolute) || hash_file('sha256', $absolute) !== $digest) {
                $changed[] = $file;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, string>
     */
    public static function read(mixed $decoded): array
    {
        $hashes = [];

        foreach (is_array($decoded) ? $decoded : [] as $file => $digest) {
            if (is_string($file) && $file !== '' && is_string($digest) && $digest !== '') {
                $hashes[$file] = $digest;
            }
        }

        return $hashes;
    }
}
