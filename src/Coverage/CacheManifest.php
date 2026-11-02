<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Version;

use function file_get_contents;
use function file_put_contents;
use function hash_file;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function mkdir;
use function rename;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * `manifest.json` in the cache directory: which artifacts are there, the
 * version of each format, the Crucible that wrote them, and each
 * artifact's SHA-256, so a reader outside Crucible hashes this one file
 * to know whether any artifact changed. spec/cache-formats.md is the
 * contract.
 */
final class CacheManifest
{
    public const int VERSION = 1;

    public const string FILE = 'manifest.json';

    /**
     * Record an artifact just written into the directory's manifest.
     *
     * @param non-empty-string $directory absolute cache directory
     * @param non-empty-string $artifact  file name inside it
     */
    public static function record(string $directory, string $artifact, int $version): void
    {
        $digest = hash_file('sha256', $directory . '/' . $artifact);

        if ($digest === false) {
            return;
        }

        $artifacts            = self::artifacts($directory);
        $artifacts[$artifact] = ['version' => $version, 'sha256' => $digest];

        ksort($artifacts);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $partial = $directory . '/' . self::FILE . '.partial';
        $json    = json_encode(
            ['version' => self::VERSION, 'crucible' => Version::NUMBER, 'artifacts' => $artifacts],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if (@file_put_contents($partial, $json . "\n") === false || !@rename($partial, $directory . '/' . self::FILE)) {
            @unlink($partial);
        }
    }

    /**
     * The artifacts the manifest lists; a missing or corrupt manifest lists none.
     *
     * @param non-empty-string $directory
     *
     * @return array<string, array{version: int, sha256: string}>
     */
    public static function artifacts(string $directory): array
    {
        $file = $directory . '/' . self::FILE;

        if (!is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        $decoded  = $contents === false ? null : json_decode($contents, true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION || !is_array($decoded['artifacts'] ?? null)) {
            return [];
        }

        $artifacts = [];

        foreach ($decoded['artifacts'] as $name => $entry) {
            if (is_string($name) && is_array($entry) && is_int($entry['version'] ?? null) && is_string($entry['sha256'] ?? null)) {
                $artifacts[$name] = ['version' => $entry['version'], 'sha256' => $entry['sha256']];
            }
        }

        return $artifacts;
    }
}
