<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Snapshot;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Snapshot\InlineSnapshotWriter;
use LucianoPereira\Crucible\Snapshot\SnapshotRepository;
use LucianoPereira\Crucible\Snapshot\Snapshots;
use LucianoPereira\Crucible\Test\TestId;

/**
 * The inline snapshot assertion (D-076): the expected value lives in the
 * test source. Comparison works everywhere and never rewrites;
 * recording is gated to a sequential run and queues a source edit rather
 * than fabricating one on a normal run. The dogfood cases at the end run
 * the real dialect entry point (call-site capture included) against
 * committed literals.
 */
#[CoversClass(Snapshots::class)]
final class InlineSnapshotTest extends TestCase
{
    private function begin(bool $update, bool $rewrite): void
    {
        Snapshots::begin(new TestId('tests/Fixture.php', 't'), $update, new SnapshotRepository(), new WorkingDirectory('/tmp'), $rewrite);
    }

    protected function tearDown(): void
    {
        Snapshots::end();
        InlineSnapshotWriter::reset();
    }

    public function testAMatchingRecordedValuePassesWithoutQueuingARewrite(): void
    {
        $this->begin(update: false, rewrite: false);

        Snapshots::matchInline([1, 2], Exporter::export([1, 2]), 'tests/Fixture.php', 5);

        $this->assertFalse(InlineSnapshotWriter::hasPending(), 'A comparison must never queue a rewrite.');
    }

    public function testAMismatchFailsWhenNotUpdating(): void
    {
        $this->begin(update: false, rewrite: false);

        $this->expectException(AssertionFailedError::class);

        Snapshots::matchInline('actual', "'stale'", 'tests/Fixture.php', 5);
    }

    public function testAnAbsentValueFailsNamingTheFlag(): void
    {
        $this->begin(update: false, rewrite: false);

        try {
            Snapshots::matchInline('x', null, 'tests/Fixture.php', 5);
            $this->fail('Expected a failure naming --update-snapshots.');
        } catch (AssertionFailedError $error) {
            $this->assertStringContainsString('--update-snapshots', $error->getMessage());
        }
    }

    public function testRecordingWhenRewritingIsForbiddenFailsNamingTheConstraint(): void
    {
        // update mode, but this process may not rewrite source (a worker
        // or a parallel run) — it must fail, never race.
        $this->begin(update: true, rewrite: false);

        try {
            Snapshots::matchInline('x', null, 'tests/Fixture.php', 5);
            $this->fail('Expected a failure naming the sequential-run constraint.');
        } catch (AssertionFailedError $error) {
            $this->assertStringContainsString('sequential run', $error->getMessage());
        }

        $this->assertFalse(InlineSnapshotWriter::hasPending());
    }

    public function testRecordingSequentiallyQueuesTheRewrite(): void
    {
        $this->begin(update: true, rewrite: true);

        Snapshots::matchInline('x', null, 'tests/Fixture.php', 5);

        $this->assertTrue(InlineSnapshotWriter::hasPending());
    }

    // --- Dogfood: the real dialect entry, live compare -----------------

    public function testAScalarInlineSnapshotComparesLive(): void
    {
        $this->assertMatchesInlineSnapshot(42, '42');
    }

    public function testAnArrayInlineSnapshotComparesLive(): void
    {
        $this->assertMatchesInlineSnapshot(['crucible', 'inline'], <<<'SNAPSHOT'
            [
                0 => 'crucible',
                1 => 'inline',
            ]
            SNAPSHOT);
    }
}
