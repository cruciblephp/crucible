<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Coverage;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Coverage\CacheManifest;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\CoverageMap;
use LucianoPereira\Crucible\Coverage\SourceHashes;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Version;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function hash_file;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The cache files another tool reads (spec/cache-formats.md): the maps
 * carry each file's hash, and the manifest lists them with theirs.
 */
#[CoversClass(SourceHashes::class)]
#[CoversClass(CacheManifest::class)]
#[CoversClass(TestLineMap::class)]
#[CoversClass(CoverageMap::class)]
final class CacheFormatsTest extends TestCase
{
    /** @var non-empty-string */
    private string $root = '/tmp/root';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crucible-cache-formats-' . uniqid();

        mkdir($this->root . '/src', 0o777, true);
        mkdir($this->root . '/tests', 0o777, true);
        file_put_contents($this->root . '/src/A.php', "<?php\n\nreturn 1;\n");
        file_put_contents($this->root . '/src/B.php', "<?php\n\nreturn 2;\n");
        file_put_contents($this->root . '/tests/ATest.php', "<?php\n");
        file_put_contents($this->root . '/tests/BTest.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        foreach (['/src/*', '/tests/*', '/cache/*'] as $pattern) {
            $files = glob($this->root . $pattern);

            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
        }

        foreach ([$this->root . '/src', $this->root . '/tests', $this->root . '/cache', $this->root] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function data(): CoverageData
    {
        return new CoverageData(
            [$this->root . '/src/A.php' => [3 => 1], $this->root . '/src/B.php' => [3 => -1]],
            tests: ['tests/ATest.php::one' => [$this->root . '/src/A.php' => [3]]],
        );
    }

    public function testTheLineMapRecordsEachFileAsItWasRun(): void
    {
        $map = TestLineMap::fromData($this->data(), $this->root);

        self::assertSame(
            ['src/A.php' => hash('sha256', "<?php\n\nreturn 1;\n"), 'src/B.php' => hash('sha256', "<?php\n\nreturn 2;\n")],
            $map->hashes,
        );

        mkdir($this->root . '/cache');
        $map->save($this->root . '/cache/coverage-lines.json');

        self::assertSame($map->hashes, TestLineMap::load($this->root . '/cache/coverage-lines.json')->hashes);
    }

    public function testAFileEditedOrDeletedSinceIsChangedAndAnUntouchedOneIsNot(): void
    {
        $hashes = TestLineMap::fromData($this->data(), $this->root)->hashes;

        self::assertSame([], SourceHashes::changed($this->root, $hashes));

        file_put_contents($this->root . '/src/A.php', "<?php\n\n\nreturn 1;\n");
        unlink($this->root . '/src/B.php');

        self::assertSame(['src/A.php', 'src/B.php'], SourceHashes::changed($this->root, $hashes));
    }

    public function testTheImpactMapCarriesTheHashesAndReadsAVersionOneMapAsEmpty(): void
    {
        $file = $this->root . '/src/map.json';
        $map  = TestLineMap::fromData($this->data(), $this->root);

        CoverageMap::save($file, CoverageMap::fromData($this->data(), $this->root), $map->hashes);

        $decoded = json_decode((string) file_get_contents($file), true);

        self::assertSame(['version' => 2, 'edges' => ['tests/ATest.php' => ['src/A.php']], 'hashes' => $map->hashes], $decoded);

        file_put_contents($file, '{"version": 1, "edges": {"tests/ATest.php": ["src/A.php"]}}');

        self::assertSame([], CoverageMap::load($file));
    }

    public function testAPartialCoverageRunKeepsTestFilesItDidNotRun(): void
    {
        $file = $this->root . '/cache/coverage-map.json';
        mkdir($this->root . '/cache');

        CoverageMap::save(
            $file,
            ['tests/ATest.php' => ['src/A.php'], 'tests/BTest.php' => ['src/B.php']],
            ['src/A.php'       => hash('sha256', 'a'), 'src/B.php' => hash('sha256', 'b')],
        );

        CoverageMap::merge(
            $file,
            ['tests/ATest.php'         => ['src/A.php', 'src/B.php']],
            ['src/A.php'               => hash('sha256', 'a2')],
            fn(string $testFile): bool => is_file($this->root . '/' . $testFile),
        );

        self::assertSame(
            ['tests/ATest.php' => ['src/A.php', 'src/B.php'], 'tests/BTest.php' => ['src/B.php']],
            CoverageMap::load($file),
        );

        self::assertSame(
            ['src/A.php' => hash('sha256', 'a2'), 'src/B.php' => hash('sha256', 'b')],
            CoverageMap::read($file)['hashes'],
        );
    }

    public function testAPartialCoverageRunUnionsWithinATestFile(): void
    {
        $file = $this->root . '/cache/coverage-map.json';
        mkdir($this->root . '/cache');

        CoverageMap::save($file, ['tests/ATest.php' => ['src/A.php', 'src/B.php']]);

        CoverageMap::merge(
            $file,
            ['tests/ATest.php' => ['src/A.php']],
            [],
            fn(string $testFile): bool => is_file($this->root . '/' . $testFile),
        );

        self::assertSame(['tests/ATest.php' => ['src/A.php', 'src/B.php']], CoverageMap::load($file));
    }

    public function testAMergeDropsATestFileThatNoLongerExists(): void
    {
        $file = $this->root . '/cache/coverage-map.json';
        mkdir($this->root . '/cache');

        CoverageMap::save($file, ['tests/ATest.php' => ['src/A.php'], 'tests/BTest.php' => ['src/B.php']]);

        unlink($this->root . '/tests/BTest.php');

        CoverageMap::merge(
            $file,
            ['tests/ATest.php' => ['src/A.php']],
            [],
            fn(string $testFile): bool => is_file($this->root . '/' . $testFile),
        );

        self::assertSame(['tests/ATest.php' => ['src/A.php']], CoverageMap::load($file));
    }

    public function testACoverageRunThatObservedNothingKeepsTheMap(): void
    {
        $file = $this->root . '/cache/coverage-map.json';
        mkdir($this->root . '/cache');

        CoverageMap::save($file, ['tests/ATest.php' => ['src/A.php']]);

        CoverageMap::merge(
            $file,
            [],
            [],
            fn(string $testFile): bool => is_file($this->root . '/' . $testFile),
        );

        self::assertSame(['tests/ATest.php' => ['src/A.php']], CoverageMap::load($file));
    }

    public function testTheManifestListsEachArtifactWithItsFormatAndDigest(): void
    {
        $cache = $this->root . '/cache';
        $map   = TestLineMap::fromData($this->data(), $this->root);

        mkdir($cache);
        $map->save($cache . '/coverage-lines.json');
        CacheManifest::record($cache, 'coverage-lines.json', TestLineMap::VERSION);
        CoverageMap::save($cache . '/coverage-map.json', [], $map->hashes);
        CacheManifest::record($cache, 'coverage-map.json', CoverageMap::VERSION);

        self::assertSame(
            [
                'coverage-lines.json' => ['version' => 2, 'sha256' => (string) hash_file('sha256', $cache . '/coverage-lines.json')],
                'coverage-map.json'   => ['version' => 2, 'sha256' => (string) hash_file('sha256', $cache . '/coverage-map.json')],
            ],
            CacheManifest::artifacts($cache),
        );

        $decoded = json_decode((string) file_get_contents($cache . '/manifest.json'), true);

        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['version']);
        self::assertSame(Version::NUMBER, $decoded['crucible']);
    }

    public function testACorruptManifestListsNothing(): void
    {
        mkdir($this->root . '/cache');
        file_put_contents($this->root . '/cache/manifest.json', 'not json');

        self::assertSame([], CacheManifest::artifacts($this->root . '/cache'));
    }
}
