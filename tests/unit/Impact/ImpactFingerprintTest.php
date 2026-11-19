<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use Composer\Autoload\ClassLoader;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ImpactFingerprint;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_pop;
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
 * else the answer depends on has to reach the key. Two bite. Suite
 * configuration: a `*.pest.php` file depends on the `Pest.php` above it
 * (D-033), so ADDING one changes that file's dependencies without
 * touching a byte of it. And autoload resolution: a class moved in the
 * classmap resolves elsewhere while `composer.json` and the lockfile
 * still hash the same - which is exactly what a regenerated
 * `vendor/composer/autoload_classmap.php` looks like to every
 * content-based check.
 *
 * The ids here are project-relative, the shape discovery actually
 * produces. The test that pinned the fingerprint before built
 * ABSOLUTE ids, a shape production never makes, and its suite
 * component was therefore always empty without the test ever noticing.
 */
#[CoversClass(ImpactFingerprint::class)]
final class ImpactFingerprintTest extends TestCase
{
    /** @var list<string> */
    private array $rubbish = [];

    /** @var list<ClassLoader> */
    private array $loaders = [];

    protected function tearDown(): void
    {
        foreach ($this->loaders as $loader) {
            $loader->unregister();
        }

        $this->loaders = [];

        foreach ($this->rubbish as $path) {
            if (is_dir($path . '/Feature')) {
                @unlink($path . '/Feature/Pest.php');
                rmdir($path . '/Feature');
            }

            @unlink($path . '/Datasets/rows.php');
            @rmdir($path . '/Datasets');
            @unlink($path . '/Pest.php');
            @unlink($path . '/composer.json');
            @unlink($path . '/composer.lock');
            @rmdir($path);
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
     * Production shape: ids carry the project-relative path, here a test
     * file that sits in the scratch project's own Feature directory.
     *
     * @return list<TestGroup>
     */
    private function groups(): array
    {
        return [new TestGroup('Feature/Example.pest.php', [
            new TestDefinition(new TestId('Feature/Example.pest.php', 'it works'), static fn(): mixed => null, MetadataCollection::from()),
        ])];
    }

    public function testAddingASuiteConfigurationChangesTheFingerprint(): void
    {
        $root = $this->tree();

        $before = ImpactFingerprint::of($root, $this->groups());

        // Nothing about the test file changed — only what sits above it.
        // A cache keyed on content alone would answer from stale data
        // here, and the failure is the invisible kind: a test that
        // should have been selected is not, so it cannot fail.
        file_put_contents($root . '/Feature/Pest.php', "<?php\n");

        self::assertNotSame($before, ImpactFingerprint::of($root, $this->groups()));
    }

    public function testTheSameTreeFingerprintsTheSame(): void
    {
        $root = $this->tree();

        // Or nothing would ever be reused and the index would be a
        // write-only file.
        self::assertSame(
            ImpactFingerprint::of($root, $this->groups()),
            ImpactFingerprint::of($root, $this->groups()),
        );
    }

    public function testTheNumberOfCasesInOneDirectoryDoesNotMoveTheKey(): void
    {
        $root = $this->tree();

        // A provider's rows are all the same files in the same
        // directories: the walk answers the same question once. Before
        // the per-directory pass, a 3,000-row fixture walked the tree
        // and globbed `Datasets/` 3,000 times for one answer.
        $rows = [];

        for ($row = 0; $row < 500; $row++) {
            $rows[] = new TestDefinition(
                new TestId('Feature/Example.pest.php', 'row ' . $row),
                static fn(): mixed => null,
                MetadataCollection::from(),
            );
        }

        $many = [new TestGroup('Feature/Example.pest.php', $rows)];

        self::assertSame(
            ImpactFingerprint::of($root, $this->groups()),
            ImpactFingerprint::of($root, $many),
            'How many cases a file contributes is not part of the key.',
        );

        // And the dedupe is per CALL: what appears between two runs must
        // still reach the key, or a warm index would replay stale edges.
        $before = ImpactFingerprint::of($root, $many);
        file_put_contents($root . '/Feature/Pest.php', "<?php\n");

        self::assertNotSame($before, ImpactFingerprint::of($root, $many));
    }

    public function testConfigurationAboveTheFileCountsToo(): void
    {
        $root = $this->tree();

        $before = ImpactFingerprint::of($root, $this->groups());

        // A Pest.php at the ROOT applies to everything beneath it, so it
        // has to reach the key from there as well.
        file_put_contents($root . '/Pest.php', "<?php\n");

        self::assertNotSame($before, ImpactFingerprint::of($root, $this->groups()));
    }

    public function testADatasetFileCountsToo(): void
    {
        $root = $this->tree();

        $before = ImpactFingerprint::of($root, $this->groups());

        // Datasets/*.php load like Pest.php does (D-033): one appearing
        // above an unchanged file changes what that file depends on.
        mkdir($root . '/Datasets');
        file_put_contents($root . '/Datasets/rows.php', "<?php\n");

        self::assertNotSame($before, ImpactFingerprint::of($root, $this->groups()));
    }

    public function testAClassmapRemapChangesTheFingerprint(): void
    {
        $root = $this->tree();

        $loader = new ClassLoader();
        $loader->addClassMap(['Scratch\\Greeting' => $root . '/src/Old.php']);
        $loader->register();
        $this->loaders[] = $loader;

        $before = ImpactFingerprint::of($root, $this->groups());

        // The bytes of the test, of composer.json and of the lockfile
        // have not moved. The class resolves elsewhere now, and a
        // regenerated autoload_classmap.php is exactly this change: one
        // the manifests never record.
        $loader->addClassMap(['Scratch\\Greeting' => $root . '/src/New.php']);

        self::assertNotSame($before, ImpactFingerprint::of($root, $this->groups()));
    }

    public function testAManifestEditChangesTheFingerprint(): void
    {
        $root = $this->tree();

        file_put_contents($root . '/composer.json', '{"autoload": {"classmap": ["src/Old.php"]}}');
        file_put_contents($root . '/composer.lock', '{}');

        $before = ImpactFingerprint::of($root, $this->groups());

        // The manifests configure the resolution before it is
        // generated, so they reach the key even with no loader
        // registered in this process at all.
        file_put_contents($root . '/composer.json', '{"autoload": {"classmap": ["src/New.php"]}}');

        self::assertNotSame($before, ImpactFingerprint::of($root, $this->groups()));
    }

    public function testTheFingerprintDoesNotMoveWithTheCheckout(): void
    {
        $here  = $this->tree();
        $there = $this->tree();

        foreach ([$here, $there] as $root) {
            file_put_contents($root . '/Feature/Pest.php', "<?php\n");
        }

        $fingerprints = [];

        // One loader at a time, each resolving inside its own project
        // with one prefix inside the root and one entry outside it:
        // every path-shaped input must fold in relative to its own root,
        // or a CI cache restored into another checkout would start
        // every run cold.
        foreach ([$here, $there] as $root) {
            $loader = new ClassLoader();
            $loader->addPsr4('Scratch\\', $root . '/src');
            $loader->addClassMap([
                'Scratch\\Greeting' => $root . '/src/Greeting.php',
                'Elsewhere\\Shared' => sys_get_temp_dir() . '/crucible-fingerprint-elsewhere/Shared.php',
            ]);
            $loader->register();
            $this->loaders[] = $loader;

            $fingerprints[] = ImpactFingerprint::of($root, $this->groups());

            $loader->unregister();
            array_pop($this->loaders);
        }

        self::assertSame(
            $fingerprints[0],
            $fingerprints[1],
            'Two checkouts of one project must land on one key.',
        );
    }

    public function testAbsoluteIdsStillFingerprintLikeRelativeOnes(): void
    {
        $root = $this->tree();

        $absolute = [new TestGroup($root . '/Feature/Example.pest.php', [
            new TestDefinition(new TestId($root . '/Feature/Example.pest.php', 'it works'), static fn(): mixed => null, MetadataCollection::from()),
        ])];

        self::assertSame(
            ImpactFingerprint::of($root, $absolute),
            ImpactFingerprint::of($root, $this->groups()),
            'A relative id and its absolute twin name the same file; the walk must resolve both against the root.',
        );
    }
}
