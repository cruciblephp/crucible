<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function unlink;

use const JSON_PRETTY_PRINT;

/**
 * The revert mechanism `compat-check --auto-fix` needs and nothing
 * else in Crucible has today (`InlineSnapshotWriter::flush()` writes
 * directly with no backup at all). Full-file backup, not a diff or
 * patch: the simplest thing that is actually safe to restore from.
 */
final readonly class FixManifest
{
    /**
     * @param non-empty-string $path
     */
    public function __construct(
        private string $path,
    ) {}

    /**
     * Records $file's content before it is overwritten — a no-op if
     * this file already has a backup, so re-fixing an already-fixed
     * file never overwrites the manifest with the already-rewritten
     * content (the FIRST backup is the one worth keeping).
     *
     * @param non-empty-string $file
     */
    public function backup(string $file, string $originalContent): void
    {
        $manifest = $this->read();

        if (isset($manifest[$file])) {
            return;
        }

        $manifest[$file] = $originalContent;

        $this->write($manifest);
    }

    /**
     * Restores every recorded file to its backed-up content and
     * clears the manifest.
     *
     * @return list<non-empty-string> files restored
     */
    public function revert(): array
    {
        $manifest = $this->read();
        $restored = [];

        foreach ($manifest as $file => $original) {
            file_put_contents($file, $original);

            /** @var non-empty-string $file */
            $restored[] = $file;
        }

        if (file_exists($this->path)) {
            unlink($this->path);
        }

        return $restored;
    }

    public function isEmpty(): bool
    {
        return $this->read() === [];
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (!is_array($decoded)) {
            return [];
        }

        $manifest = [];

        foreach ($decoded as $file => $content) {
            if (is_string($file) && $file !== '' && is_string($content)) {
                $manifest[$file] = $content;
            }
        }

        return $manifest;
    }

    /**
     * @param array<non-empty-string, string> $manifest
     */
    private function write(array $manifest): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($this->path, (string) json_encode($manifest, JSON_PRETTY_PRINT));
    }
}
