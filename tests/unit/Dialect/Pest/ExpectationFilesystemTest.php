<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use Closure;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Framework\TestCase;

use function chmod;
use function file_put_contents;
use function is_dir;
use function is_readable;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The six filesystem matchers, which no test and no probe had ever
 * executed: the parity probe excludes them because they answer about
 * paths on the running machine and the two checkouts differ, and
 * nothing covered them from the other side. Machine-dependence is a
 * reason a CROSS-RUNNER grid cannot ask them, not a reason a unit test
 * cannot — this one makes the machine's answer itself.
 *
 * Four of the six are composed rather than delegated
 * (`DirectoryExists()->matches() && is_string() && is_readable()`), so
 * both halves are exercised: a path of the wrong KIND and a path with
 * the wrong PERMISSIONS fail for different reasons.
 */
#[CoversClass(Expectation::class)]
final class ExpectationFilesystemTest extends TestCase
{
    private string $directory = '';

    private string $file = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crucible-fs-' . uniqid();
        $this->file      = $this->directory . '/present.txt';

        mkdir($this->directory);
        file_put_contents($this->file, 'present');
    }

    protected function tearDown(): void
    {
        // Restored before removal: a permission test leaves the mode it
        // set, and an unwritable directory cannot be emptied.
        if (is_dir($this->directory)) {
            chmod($this->directory, 0o755);
        }

        if ($this->file !== '' && @chmod($this->file, 0o644)) {
            @unlink($this->file);
        }

        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function testADirectoryIsADirectoryAndNotAFile(): void
    {
        (new Expectation($this->directory))->toBeDirectory();

        self::assertTrue($this->fails(fn() => (new Expectation($this->directory))->toBeFile()));
    }

    public function testAFileIsAFileAndNotADirectory(): void
    {
        (new Expectation($this->file))->toBeFile();

        self::assertTrue($this->fails(fn() => (new Expectation($this->file))->toBeDirectory()));
    }

    public function testAPathThatDoesNotExistIsNeither(): void
    {
        $missing = $this->directory . '/no-such-entry';

        self::assertTrue($this->fails(fn() => (new Expectation($missing))->toBeDirectory()));
        self::assertTrue($this->fails(fn() => (new Expectation($missing))->toBeFile()));
    }

    public function testAReadableAndWritablePathAnswersForItsKindOnly(): void
    {
        (new Expectation($this->directory))->toBeReadableDirectory()->and($this->directory)->toBeWritableDirectory();
        (new Expectation($this->file))->toBeReadableFile()->and($this->file)->toBeWritableFile();

        // The kind is checked first, so a file is not a readable
        // DIRECTORY however readable it is.
        self::assertTrue($this->fails(fn() => (new Expectation($this->file))->toBeReadableDirectory()));
        self::assertTrue($this->fails(fn() => (new Expectation($this->directory))->toBeWritableFile()));
    }

    public function testAnUnreadableFileIsNotAReadableFile(): void
    {
        chmod($this->file, 0o000);

        if (is_readable($this->file)) {
            self::markTestSkipped('the process ignores mode bits, so an unreadable path cannot be observed.');
        }

        // Still a file — only the permission half of the matcher fails.
        (new Expectation($this->file))->toBeFile();

        self::assertTrue($this->fails(fn() => (new Expectation($this->file))->toBeReadableFile()));
        self::assertTrue($this->fails(fn() => (new Expectation($this->file))->toBeWritableFile()));
    }

    public function testAnUnwritableDirectoryIsNotAWritableDirectory(): void
    {
        chmod($this->directory, 0o500);

        if (@file_put_contents($this->directory . '/probe.txt', 'x') !== false) {
            @unlink($this->directory . '/probe.txt');

            self::markTestSkipped('the process ignores mode bits, so an unwritable path cannot be observed.');
        }

        (new Expectation($this->directory))->toBeReadableDirectory();

        self::assertTrue($this->fails(fn() => (new Expectation($this->directory))->toBeWritableDirectory()));
    }

    private function fails(Closure $work): bool
    {
        try {
            $work();
        } catch (AssertionFailedError) {
            return true;
        }

        return false;
    }
}
