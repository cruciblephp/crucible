<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ChangedFiles;

use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_string;
use function mkdir;
use function realpath;
use function rmdir;
use function sprintf;
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
        foreach (['kept.php', 'modified.php', 'doomed.php', 'fresh.php'] as $file) {
            @unlink($this->repository . '/' . $file);
        }

        exec(sprintf('rm -rf %s/.git', escapeshellarg($this->repository)));

        if (is_dir($this->repository)) {
            rmdir($this->repository);
        }
    }

    private function git(string $command): void
    {
        exec(sprintf('git -C %s %s 2>/dev/null', escapeshellarg($this->repository), $command), $output, $code);

        if ($code !== 0) {
            self::fail('git ' . $command . ' failed: ' . implode("\n", $output));
        }
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

        self::assertContains($this->repository . '/modified.php', $changed->files);
        self::assertContains($this->repository . '/fresh.php', $changed->files);
        $this->assertNotContains($this->repository . '/kept.php', $changed->files);
        $this->assertNotContains($this->repository . '/doomed.php', $changed->files);
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

    public function testAnUnknownReferenceIsAnError(): void
    {
        self::assertIsString(ChangedFiles::fromGit(new WorkingDirectory($this->repository), 'no-such-ref-anywhere'));
    }

    public function testOutsideARepositoryIsAnError(): void
    {
        self::assertIsString(ChangedFiles::fromGit(new WorkingDirectory(sys_get_temp_dir() . '/'), 'HEAD'));
    }
}
