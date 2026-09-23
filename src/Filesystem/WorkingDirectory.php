<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Filesystem;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function getcwd;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * The directory a run happens in, non-empty by construction.
 *
 * ⚠ This exists to delete a comment, and the comment was the problem.
 * `@param non-empty-string $workingDirectory` appeared 75 times across
 * 37 files, because PHP's `string` cannot carry "non-empty" and the
 * constraint therefore had to be restated at every hop. Six of the 77
 * methods taking the parameter had simply lost it — not wrong yet, but
 * one call to a narrowing helper away from being wrong, which is how it
 * bit twice on 2026-09-20.
 *
 * A constraint asserted 71 times is a constraint that is wrong
 * somewhere. Carried as a type it is checked once, here, and cannot be
 * forgotten by a signature that omits an annotation.
 *
 * Deliberately NOT Stringable: an implicit cast back to string is how a
 * value object quietly becomes a comment again. Callers needing the raw
 * path say `->path`, which is visible in review.
 *
 * ⚠ Behaviour-identical to the `Paths::absolute()` it replaces, on
 * purpose. It keeps the literal `/`, does not trim a trailing separator,
 * and resolves an empty path to `<dir>/` — a refactor this wide has to
 * be provable by the gates, so not one produced path moves.
 *
 * On Windows, and only there, both separators are read: getcwd() and
 * the filesystem answer with '\', configuration and code join with
 * '/'. An absolute path comes back in the OS's own separator, a
 * relative one — what a TestId and every report carry — always in '/',
 * so the same suite names its tests the same way on every OS. Where
 * DIRECTORY_SEPARATOR is '/' every step below is the identity.
 */
final readonly class WorkingDirectory
{
    /** @var non-empty-string */
    public string $path;

    public function __construct(string $path)
    {
        if ($path === '') {
            throw new ConfigurationException('A working directory cannot be empty.');
        }

        $this->path = $path;
    }

    /** The process's own directory, which is where a CLI run starts. */
    public static function current(): self
    {
        $cwd = getcwd();

        return new self($cwd === false ? '/' : $cwd);
    }

    /**
     * A relative path resolved against this directory; an absolute one is
     * returned untouched.
     *
     * @return non-empty-string
     */
    public function absolute(string $path): string
    {
        return self::native($path !== '' && self::isAbsolute($path) ? $path : $this->path . '/' . $path);
    }

    /**
     * The counterpart: a path inside this directory, named from it.
     *
     * Anything outside is returned untouched, because a path that cannot
     * be said relatively is better said in full than said wrongly.
     */
    public function relative(string $path): string
    {
        $root     = self::portable($this->path) . '/';
        $portable = self::portable($path);
        $trimmed  = str_starts_with($portable, $root) ? substr($portable, strlen($root)) : $path;

        return $trimmed === '' ? $path : $trimmed;
    }

    /** Rooted at '/', or on Windows at a drive (`C:\`, `C:/`) or a UNC share. */
    public static function isAbsolute(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return str_starts_with($path, '/');
        }

        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    }

    /**
     * The OS's own separator throughout — the form to compare against
     * what the filesystem and PHP itself report.
     *
     * @template T of string
     *
     * @param T $path
     *
     * @return (T is non-empty-string ? non-empty-string : string)
     */
    public static function native(string $path): string
    {
        return DIRECTORY_SEPARATOR === '/' ? $path : str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * '/' throughout — the form a name is shown and stored in.
     *
     * @template T of string
     *
     * @param T $path
     *
     * @return (T is non-empty-string ? non-empty-string : string)
     */
    public static function portable(string $path): string
    {
        return DIRECTORY_SEPARATOR === '/' ? $path : str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    public function equals(self $other): bool
    {
        return $this->path === $other->path;
    }
}
