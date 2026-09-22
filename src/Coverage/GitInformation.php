<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function is_dir;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function trim;

/**
 * The spec's --include-git-information: which commit a coverage report
 * describes. Read by shelling out to git rather than parsing .git,
 * because worktrees, submodules, and packed refs all make the file
 * layout a worse source of truth than the tool that owns it.
 *
 * A directory that is not a checkout, or a machine with no git, is not
 * an error: the report simply carries no git block.
 */
final readonly class GitInformation
{
    /**
     *
     * @return ?array{originUrl: ?string, branch: ?string, commit: ?string, isClean: bool}
     */
    public static function read(WorkingDirectory $workingDirectory): ?array
    {
        if (!is_dir($workingDirectory->path)) {
            return null;
        }

        $commit = self::git(['rev-parse', 'HEAD'], $workingDirectory);

        if ($commit === null) {
            return null;
        }

        return [
            'originUrl' => self::git(['config', '--get', 'remote.origin.url'], $workingDirectory),
            'branch'    => self::git(['rev-parse', '--abbrev-ref', 'HEAD'], $workingDirectory),
            'commit'    => $commit,
            'isClean'   => self::git(['status', '--porcelain'], $workingDirectory) === '',
        ];
    }

    /**
     * @param list<string>     $arguments
     */
    private static function git(array $arguments, WorkingDirectory $workingDirectory): ?string
    {
        $process = proc_open(
            ['git', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $workingDirectory->path,
        );

        if ($process === false) {
            return null;
        }

        $output = (string) stream_get_contents($pipes[1]);

        return proc_close($process) === 0 ? trim($output) : null;
    }
}
