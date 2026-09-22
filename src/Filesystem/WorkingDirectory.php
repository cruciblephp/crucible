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
use function str_starts_with;
use function strlen;
use function substr;

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
        return $path !== '' && str_starts_with($path, '/') ? $path : $this->path . '/' . $path;
    }

    /**
     * The counterpart: a path inside this directory, named from it.
     *
     * Anything outside is returned untouched, because a path that cannot
     * be said relatively is better said in full than said wrongly.
     */
    public function relative(string $path): string
    {
        $root    = $this->path . '/';
        $trimmed = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

        return $trimmed === '' ? $path : $trimmed;
    }

    public function equals(self $other): bool
    {
        return $this->path === $other->path;
    }
}
