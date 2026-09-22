<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Compat\Migration;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\Migration\FixManifest;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(FixManifest::class)]
final class FixManifestTest extends TestCase
{
    /** @var non-empty-string */
    private string $dir;

    /** @var non-empty-string */
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->dir          = sys_get_temp_dir() . '/crucible-fix-manifest-test-' . uniqid();
        $this->manifestPath = $this->dir . '/compat-fixes.json';

        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/a.php', '/b.php', '/compat-fixes.json'] as $file) {
            if (is_file($this->dir . $file)) {
                unlink($this->dir . $file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testBackupThenRevertRestoresOriginalContent(): void
    {
        $file = $this->dir . '/a.php';
        file_put_contents($file, "<?php\n// original\n");

        $manifest = new FixManifest($this->manifestPath);
        $manifest->backup($file, (string) file_get_contents($file));

        // Simulate the actual rewrite that would follow a backup.
        file_put_contents($file, "<?php\n// rewritten\n");
        $this->assertStringContainsString('rewritten', (string) file_get_contents($file));

        $restored = $manifest->revert();

        $this->assertSame([$file], $restored);
        $this->assertStringContainsString('original', (string) file_get_contents($file));
        $this->assertStringNotContainsString('rewritten', (string) file_get_contents($file));
    }

    public function testRevertClearsTheManifest(): void
    {
        $file = $this->dir . '/a.php';
        file_put_contents($file, 'x');

        $manifest = new FixManifest($this->manifestPath);
        $manifest->backup($file, 'x');
        $manifest->revert();

        $this->assertTrue($manifest->isEmpty());
        $this->assertFalse(is_file($this->manifestPath));
    }

    public function testASecondBackupOfTheSameFileIsANoOp(): void
    {
        $file = $this->dir . '/a.php';
        file_put_contents($file, 'first');

        $manifest = new FixManifest($this->manifestPath);
        $manifest->backup($file, 'first');

        // A re-fix backs up again, but the manifest must keep the
        // ORIGINAL content, not this already-rewritten one.
        file_put_contents($file, 'second');
        $manifest->backup($file, 'second');

        file_put_contents($file, 'third');
        $manifest->revert();

        $this->assertSame('first', file_get_contents($file));
    }

    public function testMultipleFilesRevertTogether(): void
    {
        $a = $this->dir . '/a.php';
        $b = $this->dir . '/b.php';
        file_put_contents($a, 'a-original');
        file_put_contents($b, 'b-original');

        $manifest = new FixManifest($this->manifestPath);
        $manifest->backup($a, 'a-original');
        $manifest->backup($b, 'b-original');

        file_put_contents($a, 'a-fixed');
        file_put_contents($b, 'b-fixed');

        $restored = $manifest->revert();

        $this->assertCount(2, $restored);
        $this->assertSame('a-original', file_get_contents($a));
        $this->assertSame('b-original', file_get_contents($b));
    }

    public function testEmptyManifestHasNothingToRevert(): void
    {
        $manifest = new FixManifest($this->manifestPath);

        $this->assertTrue($manifest->isEmpty());
        $this->assertSame([], $manifest->revert());
    }
}
