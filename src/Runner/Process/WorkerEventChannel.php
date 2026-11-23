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
use function getenv;
use function is_resource;
use function is_string;
use function putenv;
use function random_bytes;
use function str_starts_with;
use function stream_set_blocking;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function touch;
use function unlink;

/**
 * The channel a worker writes its NDJSON events to. It is deliberately
 * NOT shared with anything the project prints: on POSIX it is descriptor
 * 3, and on Windows — where PHP's proc_open hands the child an inherited
 * handle it cannot address by number — it is a file the supervisor reads
 * by offset. stdout and stderr stay the project's, so no user write can
 * ever be mistaken for a frame.
 */
final class WorkerEventChannel
{
    /** The one environment variable that carries the channel to the worker. */
    public const string ENV = 'CRUCIBLE_WORKER_EVENTS';

    private const string PIPE_SPEC = 'fd:3';

    private const string FILE_PREFIX = 'file:';

    /** @var ?resource */
    private $reader;

    private int $offset = 0;

    private function __construct(
        private readonly string $spec,
        private readonly ?string $path = null,
    ) {}

    /** A pipe on POSIX; a file where a third descriptor cannot be addressed. */
    public static function forPlatform(): self
    {
        return DIRECTORY_SEPARATOR === '\\' ? self::file() : self::pipe();
    }

    public static function pipe(): self
    {
        return new self(self::PIPE_SPEC);
    }

    public static function file(): self
    {
        $path = sys_get_temp_dir() . '/crucible-worker-events-' . bin2hex(random_bytes(12)) . '.ndjson';

        touch($path);

        return new self(self::FILE_PREFIX . $path, $path);
    }

    /**
     * The descriptors proc_open must pass to the child, beyond 0–2.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function descriptors(): array
    {
        return $this->path === null ? [3 => ['pipe', 'w']] : [];
    }

    /**
     * @param array<int, resource> $pipes the pipes proc_open produced
     */
    public function bind(array $pipes): void
    {
        if ($this->path === null) {
            if (isset($pipes[3]) && is_resource($pipes[3])) {
                $this->reader = $pipes[3];

                stream_set_blocking($this->reader, false);
            }

            return;
        }

        $reader = fopen($this->path, 'rb');

        if ($reader !== false) {
            $this->reader = $reader;
        }
    }

    public function spec(): string
    {
        return $this->spec;
    }

    /**
     * The pipe a supervisor can select on, or null for a file (which
     * stream_select cannot wait on).
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

    /** Everything the channel holds now, without blocking. */
    public function read(): string
    {
        if (!is_resource($this->reader)) {
            return '';
        }

        if ($this->path !== null) {
            // The worker appends; re-stat so a read at the old offset
            // sees the bytes it wrote since. Windows caches file size.
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

    /**
     * The worker side: the stream named by the environment, or null when
     * none was set (a manually started `crucible --worker`, whose events
     * go to stdout as they always did).
     *
     * @return ?resource
     */
    public static function openFromEnvironment()
    {
        $spec = getenv(self::ENV);

        // One worker, one channel: a process this worker starts must not
        // inherit the handle and write its own events into it.
        putenv(self::ENV);

        if (!is_string($spec) || $spec === '') {
            return null;
        }

        if ($spec === self::PIPE_SPEC) {
            $stream = fopen('php://fd/3', 'wb');

            return $stream === false ? null : $stream;
        }

        if (str_starts_with($spec, self::FILE_PREFIX)) {
            $stream = fopen(substr($spec, strlen(self::FILE_PREFIX)), 'wb');

            return $stream === false ? null : $stream;
        }

        return null;
    }
}
