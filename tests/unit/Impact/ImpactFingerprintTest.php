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
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ImpactFingerprint;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * What makes a cached dependency answer stale.
 *
 * The index replays a file whose bytes have not changed, so anything
 * else the answer depends on has to reach the key. The one that bites
 * is suite configuration: a `*.pest.php` file depends on the `Pest.php`
 * above it (D-033), so ADDING one changes that file's dependencies
 * without touching a byte of it.
 */
#[CoversClass(ImpactFingerprint::class)]
final class ImpactFingerprintTest extends TestCase
{
    /** @var list<string> */
    private array $rubbish = [];

    protected function tearDown(): void
    {
        foreach ($this->rubbish as $path) {
            if (is_dir($path . '/Feature')) {
                @unlink($path . '/Feature/Pest.php');
                rmdir($path . '/Feature');
            }

            @unlink($path . '/Pest.php');
            rmdir($path);
        }

        $this->rubbish = [];
    }

    /**
     * @return non-empty-string
     */
    private function tree(): string
    {
        $root = sys_get_temp_dir() . '/crucible-fingerprint-' . uniqid();

        mkdir($root . '/Feature', 0o777, true);
        $this->rubbish[] = $root;

        return $root;
    }

    /**
     * @return list<TestGroup>
     */
    private function groups(string $root): array
    {
        return [new TestGroup($root . '/Feature/ExampleTest.pest.php', [
            new TestDefinition(new TestId($root . '/Feature/ExampleTest.pest.php', 'it works'), static fn(): mixed => null, MetadataCollection::from()),
        ])];
    }

    public function testAddingASuiteConfigurationChangesTheFingerprint(): void
    {
        $root   = $this->tree();
        $groups = $this->groups($root);

        $before = ImpactFingerprint::of($root, $groups);

        // Nothing about the test file changed — only what sits above it.
        // A cache keyed on content alone would answer from stale data
        // here, and the failure is the invisible kind: a test that
        // should have been selected is not, so it cannot fail.
        file_put_contents($root . '/Feature/Pest.php', "<?php\n");

        self::assertNotSame($before, ImpactFingerprint::of($root, $groups));
    }

    public function testTheSameTreeFingerprintsTheSame(): void
    {
        $root   = $this->tree();
        $groups = $this->groups($root);

        // Or nothing would ever be reused and the index would be a
        // write-only file.
        self::assertSame(ImpactFingerprint::of($root, $groups), ImpactFingerprint::of($root, $groups));
    }

    public function testConfigurationAboveTheFileCountsToo(): void
    {
        $root   = $this->tree();
        $groups = $this->groups($root);

        $before = ImpactFingerprint::of($root, $groups);

        // A Pest.php at the ROOT applies to everything beneath it, so it
        // has to reach the key from there as well.
        file_put_contents($root . '/Pest.php', "<?php\n");

        self::assertNotSame($before, ImpactFingerprint::of($root, $groups));
    }
}
