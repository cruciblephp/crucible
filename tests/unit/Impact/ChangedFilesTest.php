<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ChangedFiles;

use function chmod;
use function explode;
use function fclose;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Integration against a real throwaway git repository — the parsing
 * has no value if it drifts from what git actually prints.
 */
#[CoversClass(ChangedFiles::class)]
final class ChangedFilesTest extends TestCase
{
    /** @var non-empty-string */
    private string $repository = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-git-' . uniqid();

        mkdir($root, 0o777, true);

        $real             = realpath($root);
        $this->repository = $real === false ? $root : $real;

        $this->git('init -q');
        $this->git('config user.email crucible@example.test');
        $this->git('config user.name Crucible');

        file_put_contents($this->repository . '/kept.php', '<?php // kept');
        file_put_contents($this->repository . '/modified.php', '<?php // v1');
        file_put_contents($this->repository . '/doomed.php', '<?php // doomed');

        $this->git('add .');
        $this->git('commit -q -m base');
    }

    protected function tearDown(): void
    {
        $this->remove($this->repository);
    }

    /**
     * An argument list, not a shell line: `2>/dev/null` and `rm -rf`
     * do not exist under cmd.exe, and neither is needed.
     */
    private function git(string $command): void
    {
        $process = proc_open(
            ['git', '-C', $this->repository, ...explode(' ', $command)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('git ' . $command . ' could not be started.');
        }

        $error = (string) stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            self::fail('git ' . $command . ' failed: ' . $error);
        }
    }

    /** The commit a gitlink points at. */
    private function head(): string
    {
        $process = proc_open(
            ['git', '-C', $this->repository, 'rev-parse', 'HEAD'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('git rev-parse could not be started.');
        }

        $sha = trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || $sha === '') {
            self::fail('git rev-parse HEAD failed.');
        }

        return $sha;
    }

    /** Git leaves its objects read-only, which Windows will not unlink until told otherwise. */
    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                chmod($path, 0o666);
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }

    public function testModifiedUntrackedAndDeletedFilesAreReported(): void
    {
        file_put_contents($this->repository . '/modified.php', '<?php // v2');
        file_put_contents($this->repository . '/fresh.php', '<?php // new');
        unlink($this->repository . '/doomed.php');

        $changed = ChangedFiles::fromGit(new WorkingDirectory($this->repository), 'HEAD');

        if (is_string($changed)) {
            self::fail('Unexpected git error: ' . $changed);
        }

        self::assertContains(WorkingDirectory::native($this->repository . '/modified.php'), $changed->files);
        self::assertContains(WorkingDirectory::native($this->repository . '/fresh.php'), $changed->files);
        $this->assertNotContains(WorkingDirectory::native($this->repository . '/kept.php'), $changed->files);
        $this->assertNotContains(WorkingDirectory::native($this->repository . '/doomed.php'), $changed->files);
        self::assertSame(['doomed.php'], $changed->deleted);
    }

    public function testACleanTreeReportsNothing(): void
    {
        $changed = ChangedFiles::fromGit(new WorkingDirectory($this->repository), 'HEAD');

        if (is_string($changed)) {
            self::fail('Unexpected git error: ' . $changed);
        }

        self::assertSame([], $changed->files);
        self::assertSame([], $changed->deleted);
    }

    /**
     * A rename is a deletion plus an addition: `--no-renames` keeps the
     * old path in the change set, so tests depending on it are selected
     * rather than silently left behind.
     */
    public function testARenameReportsBothTheDeletedAndTheAddedPath(): void
    {
        $this->git('mv modified.php renamed.php');

        $changed = ChangedFiles::fromGit(new WorkingDirectory($this->repository), 'HEAD');

        if (is_string($changed)) {
            self::fail('Unexpected git error: ' . $changed);
        }

        self::assertContains(WorkingDirectory::native($this->repository . '/renamed.php'), $changed->files);
        self::assertSame(['modified.php'], $changed->deleted);
    }

    /** Spaces and non-ASCII both survive git's NUL-delimited answer. */
    public function testAwkwardFilenamesKeepTheirExactBytes(): void
    {
        $names = ['sp ace.php', 'unï€ode.php'];

        foreach ($names as $name) {
            file_put_contents($this->repository . '/' . $name, '<?php // new');
        }

        $changed = ChangedFiles::fromGit(new WorkingDirectory($this->repository), 'HEAD');

        if (is_string($changed)) {
            self::fail('Unexpected git error: ' . $changed);
        }

        foreach ($names as $name) {
            self::assertContains(WorkingDirectory::native($this->repository . '/' . $name), $changed->files);
        }
    }

    /**
     * ls-files answers relative to the current directory, so a run from
     * a nested one must still see untracked files above it and must not
     * join a child-relative path onto the repository root.
     */
    public function testUntrackedFilesAreFoundFromANestedDirectory(): void
    {
        mkdir($this->repository . '/nested/deeper', 0o777, true);
        file_put_contents($this->repository . '/nested/local.php', '<?php // new');
        file_put_contents($this->repository . '/nested/deeper/inside.php', '<?php // new');
        file_put_contents($this->repository . '/above.php', '<?php // new');

        $changed = ChangedFiles::fromGit(new WorkingDirectory($this->repository . '/nested/deeper'), 'HEAD');

        if (is_string($changed)) {
            self::fail('Unexpected git error: ' . $changed);
        }

        foreach (['above.php', 'nested/local.php', 'nested/deeper/inside.php'] as $name) {
            self::assertContains(WorkingDirectory::native($this->repository . '/' . $name), $changed->files);
        }
    }

    /**
     * A gitlink (submodule) is a directory in the working tree, not a
     * regular file: dropping it would hide an important dependency
     * change from impact selection.
     */
    public function testAGitlinkChangeSurvivesTheExistingEntryFilter(): void
    {
        mkdir($this->repository . '/modules', 0o777, true);
        mkdir($this->repository . '/modules/package');
        $this->git('update-index --add --cacheinfo 160000,' . $this->head() . ',modules/package');

        $changed = ChangedFiles::fromGit(new WorkingDirectory($this->repository), 'HEAD');

        if (is_string($changed)) {
            self::fail('Unexpected git error: ' . $changed);
        }

        self::assertContains(WorkingDirectory::native($this->repository . '/modules/package'), $changed->files);
    }

    public function testAnUnknownReferenceIsAnError(): void
    {
        self::assertIsString(ChangedFiles::fromGit(new WorkingDirectory($this->repository), 'no-such-ref-anywhere'));
    }

    public function testOutsideARepositoryIsAnError(): void
    {
        self::assertIsString(ChangedFiles::fromGit(new WorkingDirectory(sys_get_temp_dir() . '/'), 'HEAD'));
    }
}
