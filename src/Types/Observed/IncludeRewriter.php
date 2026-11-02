<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use Closure;

use function chgrp;
use function chmod;
use function chown;
use function closedir;
use function fclose;
use function feof;
use function fflush;
use function file_exists;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function ftruncate;
use function fwrite;
use function in_array;
use function is_array;
use function is_int;
use function is_link;
use function is_resource;
use function is_string;
use function lstat;
use function mkdir;
use function opendir;
use function readdir;
use function rename;
use function rewinddir;
use function rmdir;
use function stat;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_set_write_buffer;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;
use function strlen;
use function substr;
use function touch;
use function unlink;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;
use const STREAM_META_ACCESS;
use const STREAM_META_GROUP;
use const STREAM_META_GROUP_NAME;
use const STREAM_META_OWNER;
use const STREAM_META_OWNER_NAME;
use const STREAM_META_TOUCH;
use const STREAM_MKDIR_RECURSIVE;
use const STREAM_OPTION_BLOCKING;
use const STREAM_OPTION_READ_TIMEOUT;
use const STREAM_OPTION_WRITE_BUFFER;
use const STREAM_URL_STAT_LINK;
use const STREAM_URL_STAT_QUIET;
use const STREAM_USE_PATH;

/**
 * Serves a rewritten source in place of a file PHP includes, under the
 * file's own path, so `__FILE__`, `getFileName()` and `require_once`
 * see the original: a copy under another path was declared a second time
 * the first time the runner required the file by its path. Every other
 * use of a `file://` path is passed through to PHP's own wrapper.
 *
 * @internal
 */
final class IncludeRewriter
{
    private const int OPEN_FOR_INCLUDE = 128;

    /** @var ?Closure(string): ?string the rewritten source of a path, or null to include it as it is */
    private static ?Closure $rewrite = null;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    private string $source = '';

    private int $position = 0;

    private bool $served = false;

    /** @var resource|null */
    private $directory;

    /** @param Closure(string): ?string $rewrite */
    public static function register(Closure $rewrite): void
    {
        self::$rewrite = $rewrite;

        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public static function unregister(): void
    {
        self::$rewrite = null;

        @stream_wrapper_restore('file');
    }

    /**
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    private function native(Closure $call): mixed
    {
        stream_wrapper_restore('file');

        try {
            return $call();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options): bool
    {
        if (($options & self::OPEN_FOR_INCLUDE) !== 0 && self::$rewrite instanceof Closure) {
            $rewrite   = self::$rewrite;
            $rewritten = $this->native(static fn(): ?string => $rewrite($path));

            if ($rewritten !== null) {
                $this->source = $rewritten;
                $this->served = true;

                return true;
            }
        }

        $handle = $this->native(fn() => $this->context === null ? @fopen($path, $mode, ($options & STREAM_USE_PATH) !== 0) : @fopen($path, $mode, ($options & STREAM_USE_PATH) !== 0, $this->context));

        if (!is_resource($handle)) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->served) {
            $chunk = substr($this->source, $this->position, $count);
            $this->position += strlen($chunk);

            return $chunk;
        }

        return $this->handle === null || $count < 1 ? false : fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return $this->handle === null ? false : fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return $this->served ? $this->position >= strlen($this->source) : ($this->handle === null || feof($this->handle));
    }

    public function stream_tell(): int|false
    {
        return $this->served ? $this->position : ($this->handle === null ? false : ftell($this->handle));
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if (!$this->served) {
            return $this->handle !== null && fseek($this->handle, $offset, $whence) === 0;
        }

        $this->position = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->source) + $offset,
            default  => $offset,
        };

        return true;
    }

    public function stream_flush(): bool
    {
        return $this->handle === null || fflush($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        if ($this->served) {
            return ['size' => strlen($this->source), 7 => strlen($this->source)];
        }

        return $this->handle === null ? false : fstat($this->handle);
    }

    /**
     * ✓ PHP asks with 0 whether the stream can be locked at all, a value
     * flock() refuses; the native stream answers yes.
     */
    public function stream_lock(int $operation): bool
    {
        if ($operation === 0) {
            return $this->handle !== null;
        }

        return $this->handle !== null && $operation > 0 && $operation <= 7 && flock($this->handle, $operation);
    }

    public function stream_truncate(int $size): bool
    {
        return $this->handle !== null && $size >= 0 && ftruncate($this->handle, $size);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return match ($option) {
            STREAM_OPTION_BLOCKING     => stream_set_blocking($this->handle, $arg1 !== 0),
            STREAM_OPTION_READ_TIMEOUT => stream_set_timeout($this->handle, $arg1, (int) $arg2),
            STREAM_OPTION_WRITE_BUFFER => stream_set_write_buffer($this->handle, (int) $arg2) === 0,
            default                    => false,
        };
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return $this->native(static fn(): bool => match (true) {
            $option === STREAM_META_TOUCH                                                                                 => is_array($value) ? touch($path, self::time($value[0] ?? null), self::time($value[1] ?? null)) : touch($path),
            in_array($option, [STREAM_META_OWNER_NAME, STREAM_META_OWNER], true) && (is_int($value) || is_string($value)) => chown($path, $value),
            in_array($option, [STREAM_META_GROUP_NAME, STREAM_META_GROUP], true) && (is_int($value) || is_string($value)) => chgrp($path, $value),
            $option === STREAM_META_ACCESS && is_int($value)                                                              => chmod($path, $value),
            default                                                                                                       => false,
        });
    }

    private static function time(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return $this->native(static function () use ($path, $flags): array|false {
            if (($flags & STREAM_URL_STAT_QUIET) !== 0 && !file_exists($path) && !is_link($path)) {
                return false;
            }

            return ($flags & STREAM_URL_STAT_LINK) !== 0 ? @lstat($path) : @stat($path);
        });
    }

    public function unlink(string $path): bool
    {
        return $this->native(static fn(): bool => @unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return $this->native(static fn(): bool => @rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return $this->native(static fn(): bool => @mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0));
    }

    public function rmdir(string $path): bool
    {
        return $this->native(static fn(): bool => @rmdir($path));
    }

    public function dir_opendir(string $path): bool
    {
        $directory = $this->native(static fn() => @opendir($path));

        if (!is_resource($directory)) {
            return false;
        }

        $this->directory = $directory;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return $this->directory === null ? false : readdir($this->directory);
    }

    public function dir_rewinddir(): bool
    {
        if ($this->directory === null) {
            return false;
        }

        rewinddir($this->directory);

        return true;
    }

    public function dir_closedir(): bool
    {
        if ($this->directory !== null) {
            closedir($this->directory);
            $this->directory = null;
        }

        return true;
    }
}
