<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Snapshot;

use Closure;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Snapshot\SnapshotRepository;
use LucianoPereira\Crucible\Snapshot\Snapshots;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function base64_encode;
use function file_get_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function realpath;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(SnapshotRepository::class)]
#[CoversClass(Snapshots::class)]
final class SnapshotTest extends TestCase
{
    /** @var non-empty-string */
    private string $root = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-snap-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real       = realpath($root);
        $this->root = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        foreach (['/tests/__snapshots__/FakeTest.snap', '/tests/__snapshots__', '/tests', ''] as $entry) {
            $path = $this->root . $entry;

            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function testTheFormatRoundTripsHostileContent(): void
    {
        $entries = [
            'plain » #1'      => "line one\nline two",
            'tricky » #1'     => "<<<\n>>> not a key\n\n  already indented",
            'empty » #1'      => '',
            "multi\nline key" => 'value',
        ];

        self::assertSame($entries, SnapshotRepository::parse(SnapshotRepository::render($entries)));
    }

    public function testEditorTrimmedBlankLinesStillParse(): void
    {
        $rendered = SnapshotRepository::render(['k » #1' => "a\n\nb"]);

        // Simulate an editor stripping trailing whitespace from the
        // blank content line.
        $trimmed = str_replace("\n  \n", "\n\n", $rendered);

        self::assertSame(["k » #1" => "a\n\nb"], SnapshotRepository::parse($trimmed));
    }

    public function testMissingSnapshotFailsAndNamesTheFlag(): void
    {
        $event = $this->run('records a value', static function (): void {
            Snapshots::match(['brew' => 'espresso']);
        });

        self::assertSame(Outcome::Failed, $event->outcome);
        self::assertStringContainsString('No snapshot recorded', (string) $event->failure?->message);
        self::assertStringContainsString('--update-snapshots', (string) $event->failure?->message);
    }

    public function testUpdateRecordsThenANormalRunMatches(): void
    {
        $body = static function (): void {
            Snapshots::match(['brew' => 'espresso', 'ratio' => 1 / 4]);
        };

        self::assertSame(Outcome::Passed, $this->run('records a value', $body, update: true)->outcome);

        $snapFile = $this->root . '/tests/__snapshots__/FakeTest.snap';

        self::assertStringContainsString('>>> records a value » #1', (string) file_get_contents($snapFile));

        self::assertSame(Outcome::Passed, $this->run('records a value', $body)->outcome);
    }

    public function testAChangedValueFailsWithTheStoredExpectation(): void
    {
        self::assertSame(Outcome::Passed, $this->run('records a value', static function (): void {
            Snapshots::match('first shape');
        }, update: true)->outcome);

        $event = $this->run('records a value', static function (): void {
            Snapshots::match('second shape');
        });

        self::assertSame(Outcome::Failed, $event->outcome);
        self::assertStringContainsString('Snapshot mismatch', (string) $event->failure?->message);
    }

    public function testTheScreenshotFlavorStoresTheReferenceAndRendersAVisualDiff(): void
    {
        $reference = 'PNG-REFERENCE-BYTES';
        $changed   = 'PNG-CHANGED-BYTES';

        // Record: the hash lands in the .snap, the reference image
        // beside it (D-064 — recording stays explicit).
        self::assertSame(Outcome::Passed, $this->run('shot', static function () use ($reference): void {
            Snapshots::matchScreenshot($reference, 'home');
        }, update: true)->outcome);

        $imageBase = $this->root . '/tests/__snapshots__/FakeTest/shot_home';

        self::assertStringContainsString('png-sha256:', (string) file_get_contents($this->root . '/tests/__snapshots__/FakeTest.snap'));
        self::assertSame($reference, file_get_contents($imageBase . '.png'));

        // Same rendering: green.
        self::assertSame(Outcome::Passed, $this->run('shot', static function () use ($reference): void {
            Snapshots::matchScreenshot($reference, 'home');
        })->outcome);

        // A changed rendering: fails naming the visual diff, which
        // embeds both images.
        $event = $this->run('shot', static function () use ($changed): void {
            Snapshots::matchScreenshot($changed, 'home');
        });

        try {
            self::assertSame(Outcome::Failed, $event->outcome);
            self::assertStringContainsString('Screenshot mismatch', (string) $event->failure?->message);
            self::assertStringContainsString('Visual diff: ', (string) $event->failure?->message);

            $diff = (string) file_get_contents($imageBase . '.diff.html');

            self::assertStringContainsString('mix-blend-mode: difference', $diff);
            self::assertStringContainsString(base64_encode($reference), $diff);
            self::assertStringContainsString(base64_encode($changed), $diff);
            self::assertSame($changed, file_get_contents($imageBase . '.actual.png'));
        } finally {
            foreach ([$imageBase . '.png', $imageBase . '.actual.png', $imageBase . '.diff.html'] as $artifact) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            }

            if (is_dir($this->root . '/tests/__snapshots__/FakeTest')) {
                rmdir($this->root . '/tests/__snapshots__/FakeTest');
            }
        }
    }

    public function testUpdateRunsReportCreatedAndUpdatedCounts(): void
    {
        // First recording: created (D-066), on the event payload.
        $event = $this->run('counted', static function (): void {
            Snapshots::match('first');
            Snapshots::match('second');
        }, update: true);

        self::assertSame(['created' => 2, 'updated' => 0], $event->snapshots);

        // Unchanged re-record: nothing to report — payload omitted.
        $event = $this->run('counted', static function (): void {
            Snapshots::match('first');
            Snapshots::match('second');
        }, update: true);

        self::assertNull($event->snapshots);

        // A changed value: updated.
        $event = $this->run('counted', static function (): void {
            Snapshots::match('first');
            Snapshots::match('CHANGED');
        }, update: true);

        self::assertSame(['created' => 0, 'updated' => 1], $event->snapshots);

        // Normal (non-update) runs never carry the payload.
        $event = $this->run('counted', static function (): void {
            Snapshots::match('first');
            Snapshots::match('CHANGED');
        });

        self::assertNull($event->snapshots);
    }

    public function testVisitedKeysRideTheFinishEvent(): void
    {
        // What the obsolete-snapshot pruner (D-071) unions across the
        // run — carried on every run, worker-transparent by riding
        // the event.
        $event = $this->run('visits two', static function (): void {
            Snapshots::match('first');
            Snapshots::match('second', 'named');
        }, update: true);

        self::assertSame(['visits two » #1', 'visits two » named'], $event->snapshotKeys);
    }

    public function testAFilteredUpdateKeepsUnvisitedKeys(): void
    {
        $this->run('records a value', static function (): void {
            Snapshots::match('kept');
        }, update: true);

        $this->run('another test', static function (): void {
            Snapshots::match('added');
        }, update: true);

        $contents = (string) file_get_contents($this->root . '/tests/__snapshots__/FakeTest.snap');

        self::assertStringContainsString('records a value » #1', $contents);
        self::assertStringContainsString('another test » #1', $contents);
    }

    public function testNamedAndCountedSnapshotsCoexist(): void
    {
        $this->run('several claims', static function (): void {
            Snapshots::match('one');
            Snapshots::match('two');
            Snapshots::match('three', 'the named one');
        }, update: true);

        $contents = (string) file_get_contents($this->root . '/tests/__snapshots__/FakeTest.snap');

        self::assertStringContainsString('several claims » #1', $contents);
        self::assertStringContainsString('several claims » #2', $contents);
        self::assertStringContainsString('several claims » the named one', $contents);
    }

    public function testOutsideTheRunnerSnapshotsRefuseLoudly(): void
    {
        Snapshots::end(); // simulate direct engine use with no context

        try {
            Snapshots::match('anything');
        } catch (\LucianoPereira\Crucible\Exceptions\ConfigurationException $refused) {
            self::assertStringContainsString('snapshot context', $refused->getMessage());

            return;
        }

        self::fail('Snapshots::match() ran without a context.');
    }

    public function testTheDogfoodSnapshotMatches(): void
    {
        // A committed snapshot in this repository's own tree — the
        // phpunit-dialect surface working end to end.
        self::assertMatchesSnapshot([
            'engine'   => 'crucible',
            'feature'  => 'snapshots',
            'dialects' => ['phpunit', 'pest', 'crucible', 'inline'],
        ], 'the dogfood snapshot');
    }

    /**
     * Runs one closure as a test in a throwaway working directory and
     * returns its finish event.
     *
     * @param non-empty-string $name
     */
    private function run(string $name, Closure $body, bool $update = false): TestFinished
    {
        $captured = new class implements Listener {
            public ?TestFinished $last = null;

            public function handle(Envelope $envelope): void
            {
                if ($envelope->event instanceof TestFinished) {
                    $this->last = $envelope->event;
                }
            }
        };

        $emitter = new Emitter(new SystemClock());
        $emitter->subscribe($captured);

        $definition = new TestDefinition(
            new TestId('tests/FakeTest.php', $name),
            static fn(array $values): mixed => $body(),
            MetadataCollection::from(),
        );

        (new TestRunner($emitter, new RunnerOptions(
            workingDirectory: new WorkingDirectory($this->root),
            updateSnapshots: $update,
        )))->execute([new TestGroup('snapshot fixtures', [$definition])]);

        self::assertInstanceOf(TestFinished::class, $captured->last, 'No test:finish event was emitted.');

        return $captured->last;
    }
}
