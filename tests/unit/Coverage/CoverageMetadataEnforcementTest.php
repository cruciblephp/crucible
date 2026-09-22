<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Coverage;

use ArrayObject;
use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Coverage\CoverageCollector;
use LucianoPereira\Crucible\Coverage\CoverageDriver;
use LucianoPereira\Crucible\Coverage\CoverageWindow;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;
use ReflectionClass;

/**
 * D-063 end to end: the two strictness knobs reclassify passing
 * tests as risky in the oracle's words, and the aggregate takes the
 * covers-filtered contribution while the per-test map keeps truth.
 */
#[CoversClass(TestRunner::class)]
#[CoversClass(CoverageCollector::class)]
final class CoverageMetadataEnforcementTest extends TestCase
{
    /**
     * @param list<TestDefinition> $definitions
     *
     * @return array{outcomes: array<string, Outcome>, reasons: array<string, ?string>}
     */
    private function run(array $definitions, RunnerOptions $options): array
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-17T12:00:00+00:00')));

        /** @var ArrayObject<int, Envelope> $log */
        $log = new ArrayObject();

        $emitter->subscribe(new readonly class ($log) implements Listener {
            /**
             * @param ArrayObject<int, Envelope> $log
             */
            public function __construct(
                private ArrayObject $log,
            ) {}

            public function handle(Envelope $envelope): void
            {
                $this->log->append($envelope);
            }
        });

        (new TestRunner($emitter, $options))->run([
            new TestGroup('tests/CoversEnforcement.php', $definitions),
        ]);

        $outcomes = [];
        $reasons  = [];

        foreach ($log as $envelope) {
            if ($envelope->event instanceof TestFinished) {
                $outcomes[$envelope->event->test->name] = $envelope->event->outcome;
                $reasons[$envelope->event->test->name]  = $envelope->event->reason;
            }
        }

        return ['outcomes' => $outcomes, 'reasons' => $reasons];
    }

    public function testRequireCoverageMetadataReclassifiesEvenWithoutACoverageRun(): void
    {
        $result = $this->run(
            [
                new TestDefinition(
                    new TestId('tests/CoversEnforcement.php', 'bare'),
                    function (array $args): void {
                        $this->addToAssertionCount(1);
                    },
                    MetadataCollection::from(),
                ),
                new TestDefinition(
                    new TestId('tests/CoversEnforcement.php', 'claimed'),
                    function (array $args): void {
                        $this->addToAssertionCount(1);
                    },
                    MetadataCollection::from(new \LucianoPereira\Crucible\Attributes\CoversNothing()),
                ),
            ],
            new RunnerOptions(requireCoverageMetadata: true),
        );

        self::assertSame(Outcome::Risky, $result['outcomes']['bare']);
        self::assertSame('This test does not define a code coverage target but is expected to do so', $result['reasons']['bare']);
        self::assertSame(Outcome::Passed, $result['outcomes']['claimed']);
    }

    public function testStrictCheckAndDiscardOverScopedWindows(): void
    {
        $calculator = new ReflectionClass(ProbeCalculator::class);
        $helper     = new ReflectionClass(ProbeHelper::class);
        $file       = $calculator->getFileName();

        if ($file === false) {
            self::fail('The fixture class must have a file.');
        }

        $window = new CoverageWindow([$file => [
            (int) $calculator->getStartLine() + 2 => 1,
            (int) $helper->getStartLine() + 2     => 1,
        ]]);

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-17T12:00:00+00:00')));

        /** @var ArrayObject<int, Envelope> $log */
        $log = new ArrayObject();

        $emitter->subscribe(new readonly class ($log) implements Listener {
            /**
             * @param ArrayObject<int, Envelope> $log
             */
            public function __construct(
                private ArrayObject $log,
            ) {}

            public function handle(Envelope $envelope): void
            {
                $this->log->append($envelope);
            }
        });

        $driver = new readonly class ($window) implements CoverageDriver {
            public function __construct(
                private CoverageWindow $window,
            ) {}

            public function name(): string
            {
                return 'scripted';
            }

            public function start(): void {}

            public function stop(): CoverageWindow
            {
                return $this->window;
            }
        };

        // Scope includes the real fixture file so the window survives.
        $collector = new CoverageCollector($driver, [$file]);

        (new TestRunner($emitter, new RunnerOptions(beStrictAboutCoverageMetadata: true), coverage: $collector))->run([
            new TestGroup('tests/CoversEnforcement.php', [
                new TestDefinition(
                    new TestId('tests/CoversEnforcement.php', 'strays'),
                    function (array $args): void {
                        $this->addToAssertionCount(1);
                    },
                    MetadataCollection::from(new CoversClass(ProbeCalculator::class)),
                ),
            ]),
        ]);

        $outcome = null;
        $reason  = null;

        foreach ($log as $envelope) {
            if ($envelope->event instanceof TestFinished) {
                $outcome = $envelope->event->outcome;
                $reason  = $envelope->event->reason;
            }
        }

        self::assertSame(Outcome::Risky, $outcome);
        self::assertSame(
            "This test executed code that is not listed as code to be covered or used:\n- " . ProbeHelper::class,
            $reason,
        );

        // The risky test's aggregate contribution is hit-demoted (the
        // oracle discards coverage, keeps denominators) while the
        // per-test map keeps the observed truth.
        $data = $collector->data();

        self::assertSame(-1, $data->lines[$file][(int) $calculator->getStartLine() + 2]);
        self::assertSame(
            [(int) $calculator->getStartLine() + 2, (int) $helper->getStartLine() + 2],
            $data->tests['tests/CoversEnforcement.php::strays'][$file] ?? null,
        );
    }
}
