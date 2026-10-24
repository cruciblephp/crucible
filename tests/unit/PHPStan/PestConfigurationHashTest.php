<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\PestConfigurationHash;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The Pest.php files PHPStan's result cache cannot see join its metadata
 * (D-157): ✓ measured on PHPStan 2.2.6, a changed `pest()->extend()->in()`
 * left a removed method unreported until the cache was cleared.
 */
#[CoversClass(PestConfigurationHash::class)]
final class PestConfigurationHashTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crucible-pest-hash-' . uniqid();

        mkdir($this->root . '/tests/Feature/Deep', 0o777, true);
        mkdir($this->root . '/vendor/pkg', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAnAncestorOrADescendantPestPhpChangesTheHash(): void
    {
        $analysed = [$this->root . '/tests/Feature'];
        $empty    = (new PestConfigurationHash($analysed))->getHash();

        file_put_contents($this->root . '/tests/Pest.php', '<?php uses(A::class)->in("Feature");');
        $ancestor = (new PestConfigurationHash($analysed))->getHash();

        file_put_contents($this->root . '/tests/Feature/Deep/Pest.php', '<?php uses(B::class);');
        $descendant = (new PestConfigurationHash($analysed))->getHash();

        file_put_contents($this->root . '/tests/Pest.php', '<?php uses(C::class)->in("Feature");');
        $edited = (new PestConfigurationHash($analysed))->getHash();

        $this->assertNotSame($empty, $ancestor);
        $this->assertNotSame($ancestor, $descendant);
        $this->assertNotSame($descendant, $edited);
        $this->assertSame($edited, (new PestConfigurationHash($analysed))->getHash(), 'unchanged files keep the hash, and the cache with it');
    }

    public function testNothingOutsideTheProjectOrInVendorCounts(): void
    {
        $analysed = [$this->root . '/tests'];
        $before   = (new PestConfigurationHash($analysed))->getHash();

        file_put_contents($this->root . '/vendor/pkg/Pest.php', '<?php uses(A::class);');

        $this->assertSame($before, (new PestConfigurationHash($analysed))->getHash());
    }

    private function remove(string $path): void
    {
        $entries = is_dir($path) ? scandir($path) : false;

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($path . '/' . $entry) ? $this->remove($path . '/' . $entry) : unlink($path . '/' . $entry);
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
