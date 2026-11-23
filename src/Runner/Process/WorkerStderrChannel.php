<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use function bin2hex;
use function clearstatcache;
use function fclose;
use function fopen;
use function fread;
use function fseek;
use function is_resource;
use function random_bytes;
use function stream_set_blocking;
use function strlen;
use function sys_get_temp_dir;
use function touch;
use function unlink;

/**
 * The worker's stderr as the supervisor reads it. On POSIX that is the
 * pipe proc_open already gives us; on Windows it is a file, for the same
 * reason the event channel is one: the child cannot address an inherited
 * descriptor, and a pipe there cannot be read without the risk of
 * blocking on a partial line. A file read by offset also returns the
 * bytes of a write that never ended in a newline, which is exactly what
 * D-124's diagnostic needs.
 *
 * stdout is opened on the platform's null device on Windows, so the only
 * worker streams the supervisor reads there are files.
 */
final class WorkerStderrChannel
{
    /** @var ?resource */
    private $reader;

    private int $offset = 0;

    private function __construct(
        private readonly ?string $path = null,
    ) {}

    public static function forPlatform(): self
    {
        return DIRECTORY_SEPARATOR === '\\' ? self::file() : self::pipe();
    }

    public static function pipe(): self
    {
        return new self();
    }

    public static function file(): self
    {
        $path = sys_get_temp_dir() . '/crucible-worker-stderr-' . bin2hex(random_bytes(12)) . '.log';

        touch($path);

        return new self($path);
    }

    /**
     * @return array<int, array{0: string, 1: string, 2?: string}>
     */
    public function descriptors(): array
    {
        return $this->path === null
            ? [2 => ['pipe', 'w']]
            : [2 => ['file', $this->path, 'a']];
    }

    /**
     * @param array<int, resource> $pipes
     */
    public function bind(array $pipes): void
    {
        if ($this->path === null) {
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $this->reader = $pipes[2];

                stream_set_blocking($this->reader, false);
            }

            return;
        }

        $reader = fopen($this->path, 'rb');

        if ($reader !== false) {
            $this->reader = $reader;
        }
    }

    /**
     * The pipe a supervisor can select on, or null for a file.
     *
     * @return ?resource
     */
    public function selectable()
    {
        return $this->path === null ? $this->reader : null;
    }

    public function isSelectable(): bool
    {
        return $this->path === null;
    }

    /** Everything stderr holds now, bytes as written — no line requirement. */
    public function read(): string
    {
        if (!is_resource($this->reader)) {
            return '';
        }

        if ($this->path !== null) {
            clearstatcache(true, $this->path);

            if (fseek($this->reader, $this->offset) !== 0) {
                return '';
            }
        }

        $chunk = fread($this->reader, 65_536);

        if ($chunk === false || $chunk === '') {
            return '';
        }

        if ($this->path !== null) {
            $this->offset += strlen($chunk);
        }

        return $chunk;
    }

    public function close(): void
    {
        if (is_resource($this->reader)) {
            fclose($this->reader);
        }

        $this->reader = null;

        if ($this->path !== null) {
            @unlink($this->path);
        }
    }
}
