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
use LucianoPereira\Crucible\Impact\DependencyGraph;
use LucianoPereira\Crucible\Impact\DependencyIndex;

use function dirname;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function mkdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * The index earns its place only if it never changes the answer, so the
 * load-bearing test is the invariant itself: the same closure, with the
 * index warm and without it at all.
 */
#[CoversClass(DependencyIndex::class)]
final class DependencyIndexTest extends TestCase
{
    /** @var non-empty-string */
    private string $scratch = '/tmp';

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/crucible-depindex-' . getmypid();

        if (!is_dir($this->scratch)) {
            mkdir($this->scratch, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (['index/.gitkeep'] as $file) {
            @unlink($this->scratch . '/' . $file);
        }
    }

    public function testAWarmIndexProducesTheIdenticalClosure(): void
    {
        $root  = dirname(__DIR__, 3);
        $file  = $root . '/src/Impact/ImpactSelection.php';
        $print = DependencyIndex::fingerprintOf($root, DependencyIndex::suiteConfigurationIn($root));

        $cold = (new DependencyGraph($root))->closureOf($file);

        // First indexed run populates; second replays every entry from it.
        $populate = new DependencyGraph($root, index: new DependencyIndex($this->scratch, $print, $root));
        $populate->closureOf($file);
        $populate->persist();

        $warm = (new DependencyGraph($root, index: new DependencyIndex($this->scratch, $print, $root)))->closureOf($file);

        self::assertSame($cold, $warm, 'Incrementality may skip work, never change the answer.');
        self::assertNotSame([], $cold, 'A closure of nothing would make this test vacuous.');
    }

    public function testAChangedFileIsNotReplayedFromTheIndex(): void
    {
        $source = $this->scratch . '/Subject.php';
        file_put_contents($source, "<?php\nclass DepIndexSubjectA {}\n");

        $index = new DependencyIndex($this->scratch, 'fixed');
        $index->load();
        $index->record($source, ['/one.php']);
        $index->save();

        $reader = new DependencyIndex($this->scratch, 'fixed');
        $reader->load();
        self::assertSame(['/one.php'], $reader->reuse($source), 'Unchanged content replays.');

        file_put_contents($source, "<?php\nclass DepIndexSubjectB {}\n");

        $after = new DependencyIndex($this->scratch, 'fixed');
        $after->load();
        self::assertNull($after->reuse($source), 'Changed content must be rescanned, not replayed.');

        @unlink($source);
    }

    public function testADifferentConfigurationLandsOnADifferentIndex(): void
    {
        $source = $this->scratch . '/Config.php';
        file_put_contents($source, "<?php\nclass DepIndexConfig {}\n");

        $one = new DependencyIndex($this->scratch, 'fingerprint-one');
        $one->load();
        $one->record($source, ['/one.php']);
        $one->save();

        // Same file, same bytes, different configuration: a cache keyed on
        // content alone would answer from the other config's data.
        $two = new DependencyIndex($this->scratch, 'fingerprint-two');
        $two->load();

        self::assertNull($two->reuse($source));

        @unlink($source);
    }

    public function testASuiteConfigurationAppearingChangesTheFingerprint(): void
    {
        $root = $this->scratch . '/proj';

        if (!is_dir($root . '/tests')) {
            mkdir($root . '/tests', 0o777, true);
        }

        $before = DependencyIndex::fingerprintOf($root, DependencyIndex::suiteConfigurationIn($root));

        // A Pest.php adds edges to every file beneath it without changing
        // one byte of them (D-033) — the case a content hash cannot see.
        file_put_contents($root . '/tests/Pest.php', "<?php\n");

        $after = DependencyIndex::fingerprintOf($root, DependencyIndex::suiteConfigurationIn($root));

        self::assertNotSame($before, $after);

        @unlink($root . '/tests/Pest.php');
    }

    public function testAnIndexSurvivesTheCheckoutMovingToAnotherPath(): void
    {
        // The normal CI case: the cache directory is restored into a
        // checkout at a different absolute path. Keyed on absolute paths
        // the file would load and match nothing — no wrong answer, just
        // the feature quietly not working where it was built to work.
        $here  = $this->scratch . '/checkout-a';
        $there = $this->scratch . '/checkout-b';

        foreach ([$here, $there] as $root) {
            if (!is_dir($root . '/src')) {
                mkdir($root . '/src', 0o777, true);
            }

            file_put_contents($root . '/src/Thing.php', "<?php\nclass MovedThing {}\n");
        }

        $written = new DependencyIndex($this->scratch, 'moved', $here);
        $written->load();
        $written->record($here . '/src/Thing.php', [$here . '/src/Other.php']);
        $written->save();

        $read = new DependencyIndex($this->scratch, 'moved', $there);
        $read->load();

        self::assertSame(
            [$there . '/src/Other.php'],
            $read->reuse($there . '/src/Thing.php'),
            'The index must key on project-relative paths, or a restored cache matches nothing.',
        );
    }

    public function testTheFingerprintDoesNotDependOnWhereTheProjectSits(): void
    {
        $here  = $this->scratch . '/print-a';
        $there = $this->scratch . '/print-b';

        foreach ([$here, $there] as $root) {
            if (!is_dir($root . '/tests')) {
                mkdir($root . '/tests', 0o777, true);
            }

            file_put_contents($root . '/tests/Pest.php', "<?php\n");
        }

        self::assertSame(
            DependencyIndex::fingerprintOf($here, DependencyIndex::suiteConfigurationIn($here)),
            DependencyIndex::fingerprintOf($there, DependencyIndex::suiteConfigurationIn($there)),
            'Two checkouts of the same project must land on the same index.',
        );
    }

    public function testAnEntryReachingOutsideTheProjectIsNotStored(): void
    {
        // A composer path repository — how a monorepo links a local
        // package — resolves outside the root. Stored with that absolute
        // path left in, a restored cache would replay THIS machine's
        // path, a changed file elsewhere would never match it, and a
        // test would silently not be selected. Rescanning costs time;
        // replaying costs correctness.
        $project = $this->scratch . '/portable';
        $outside = $this->scratch . '/sibling';

        foreach ([$project . '/src', $outside] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }
        }

        file_put_contents($project . '/src/Uses.php', "<?php\nclass PortableUses {}\n");
        file_put_contents($outside . '/Shared.php', "<?php\nclass PortableShared {}\n");

        $index = new DependencyIndex($this->scratch, 'portable', $project);
        $index->load();
        $index->record($project . '/src/Uses.php', [$outside . '/Shared.php']);
        $index->save();

        $reader = new DependencyIndex($this->scratch, 'portable', $project);
        $reader->load();

        self::assertNull(
            $reader->reuse($project . '/src/Uses.php'),
            'An entry that cannot be made portable must be dropped, not stored with an absolute path.',
        );
    }

    public function testAnEntryWhollyInsideTheProjectIsStillStored(): void
    {
        // The guard must drop the unportable entry and nothing else.
        $project = $this->scratch . '/portable';

        if (!is_dir($project . '/src')) {
            mkdir($project . '/src', 0o777, true);
        }

        file_put_contents($project . '/src/Inside.php', "<?php\nclass PortableInside {}\n");
        file_put_contents($project . '/src/Other.php', "<?php\nclass PortableOther {}\n");

        $index = new DependencyIndex($this->scratch, 'inside', $project);
        $index->load();
        $index->record($project . '/src/Inside.php', [$project . '/src/Other.php']);
        $index->save();

        $reader = new DependencyIndex($this->scratch, 'inside', $project);
        $reader->load();

        self::assertSame([$project . '/src/Other.php'], $reader->reuse($project . '/src/Inside.php'));
    }
}
