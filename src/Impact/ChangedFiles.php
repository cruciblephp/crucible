<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_keys;
use function explode;
use function fclose;
use function is_file;
use function is_resource;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function trim;

/**
 * The change set impact selection works from: existing changed files
 * (absolute paths) plus the names of deleted ones, which get their own
 * field because deletions defeat the graph — the files that depended
 * on a deleted file cannot be found by scanning it.
 */
final readonly class ChangedFiles
{
    /**
     * @param list<non-empty-string> $files   absolute paths of changed files that exist
     * @param list<non-empty-string> $deleted repo-relative names of deleted files
     */
    public function __construct(
        public array $files = [],
        public array $deleted = [],
    ) {}

    /**
     * The working tree's changes against a git reference: committed
     * differences, staged and unstaged modifications, plus untracked
     * files (a brand-new test must be selected, not invisible).
     *
     * @param non-empty-string $reference
     *
     * @return self|non-empty-string an error message when git cannot answer
     */
    public static function fromGit(WorkingDirectory $workingDirectory, string $reference): self|string
    {
        [$code, $output, $error] = self::git(['rev-parse', '--show-toplevel'], $workingDirectory);

        if ($code !== 0) {
            $message = trim($error);

            return $message !== '' ? $message : 'not a git repository.';
        }

        $top = trim($output);

        if ($top === '') {
            return 'git did not report a repository root.';
        }

        [$code, $output, $error] = self::git(['diff', '--name-only', '--diff-filter=d', $reference], $workingDirectory);

        if ($code !== 0) {
            $message = trim($error);

            return $message !== '' ? $message : 'git diff against "' . $reference . '" failed.';
        }

        $changed = self::lines($output);

        [$code, $output] = self::git(['diff', '--name-only', '--diff-filter=D', $reference], $workingDirectory);

        $deleted = $code === 0 ? self::lines($output) : [];

        [$code, $output] = self::git(['ls-files', '--others', '--exclude-standard'], $workingDirectory);

        if ($code === 0) {
            $changed = [...$changed, ...self::lines($output)];
        }

        /** @var array<non-empty-string, true> $files */
        $files = [];

        foreach ($changed as $name) {
            // git answers with '/' on every OS; the graph keys on the OS's own.
            $absolute = WorkingDirectory::native($top . '/' . $name);

            if (is_file($absolute)) {
                $files[$absolute] = true;
            }
        }

        return new self(array_keys($files), $deleted);
    }

    /**
     * @return list<non-empty-string>
     */
    private static function lines(string $output): array
    {
        $lines = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<non-empty-string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function git(array $arguments, WorkingDirectory $workingDirectory): array
    {
        $process = proc_open(
            ['git', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory->path,
        );

        if (!is_resource($process)) {
            return [1, '', 'git could not be started.'];
        }

        $output = stream_get_contents($pipes[1]);
        $error  = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output === false ? '' : $output, $error === false ? '' : $error];
    }
}
