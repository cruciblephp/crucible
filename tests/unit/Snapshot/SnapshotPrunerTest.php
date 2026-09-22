<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Snapshot;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Snapshot\SnapshotPruner;
use LucianoPereira\Crucible\Snapshot\SnapshotRepository;
use LucianoPereira\Crucible\Test\TestId;

use function array_keys;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function realpath;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(SnapshotPruner::class)]
final class SnapshotPrunerTest extends TestCase
{
    /** @var non-empty-string */
    private string $root = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-prune-' . uniqid();

        mkdir($root . '/tests/__snapshots__', 0o777, true);

        $real       = realpath($root);
        $this->root = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testACompleteRunPrunesUnvisitedEntries(): void
    {
        $snap = $this->seed('FakeTest', ['alive » #1' => 'kept', 'stale » #1' => 'gone']);

        $this->emit(
            [new TestFinished(new TestId('tests/FakeTest.php', 'alive'), Outcome::Passed, 0.01, snapshotKeys: ['alive » #1'])],
            complete: true,
        );

        self::assertSame(['alive » #1'], array_keys(SnapshotRepository::parse((string) file_get_contents($snap))));
    }

    public function testAnIncompleteRunPrunesNothing(): void
    {
        $snap = $this->seed('FakeTest', ['alive » #1' => 'kept', 'stale » #1' => 'gone']);

        $this->emit(
            [new TestFinished(new TestId('tests/FakeTest.php', 'alive'), Outcome::Passed, 0.01, snapshotKeys: ['alive » #1'])],
            complete: false,
        );

        self::assertCount(2, SnapshotRepository::parse((string) file_get_contents($snap)));
    }

    public function testAFailedTestShieldsItsWholeSnapshotFile(): void
    {
        // The failing body may have aborted before its later snapshot
        // calls — nothing it owns may be treated as obsolete.
        $snap = $this->seed('FakeTest', ['early » #1' => 'kept', 'late » #1' => 'unreached']);

        $this->emit(
            [
                new TestFinished(new TestId('tests/FakeTest.php', 'early'), Outcome::Passed, 0.01, snapshotKeys: ['early » #1']),
                new TestFinished(new TestId('tests/FakeTest.php', 'late'), Outcome::Failed, 0.01),
            ],
            complete: true,
        );

        self::assertCount(2, SnapshotRepository::parse((string) file_get_contents($snap)));
    }

    public function testAFullyObsoleteFileIsDeletedWithItsEmptyDirectories(): void
    {
        // The owning test was deleted: no finished test references
        // Old.snap, and the complete run proves nothing else will.
        $this->seed('Old', ['gone » #1' => 'value']);

        $this->emit(
            [new TestFinished(new TestId('tests/FakeTest.php', 'alive'), Outcome::Passed, 0.01)],
            complete: true,
        );

        self::assertFalse(is_file($this->root . '/tests/__snapshots__/Old.snap'));
        self::assertFalse(is_dir($this->root . '/tests/__snapshots__'));
    }

    public function testAPrunedScreenshotEntryTakesItsImagesAlong(): void
    {
        $snap = $this->seed('FakeTest', [
            'alive » #1' => 'kept',
            'shot » #1'  => 'png-sha256:0000',
        ]);

        $imageDirectory = $this->root . '/tests/__snapshots__/FakeTest';

        mkdir($imageDirectory, 0o777, true);
        file_put_contents($imageDirectory . '/shot_1.png', 'png');
        file_put_contents($imageDirectory . '/shot_1.diff.html', 'diff');

        $this->emit(
            [new TestFinished(new TestId('tests/FakeTest.php', 'alive'), Outcome::Passed, 0.01, snapshotKeys: ['alive » #1'])],
            complete: true,
        );

        self::assertSame(['alive » #1'], array_keys(SnapshotRepository::parse((string) file_get_contents($snap))));
        self::assertFalse(is_file($imageDirectory . '/shot_1.png'));
        self::assertFalse(is_dir($imageDirectory));
    }

    /**
     * Writes a seeded snapshot file and returns its path.
     *
     * @param non-empty-string      $name
     * @param array<string, string> $entries
     *
     * @return non-empty-string
     */
    private function seed(string $name, array $entries): string
    {
        $snap = $this->root . '/tests/__snapshots__/' . $name . '.snap';

        file_put_contents($snap, SnapshotRepository::render($entries));

        return $snap;
    }

    /**
     * Runs the given finish events plus a run:finish through a pruner
     * watching tests/FakeTest.php.
     *
     * @param list<TestFinished> $finishes
     */
    private function emit(array $finishes, bool $complete): void
    {
        $emitter = new Emitter(new SystemClock());
        $emitter->subscribe(new SnapshotPruner(new WorkingDirectory($this->root), ['tests/FakeTest.php']));

        $passed = 0;

        foreach ($finishes as $finish) {
            $emitter->emit($finish);
            $passed++;
        }

        $emitter->emit(new RunFinished(new RunSummary(passed: $passed), 0.1, $complete));
    }

    private function removeTree(string $directory): void
    {
        $entries = scandir($directory);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
