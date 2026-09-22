<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\ResultCache;

use function dirname;
use function file_put_contents;
use function is_file;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(ResultCache::class)]
final class ResultCacheTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crucible-result-cache-' . uniqid() . '/results.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testRoundTripPreservesOutcomeHistoryAndDuration(): void
    {
        $cache = new ResultCache();
        $cache->record('tests/FooTest.php::testA', Outcome::Failed, 0.5);
        $cache->record('tests/FooTest.php::testA', Outcome::Passed, 0.25);
        $cache->persist($this->file);

        $loaded = ResultCache::load($this->file);

        $this->assertSame([Outcome::Passed, Outcome::Failed], $loaded->outcomes('tests/FooTest.php::testA'));
        $this->assertSame(0.25, $loaded->duration('tests/FooTest.php::testA'));
    }

    public function testMissingFileLoadsAsEmptyCache(): void
    {
        $cache = ResultCache::load($this->file);

        $this->assertSame([], $cache->outcomes('tests/FooTest.php::testA'));
        $this->assertNull($cache->duration('tests/FooTest.php::testA'));
    }

    public function testCorruptFileLoadsAsEmptyCache(): void
    {
        mkdir(dirname($this->file), 0o777, true);
        file_put_contents($this->file, '{not json');

        $this->assertSame([], ResultCache::load($this->file)->outcomes('tests/FooTest.php::testA'));
    }

    public function testIncompatibleVersionIsDiscardedNotMisread(): void
    {
        mkdir(dirname($this->file), 0o777, true);
        file_put_contents($this->file, json_encode([
            'version' => ResultCache::VERSION + 1,
            'tests'   => ['tests/FooTest.php::testA' => ['outcomes' => ['fail'], 'duration' => 1.0]],
        ]));

        $this->assertSame([], ResultCache::load($this->file)->outcomes('tests/FooTest.php::testA'));
    }

    public function testUnknownOutcomeValuesAreSkippedOnLoad(): void
    {
        mkdir(dirname($this->file), 0o777, true);
        file_put_contents($this->file, json_encode([
            'version' => ResultCache::VERSION,
            'tests'   => ['tests/FooTest.php::testA' => ['outcomes' => ['fail', 'exploded', 'pass'], 'duration' => 1.0]],
        ]));

        $this->assertSame(
            [Outcome::Failed, Outcome::Passed],
            ResultCache::load($this->file)->outcomes('tests/FooTest.php::testA'),
        );
    }

    public function testHistoryIsBoundedMostRecentFirst(): void
    {
        $cache = new ResultCache();

        for ($run = 0; $run < ResultCache::HISTORY_LIMIT + 3; $run++) {
            $cache->record('tests/FooTest.php::testA', $run === 0 ? Outcome::Errored : Outcome::Passed, 0.1);
        }

        $outcomes = $cache->outcomes('tests/FooTest.php::testA');

        $this->assertCount(ResultCache::HISTORY_LIMIT, $outcomes);
        // The oldest run (the error) fell off the end of the window.
        $this->assertNotContains(Outcome::Errored, $outcomes);
    }
}
