<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Watch;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Watch\FileWatcher;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;
use function unlink;

#[CoversClass(FileWatcher::class)]
final class FileWatcherTest extends TestCase
{
    /** @var non-empty-string */
    private string $root = '.';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crucible-watch-' . uniqid();

        mkdir($this->root . '/sub', 0o777, true);
        mkdir($this->root . '/vendor', 0o777, true);
        mkdir($this->root . '/node_modules', 0o777, true);
        mkdir($this->root . '/.hidden', 0o777, true);

        file_put_contents($this->root . '/a.php', 'a');
        file_put_contents($this->root . '/sub/b.php', 'b');
        file_put_contents($this->root . '/vendor/dep.php', 'v');
        file_put_contents($this->root . '/node_modules/dep.js', 'n');
        file_put_contents($this->root . '/.hidden/c.php', 'h');
    }

    protected function tearDown(): void
    {
        foreach (['/a.php', '/sub/b.php', '/vendor/dep.php', '/node_modules/dep.js', '/.hidden/c.php', '/fresh.php'] as $file) {
            if (is_file($this->root . $file)) {
                unlink($this->root . $file);
            }
        }

        foreach (['/sub', '/vendor', '/node_modules', '/.hidden', ''] as $directory) {
            if (is_dir($this->root . $directory)) {
                rmdir($this->root . $directory);
            }
        }
    }

    public function testVendorNodeModulesAndHiddenDirectoriesAreNotWatched(): void
    {
        $snapshot = (new FileWatcher())->snapshot([$this->root], []);

        self::assertArrayHasKey(WorkingDirectory::native($this->root . '/a.php'), $snapshot);
        self::assertArrayHasKey(WorkingDirectory::native($this->root . '/sub/b.php'), $snapshot);
        $this->assertArrayNotHasKey(WorkingDirectory::native($this->root . '/vendor/dep.php'), $snapshot);
        $this->assertArrayNotHasKey($this->root . '/node_modules/dep.js', $snapshot);
        $this->assertArrayNotHasKey(WorkingDirectory::native($this->root . '/.hidden/c.php'), $snapshot);
    }

    public function testModificationsAdditionsAndDeletionsAreDiffed(): void
    {
        $watcher = new FileWatcher();
        $before  = $watcher->snapshot([$this->root], []);

        file_put_contents($this->root . '/a.php', 'a changed'); // size changes even within one mtime second
        file_put_contents($this->root . '/fresh.php', 'new');
        unlink($this->root . '/sub/b.php');

        $diff = $watcher->diff($before, $watcher->snapshot([$this->root], []));

        self::assertContains(WorkingDirectory::native($this->root . '/a.php'), $diff['changed']);
        self::assertContains(WorkingDirectory::native($this->root . '/fresh.php'), $diff['changed']);
        self::assertSame([WorkingDirectory::native($this->root . '/sub/b.php')], $diff['deleted']);
    }

    public function testATouchAloneIsAChange(): void
    {
        $watcher = new FileWatcher();
        $before  = $watcher->snapshot([$this->root], []);

        touch($this->root . '/a.php', time() + 5); // same size, future mtime

        $diff = $watcher->diff($before, $watcher->snapshot([$this->root], []));

        self::assertSame([WorkingDirectory::native($this->root . '/a.php')], $diff['changed']);
    }

    public function testExplicitFilesAreWatchedToo(): void
    {
        $snapshot = (new FileWatcher())->snapshot([], [$this->root . '/a.php', $this->root . '/gone.php']);

        self::assertArrayHasKey(WorkingDirectory::native($this->root . '/a.php'), $snapshot);
        $this->assertArrayNotHasKey(WorkingDirectory::native($this->root . '/gone.php'), $snapshot);
    }
}
