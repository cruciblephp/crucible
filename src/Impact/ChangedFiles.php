<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Impact;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_key_last;
use function array_keys;
use function array_pop;
use function count;
use function explode;
use function fclose;
use function file_exists;
use function is_link;
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
     * @param list<non-empty-string> $files   absolute paths of changed entries that exist
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
     * Every query runs from the repository root, because git answers
     * `ls-files` relative to the current directory: from a nested one,
     * an untracked file above it would be listed under a path joined to
     * the wrong parent — or not listed at all. `-z` keeps filenames
     * byte-exact (spaces, quotes, newlines, non-ASCII), and
     * `--no-renames` turns a rename into the deletion and addition it
     * really is, so both halves reach the selection.
     *
     * @param non-empty-string $reference
     *
     * @return self|non-empty-string an error message when git cannot answer
     */
    public static function fromGit(WorkingDirectory $workingDirectory, string $reference): self|string
    {
        [$code, $output, $error] = self::git(['rev-parse', '--show-toplevel'], $workingDirectory->path);

        if ($code !== 0) {
            $message = trim($error);

            return $message !== '' ? $message : 'not a git repository.';
        }

        $top = trim($output);

        if ($top === '') {
            return 'git did not report a repository root.';
        }

        [$code, $output, $error] = self::git(
            ['diff', '--name-status', '-z', '--no-renames', $reference, '--'],
            $top,
        );

        if ($code !== 0) {
            $message = trim($error);

            return $message !== '' ? $message : 'git diff against "' . $reference . '" failed.';
        }

        $changed = [];
        /** @var array<non-empty-string, true> $deleted */
        $deleted = [];
        $fields  = self::fields($output);

        // -z pairs each status with its path, in that order.
        for ($index = 0; $index + 1 < count($fields); $index += 2) {
            $status = $fields[$index];
            $name   = $fields[$index + 1];

            if ($name === '') {
                continue;
            }

            if ($status === 'D') {
                $deleted[$name] = true;

                continue;
            }

            $changed[] = $name;
        }

        [$code, $output, $error] = self::git(
            ['ls-files', '--others', '--exclude-standard', '--full-name', '-z'],
            $top,
        );

        if ($code !== 0) {
            $message = trim($error);

            return $message !== '' ? $message : 'git ls-files could not list untracked files.';
        }

        foreach (self::fields($output) as $name) {
            $changed[] = $name;
        }

        /** @var array<non-empty-string, true> $files */
        $files = [];

        foreach ($changed as $name) {
            // git answers with '/' on every OS; the graph keys on the OS's own.
            $absolute = WorkingDirectory::native($top . '/' . $name);

            // file_exists() covers directories, which is what a gitlink
            // (submodule) change is. A symlink is an entry even when its
            // target is gone.
            if (file_exists($absolute) || is_link($absolute)) {
                $files[$absolute] = true;
            }
        }

        return new self(array_keys($files), array_keys($deleted));
    }

    /**
     * The NUL-separated fields of a `-z` answer, verbatim: no trimming,
     * no newline splitting — a filename may contain either.
     *
     * @return list<string>
     */
    private static function fields(string $output): array
    {
        $fields = explode("\0", $output);

        // Every record is NUL-terminated, so the split (which always
        // yields at least one field) ends with one empty field; an empty
        // name is never a real record.
        if ($fields[array_key_last($fields)] === '') {
            array_pop($fields);
        }

        return $fields;
    }

    /**
     * @param list<non-empty-string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function git(array $arguments, string $directory): array
    {
        $process = proc_open(
            ['git', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
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
