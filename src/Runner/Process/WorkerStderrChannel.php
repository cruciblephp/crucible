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

use const DIRECTORY_SEPARATOR;

/**
 * The worker's stderr: the POSIX pipe, or a per-worker file on Windows,
 * where a pipe cannot be read without blocking and stream_select() waits
 * on sockets only. Either way it is one stream — frames and the user's
 * text in write order — so D-124 attribution is unchanged.
 *
 * The file is read through a second handle opened by path: reading the
 * write handle would move the child's position (PHP bug #65650).
 */
final class WorkerStderrChannel
{
    private const int CHUNK = 65_536;

    /** @var ?resource */
    private $reader;

    private int $offset = 0;

    private function __construct(public readonly ?string $path = null) {}

    public static function forPlatform(): self
    {
        return DIRECTORY_SEPARATOR === '\\' ? self::file() : new self();
    }

    public static function file(): self
    {
        $path = sys_get_temp_dir() . '/crucible-worker-' . bin2hex(random_bytes(12)) . '.log';

        touch($path);

        return new self($path);
    }

    /** @return array<int, array{0: string, 1: string, 2?: string}> */
    public function descriptors(): array
    {
        return $this->path === null
            ? [2 => ['pipe', 'w']]
            : [2 => ['file', $this->path, 'a']];
    }

    /** @param array<int, resource> $pipes */
    public function bind(array $pipes): bool
    {
        if ($this->path === null) {
            $this->reader = $pipes[2] ?? null;

            if (!is_resource($this->reader)) {
                return false;
            }

            stream_set_blocking($this->reader, false);

            return true;
        }

        $reader = fopen($this->path, 'rb');

        if (!is_resource($reader)) {
            return false;
        }

        $this->reader = $reader;

        return true;
    }

    /** @return ?resource the stream to select on, or null for a file */
    public function selectable()
    {
        return $this->path === null ? $this->reader : null;
    }

    /** Everything the channel holds now, without blocking. */
    public function read(): string
    {
        if (!is_resource($this->reader)) {
            return '';
        }

        if ($this->path !== null && fseek($this->reader, $this->offset) !== 0) {
            return '';
        }

        $chunk = fread($this->reader, self::CHUNK);

        if ($chunk === false || $chunk === '') {
            return '';
        }

        if ($this->path !== null) {
            $this->offset += strlen($chunk);
        }

        return $chunk;
    }

    public function close(bool $keep = false): void
    {
        if (is_resource($this->reader)) {
            fclose($this->reader);
        }

        $this->reader = null;

        if ($this->path !== null && !$keep) {
            @unlink($this->path);
        }
    }
}
