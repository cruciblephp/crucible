<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Filesystem;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\RequiresOperatingSystem;
use LucianoPereira\Crucible\Filesystem\AtomicFile;
use LucianoPereira\Crucible\Framework\TestCase;

use function chmod;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(AtomicFile::class)]
final class AtomicFileTest extends TestCase
{
    /** @var non-empty-string */
    private string $directory = '.';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crucible-atomic-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach ((array) scandir($this->directory) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                unlink($this->directory . '/' . $entry);
            }
        }

        rmdir($this->directory);
    }

    public function testItCreatesTheDirectoryAndWritesTheFile(): void
    {
        $file = $this->directory . '/nested/results.json';

        self::assertTrue(AtomicFile::write($file, '{"ok":true}'));
        self::assertSame('{"ok":true}', file_get_contents($file));
        self::assertFalse(is_file($file . '.partial'), 'The temporary file outlived the rename.');
    }

    public function testItReplacesAnExistingFileInOneRename(): void
    {
        mkdir($this->directory, 0o777, true);

        $file = $this->directory . '/results.json';
        file_put_contents($file, 'old');

        self::assertTrue(AtomicFile::write($file, 'new'));
        self::assertSame('new', file_get_contents($file));
        self::assertFalse(is_file($file . '.partial'));
    }

    #[RequiresOperatingSystem('^(?!WIN)')]
    public function testItKeepsTheExistingMode(): void
    {
        mkdir($this->directory, 0o777, true);

        $file = $this->directory . '/Test.php';
        file_put_contents($file, 'old');
        chmod($file, 0o664);

        self::assertTrue(AtomicFile::write($file, 'new'));

        $perms = fileperms($file);

        self::assertIsInt($perms);
        self::assertSame(0o664, $perms & 0o777, 'Rewriting a test source must not change its permissions.');
    }

    public function testItReportsAFailureItCanSee(): void
    {
        // The parent path is a file, so no directory can be created and
        // no partial can be written. The caller is told, not left with a
        // silently absent replacement.
        mkdir($this->directory, 0o777, true);

        $blocker = $this->directory . '/blocker';
        file_put_contents($blocker, 'x');

        self::assertFalse(AtomicFile::write($blocker . '/results.json', 'data'));
    }
}
