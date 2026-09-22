<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Filesystem;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function getcwd;

/**
 * The type that replaced 71 copies of one comment.
 */
#[CoversClass(WorkingDirectory::class)]
final class WorkingDirectoryTest extends TestCase
{
    /**
     * The invariant, checked once instead of annotated everywhere.
     *
     * This is the whole reason the class exists: `non-empty-string` was
     * a claim 71 signatures made in a docblock and six of them forgot.
     * Here it is a constructor that cannot be bypassed.
     */
    public function testAnEmptyPathIsRefusedAtConstruction(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be empty');

        new WorkingDirectory('');
    }

    public function testThePathIsKeptVerbatim(): void
    {
        self::assertSame('/project', (new WorkingDirectory('/project'))->path);
        self::assertSame('/', (new WorkingDirectory('/'))->path);

        // NOT normalised. A trailing separator survives, because
        // Paths::absolute() never trimmed one either and this refactor
        // is type-level: no produced path may move.
        self::assertSame('/project/', (new WorkingDirectory('/project/'))->path);
    }

    /**
     * Resolution is the replaced helper's, shape for shape.
     *
     * ✓ Checked against `Paths::absolute()` on all five shapes that occur,
     * including the two ugly ones — a root directory doubling the
     * separator, and a trailing separator doing the same. They are
     * preserved rather than fixed, so the gates can prove the refactor
     * changed nothing.
     */
    public function testRelativePathsResolveAgainstTheDirectory(): void
    {
        $cwd = new WorkingDirectory('/project');

        self::assertSame('/project/src', $cwd->absolute('src'));
        self::assertSame('/project/a/b', $cwd->absolute('a/b'));
        self::assertSame('/project/', $cwd->absolute(''), 'an empty path is the directory itself');
    }

    public function testAnAbsolutePathIsReturnedUntouched(): void
    {
        self::assertSame('/elsewhere/x', (new WorkingDirectory('/project'))->absolute('/elsewhere/x'));
    }

    public function testTheUglyShapesArePreservedRatherThanFixed(): void
    {
        self::assertSame('//a/b', (new WorkingDirectory('/'))->absolute('a/b'));
        self::assertSame('/project//src', (new WorkingDirectory('/project/'))->absolute('src'));
    }

    public function testCurrentReadsTheProcessDirectory(): void
    {
        self::assertSame((string) getcwd(), WorkingDirectory::current()->path);
    }

    public function testTwoDirectoriesAreEqualByPath(): void
    {
        $a = new WorkingDirectory('/project');

        self::assertTrue($a->equals(new WorkingDirectory('/project')));
        self::assertFalse($a->equals(new WorkingDirectory('/other')));
        self::assertFalse($a->equals(new WorkingDirectory('/project/')), 'verbatim, so not normalised away');
    }

    /**
     * The counterpart to {@see WorkingDirectory::absolute()}.
     *
     * A path that cannot be said relatively is said in full instead:
     * trimming a prefix that is not there would produce a path naming
     * somewhere else entirely.
     */
    public function testAPathInsideTheDirectoryIsNamedFromIt(): void
    {
        $directory = new WorkingDirectory('/srv/app');

        self::assertSame('crucible.php', $directory->relative('/srv/app/crucible.php'));
        self::assertSame('tests/unit/FooTest.php', $directory->relative('/srv/app/tests/unit/FooTest.php'));
        self::assertSame('/etc/crucible.php', $directory->relative('/etc/crucible.php'), 'outside, untouched');
        self::assertSame('crucible.php', $directory->relative('crucible.php'), 'already relative, untouched');
        self::assertSame('/srv/app', $directory->relative('/srv/app'), 'the directory itself is not the empty string');

        // ⚠ A prefix match on the string rather than on the path would
        // turn a sibling directory into a relative path inside this one.
        self::assertSame('/srv/application/crucible.php', $directory->relative('/srv/application/crucible.php'));
    }
}
