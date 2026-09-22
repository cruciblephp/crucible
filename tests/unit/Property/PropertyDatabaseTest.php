<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Property;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Property\Gen;
use LucianoPereira\Crucible\Property\Property;
use LucianoPereira\Crucible\Property\PropertyContext;
use LucianoPereira\Crucible\Property\PropertyFailedError;
use LucianoPereira\Crucible\Runner\PropertyFailures;
use LucianoPereira\Crucible\Runner\PropertyFailureWriter;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(PropertyFailures::class)]
#[CoversClass(PropertyFailureWriter::class)]
#[CoversClass(PropertyContext::class)]
#[CoversClass(PropertyFailedError::class)]
final class PropertyDatabaseTest extends TestCase
{
    protected function tearDown(): void
    {
        PropertyContext::end();
    }

    public function testAStoredSequenceReplaysBeforeAnyRandomCase(): void
    {
        // "n is never 5" fails only for 5; five random cases with this
        // seed do not find it, but the stored sequence [5] does —
        // int(0, 1000) maps choice 5 straight to value 5.
        $id = new TestId('tests/DatabaseFixture.php', 'never five');

        PropertyContext::begin($id, [
            'tests/DatabaseFixture.php::never five#property 0' => [[5]],
        ]);

        try {
            Property::forAll(Gen::int(0, 1_000))
                ->cases(5)
                ->seed(1)
                ->check(static fn(int $n) => Assert::assertNotSame(5, $n));
        } catch (PropertyFailedError $failure) {
            self::assertStringContainsString('by the failure database', $failure->getMessage());
            self::assertStringContainsString('Counterexample: 5', $failure->getMessage());
            self::assertSame('tests/DatabaseFixture.php::never five#property 0', $failure->key);
            self::assertSame([5], $failure->choices);

            return;
        }

        self::fail('The stored counterexample did not replay.');
    }

    public function testEachCheckInATestClaimsItsOwnKey(): void
    {
        PropertyContext::begin(new TestId('tests/DatabaseFixture.php', 'two properties'), []);

        $first  = PropertyContext::claim();
        $second = PropertyContext::claim();

        self::assertSame('tests/DatabaseFixture.php::two properties#property 0', $first['key'] ?? null);
        self::assertSame('tests/DatabaseFixture.php::two properties#property 1', $second['key'] ?? null);
    }

    public function testWithoutAContextThereIsNoKeyAndNoDatabase(): void
    {
        PropertyContext::end();

        self::assertNull(PropertyContext::claim());
    }

    public function testTheRunnerPutsTheReplayDataOnTheEvent(): void
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
            new TestId('tests/DatabaseFixture.php', 'always falsified'),
            static function (array $values): void {
                Property::forAll(Gen::int(0, 100))->seed(4)->check(static fn(int $n): bool => false);
            },
            MetadataCollection::from(),
        );

        (new TestRunner($emitter, new RunnerOptions()))->execute([new TestGroup('db fixtures', [$definition])]);

        $event = $captured->last;

        self::assertInstanceOf(TestFinished::class, $event, 'The event carries no property replay data.');
        self::assertNotNull($event->property, 'The event carries no property replay data.');

        self::assertSame('tests/DatabaseFixture.php::always falsified#property 0', $event->property['key']);
        self::assertIsList($event->property['choices']);
    }

    public function testACleanReplayRidesThePassingFinishEvent(): void
    {
        // The bug is fixed: the stored counterexample [5] no longer
        // falsifies. The passing first attempt reports the key clean —
        // the per-key resolution signal pruning needs (D-071).
        $key   = 'tests/DatabaseFixture.php::healed#property 0';
        $event = $this->runOne('healed', static function (): void {
            Property::forAll(Gen::int(0, 1_000))
                ->cases(3)
                ->seed(1)
                ->check(static fn(int $n) => Assert::assertNotSame(-1, $n));
        }, [$key => [[5]]]);

        self::assertSame(Outcome::Passed, $event->outcome);
        self::assertSame([$key], $event->propertyClean);
    }

    public function testAnInertStoredSequenceIsNotReportedClean(): void
    {
        // suchThat rejects every replayed candidate → CannotGenerate:
        // the entry could not replay, so it was not proven fixed and
        // must survive pruning (the D-040 inert-entry promise).
        $key   = 'tests/DatabaseFixture.php::reshaped#property 0';
        $event = $this->runOne('reshaped', static function (): void {
            Property::forAll(Gen::int(0, 1_000)->suchThat(static fn(int $n): bool => $n >= 500))
                ->cases(3)
                ->seed(1)
                ->check(static fn(int $n) => Assert::assertNotSame(-1, $n));
        }, [$key => [[5]]]);

        self::assertSame(Outcome::Passed, $event->outcome);
        self::assertSame([], $event->propertyClean);
    }

    public function testAFailingTestReportsNoCleanKeys(): void
    {
        // The first check replays its stored sequence clean, but the
        // second falsifies the test — an aborted-or-failed attempt
        // must not prune anything.
        $key   = 'tests/DatabaseFixture.php::half healed#property 0';
        $event = $this->runOne('half healed', static function (): void {
            Property::forAll(Gen::int(0, 1_000))
                ->cases(3)
                ->seed(1)
                ->check(static fn(int $n) => Assert::assertNotSame(-1, $n));

            Property::forAll(Gen::int(0, 100))->cases(3)->seed(4)->check(static fn(int $n): bool => false);
        }, [$key => [[5]]]);

        self::assertSame(Outcome::Failed, $event->outcome);
        self::assertSame([], $event->propertyClean);
    }

    public function testTheWriterPrunesCleanAndOrphanedKeysOnACompleteRun(): void
    {
        $file = sys_get_temp_dir() . '/crucible-propdb-' . uniqid() . '.json';

        try {
            PropertyFailures::save($file, [
                'tests/A.php::t#property 0'    => [[1]],
                'tests/Gone.php::t#property 0' => [[2]],
                'tests/B.php::t#property 0'    => [[3]],
            ]);

            $emitter = new Emitter(new SystemClock());
            $emitter->subscribe(new PropertyFailureWriter($file));

            $emitter->emit(new TestFinished(new TestId('tests/A.php', 't'), Outcome::Passed, 0.01, propertyClean: ['tests/A.php::t#property 0']));
            $emitter->emit(new TestFinished(new TestId('tests/B.php', 't'), Outcome::Passed, 0.01));
            $emitter->emit(new RunFinished(new RunSummary(passed: 2), 0.1, complete: true));

            // A's entry replayed clean; Gone's test no longer exists;
            // B ran without a clean signal — only B survives.
            self::assertSame(['tests/B.php::t#property 0' => [[3]]], PropertyFailures::load($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAnIncompleteRunPrunesCleanKeysButNeverOrphans(): void
    {
        $file = sys_get_temp_dir() . '/crucible-propdb-' . uniqid() . '.json';

        try {
            PropertyFailures::save($file, [
                'tests/A.php::t#property 0'    => [[1]],
                'tests/Gone.php::t#property 0' => [[2]],
            ]);

            $emitter = new Emitter(new SystemClock());
            $emitter->subscribe(new PropertyFailureWriter($file));

            $emitter->emit(new TestFinished(new TestId('tests/A.php', 't'), Outcome::Passed, 0.01, propertyClean: ['tests/A.php::t#property 0']));
            $emitter->emit(new RunFinished(new RunSummary(passed: 1), 0.1));

            // Clean is a per-key proof, valid on any run; the orphan
            // sweep needs the whole suite to have finished (D-071).
            self::assertSame(['tests/Gone.php::t#property 0' => [[2]]], PropertyFailures::load($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Runs one closure as a test with the given failure database and
     * returns its finish event.
     *
     * @param non-empty-string               $name
     * @param array<string, list<list<int>>> $known
     */
    private function runOne(string $name, callable $body, array $known): TestFinished
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
            new TestId('tests/DatabaseFixture.php', $name),
            static fn(array $values): mixed => $body(),
            MetadataCollection::from(),
        );

        (new TestRunner($emitter, new RunnerOptions(propertyFailures: $known)))
            ->execute([new TestGroup('db fixtures', [$definition])]);

        self::assertInstanceOf(TestFinished::class, $captured->last, 'No test:finish event was emitted.');

        return $captured->last;
    }

    public function testTheDatabaseRoundTripsMergesAndBounds(): void
    {
        $file = sys_get_temp_dir() . '/crucible-propdb-' . uniqid() . '.json';

        try {
            self::assertSame([], PropertyFailures::load($file));

            $merged = PropertyFailures::merge([], ['k' => [[1, 2]]]);
            $merged = PropertyFailures::merge($merged, ['k' => [[1, 2], [3]]]); // duplicate ignored

            self::assertSame(['k' => [[3], [1, 2]]], $merged);

            // Bounded: only the five newest survive.
            for ($i = 0; $i < 9; $i++) {
                $merged = PropertyFailures::merge($merged, ['k' => [[$i, $i]]]);
            }

            self::assertCount(5, $merged['k']);

            PropertyFailures::save($file, $merged);

            self::assertSame($merged, PropertyFailures::load($file));

            file_put_contents($file, '{broken');

            self::assertSame([], PropertyFailures::load($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
