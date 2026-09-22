<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;

use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function sort;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The deprecations a project has consciously accepted, as a versioned
 * JSON file (Symfony's bridge kept this idea in an env string; a file
 * survives review and diffs). Entries are keyed file|message — line
 * numbers shift too easily to be part of identity. Baselined
 * deprecations are suppressed at capture, so they exist nowhere on
 * the stream; removing a line from the file makes the deprecation
 * count again.
 */
final readonly class DeprecationBaseline
{
    public const int VERSION = 1;

    /**
     * @return list<non-empty-string> keys, "file|message"
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

        $keys = [];

        foreach (is_array($decoded['deprecations'] ?? null) ? $decoded['deprecations'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['file'] ?? null) || !is_string($entry['message'] ?? null)) {
                continue;
            }

            if ($entry['file'] !== '' && $entry['message'] !== '') {
                $keys[] = $entry['file'] . '|' . $entry['message'];
            }
        }

        return $keys;
    }

    /**
     * @param list<Issue>            $issues       every issue of the run; only deprecations are written
     * @param list<non-empty-string> $existingKeys entries to keep ("file|message") — updates merge, never drop
     */
    public static function save(string $file, array $issues, array $existingKeys = []): void
    {
        $entries = [];
        $seen    = [];

        foreach ($existingKeys as $key) {
            [$keyFile, $keyMessage] = explode('|', $key, 2) + [1 => ''];

            if ($keyFile !== '' && $keyMessage !== '' && !in_array($key, $seen, true)) {
                $seen[]    = $key;
                $entries[] = ['file' => $keyFile, 'message' => $keyMessage];
            }
        }

        foreach ($issues as $issue) {
            if ($issue->kind !== IssueKind::Deprecation) {
                continue;
            }

            $key = $issue->file . '|' . $issue->message;

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[]    = $key;
            $entries[] = ['file' => $issue->file, 'message' => $issue->message];
        }

        sort($entries);

        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($file, json_encode(
            ['version' => self::VERSION, 'deprecations' => $entries],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }
}
