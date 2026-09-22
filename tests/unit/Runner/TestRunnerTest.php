<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use ArrayObject;
use DateTimeImmutable;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Dialect\PhpUnit\TestBuilder;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\EventName;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;
use LucianoPereira\Crucible\TestFixtures\Framework\DemoTest;

use function array_first;
use function array_last;
use function array_map;
use function ob_get_clean;
use function ob_start;
use function trigger_error;
use function usleep;

#[CoversClass(TestRunner::class)]
#[CoversClass(TestBuilder::class)]
final class TestRunnerTest extends TestCase
{
    /** @var list<Envelope> */
    private array $envelopes = [];

    private TestGroup $group;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../_fixtures/framework/DemoTest.php';

        DemoTest::$setUpBeforeClassCalls   = 0;
        DemoTest::$tearDownAfterClassCalls = 0;
        DemoTest::$lifecycle               = [];

        $this->envelopes = [];
        $this->group     = (new TestBuilder())->build(DemoTest::class, 'tests/_fixtures/framework/DemoTest.php');
    }

    private function runFixture(): void
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));

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

        (new TestRunner($emitter))->run([$this->group]);

        $this->envelopes = [...$log];
    }

    public function testDiscoveryExpandsDatasetsAndSkipsHelpers(): void
    {
        $ids = array_map(
            static fn($definition) => $definition->id->toString(),
            $this->group->tests,
        );

        $this->assertContains('tests/_fixtures/framework/DemoTest.php::testSums#small', $ids);
        $this->assertContains('tests/_fixtures/framework/DemoTest.php::testSums#zero', $ids);
        $this->assertContains('tests/_fixtures/framework/DemoTest.php::testInlineRows#0', $ids);
        $this->assertContains('tests/_fixtures/framework/DemoTest.php::testInlineRows#fives', $ids);
        $this->assertContains('tests/_fixtures/framework/DemoTest.php::attributeMarked', $ids);
        $this->assertNotContains('tests/_fixtures/framework/DemoTest.php::helperNotATest', $ids);
        $this->assertNotContains('tests/_fixtures/framework/DemoTest.php::provideSums', $ids);
    }

    public function testOutcomesAreClassifiedPerTheSpec(): void
    {
        $this->runFixture();

        $outcomes = [];

        foreach ($this->envelopes as $envelope) {
            if ($envelope->event instanceof TestFinished) {
                $outcomes[$envelope->event->test->name . ($envelope->event->test->dataset !== null ? '#' . $envelope->event->test->dataset : '')] = $envelope->event->outcome;
            }
        }

        $this->assertSame(Outcome::Passed, $outcomes['testPasses']);
        $this->assertSame(Outcome::Failed, $outcomes['testFails']);
        $this->assertSame(Outcome::Errored, $outcomes['testErrors']);
        $this->assertSame(Outcome::Skipped, $outcomes['testSkips']);
        $this->assertSame(Outcome::Incomplete, $outcomes['testIncomplete']);
        $this->assertSame(Outcome::Risky, $outcomes['testRiskyWithoutAssertions']);
        $this->assertSame(Outcome::Passed, $outcomes['testQuietButLegitimate']);
        $this->assertSame(Outcome::Passed, $outcomes['testExpectedException']);
        $this->assertSame(Outcome::Failed, $outcomes['testMissedExpectedException']);
        $this->assertSame(Outcome::Passed, $outcomes['attributeMarked']);
        $this->assertSame(Outcome::Passed, $outcomes['testSums#small']);
        $this->assertSame(Outcome::Passed, $outcomes['testInlineRows#fives']);
    }

    public function testFailureCarriesStructuredDiffOnTheEvent(): void
    {
        $this->runFixture();

        foreach ($this->envelopes as $envelope) {
            $event = $envelope->event;

            if ($event instanceof TestFinished && $event->test->name === 'testFails') {
                self::assertNotNull($event->failure, 'Expected a failure payload.');
                $this->assertSame("'expected'", $event->failure->expected);
                $this->assertSame("'actual'", $event->failure->actual);

                return;
            }
        }

        $this->fail('testFails event not found');
    }

    public function testLifecycleAndClassHooks(): void
    {
        $this->runFixture();

        $this->assertSame(1, DemoTest::$setUpBeforeClassCalls);
        $this->assertSame(1, DemoTest::$tearDownAfterClassCalls);

        // Every test ran between exactly one setUp and one tearDown.
        $this->assertContains('setUp', DemoTest::$lifecycle);
        $first = DemoTest::$lifecycle;
        $this->assertSame(['setUp', 'testPasses', 'tearDown'], [$first[0], $first[1], $first[2]]);
    }

    public function testStreamDisciplineFirstAndLastEvents(): void
    {
        $this->runFixture();

        $first = array_first($this->envelopes);
        $last  = array_last($this->envelopes);

        $this->assertNotNull($first);
        $this->assertNotNull($last);
        $this->assertSame(EventName::RunStarted, $first->event->name());
        $this->assertSame(EventName::RunFinished, $last->event->name());
    }

    public function testRunFinishReportsCompleteOnlyForAFullFinishedSuite(): void
    {
        // The CLI half (fullSuite) and the runner half (every planned
        // test finished) must BOTH hold for the D-071 pruning gate.
        $passing = new TestDefinition(
            new TestId('tests/CompleteFixture.php', 'passes'),
            static function (array $values): void {
                Assert::assertTrue(true);
            },
            MetadataCollection::from(),
        );
        $failing = new TestDefinition(
            new TestId('tests/CompleteFixture.php', 'fails'),
            static fn(array $values): mixed => Assert::fail('as planned'),
            MetadataCollection::from(),
        );

        $finish = $this->runWith([$passing], new RunnerOptions(fullSuite: true));
        $this->assertTrue($finish->complete);
        $this->assertTrue($finish->payload()['complete'] ?? false);

        $finish = $this->runWith([$passing], new RunnerOptions());
        $this->assertFalse($finish->complete);
        $this->assertArrayNotHasKey('complete', $finish->payload());

        // stop-on halts before the second test: planned > finished.
        $finish = $this->runWith([$failing, $passing], new RunnerOptions(stopOnFailure: true, fullSuite: true));
        $this->assertFalse($finish->complete);
    }

    public function testStopOnHaltsOnTheOutcomeItNames(): void
    {
        $risky = new TestDefinition(
            new TestId('tests/StopFixture.php', 'risky'),
            static function (array $values): void {},
            MetadataCollection::from(),
        );
        $skipped = new TestDefinition(
            new TestId('tests/StopFixture.php', 'skipped'),
            static fn(array $values): mixed => throw new SkippedTestError('nope'),
            MetadataCollection::from(),
        );
        $passing = new TestDefinition(
            new TestId('tests/StopFixture.php', 'passes'),
            static function (array $values): void {
                Assert::assertTrue(true);
            },
            MetadataCollection::from(),
        );

        // A test that asserts nothing is risky; --stop-on-risky halts on it
        // where --stop-on-skipped, naming a different outcome, does not.
        $this->assertSame(1, $this->runWith([$risky, $passing], new RunnerOptions(stopOnRisky: true))->summary->total());
        $this->assertSame(2, $this->runWith([$risky, $passing], new RunnerOptions(stopOnSkipped: true))->summary->total());
        $this->assertSame(1, $this->runWith([$skipped, $passing], new RunnerOptions(stopOnSkipped: true))->summary->total());

        // --stop-on-defect still covers risky, as it always has.
        $this->assertSame(1, $this->runWith([$risky, $passing], new RunnerOptions(stopOnDefect: true))->summary->total());
    }

    public function testStopOnIssueReadsWhatTheTestEmittedNotHowItEnded(): void
    {
        // The point of --stop-on-deprecation: the test passes, and the run
        // still stops, because it is the deprecation that matters.
        $deprecating = new TestDefinition(
            new TestId('tests/StopFixture.php', 'deprecates'),
            static function (array $values): void {
                trigger_error('old api', E_USER_DEPRECATED);
                Assert::assertTrue(true);
            },
            MetadataCollection::from(),
        );
        $passing = new TestDefinition(
            new TestId('tests/StopFixture.php', 'passes'),
            static function (array $values): void {
                Assert::assertTrue(true);
            },
            MetadataCollection::from(),
        );

        $stopped = $this->runWith([$deprecating, $passing], new RunnerOptions(stopOnDeprecation: true));

        $this->assertSame(1, $stopped->summary->total());
        $this->assertSame(1, $stopped->summary->passed);

        // A notice switch does not halt on a deprecation.
        $this->assertSame(2, $this->runWith([$deprecating, $passing], new RunnerOptions(stopOnNotice: true))->summary->total());
    }

    public function testDisallowedOutputIsRiskyAndIsReportedRatherThanPrinted(): void
    {
        $printing = new TestDefinition(
            new TestId('tests/OutputFixture.php', 'prints'),
            static function (array $values): void {
                print 'chatty';
                Assert::assertTrue(true);
            },
            MetadataCollection::from(),
        );

        // Off by default: printing is a test's business, and the output
        // still reaches the terminal.
        ob_start();
        $finish  = $this->runWith([$printing], new RunnerOptions());
        $printed = (string) ob_get_clean();

        $this->assertSame(1, $finish->summary->passed);
        $this->assertStringContainsString('chatty', $printed);

        ob_start();
        $finish  = $this->runWith([$printing], new RunnerOptions(disallowTestOutput: true));
        $printed = (string) ob_get_clean();

        $this->assertSame(1, $finish->summary->risky);
        $this->assertStringNotContainsString('chatty', $printed, 'under the switch the output is reported, not printed');
    }

    public function testATestSlowerThanItsSizeAllowsIsRisky(): void
    {
        // The smallest budget the spec has is one second, so pinning
        // the over-budget path costs a real second of wall clock. It
        // buys the only assertion that matters here: that the runner
        // compares at all, and says what it measured.
        $slow = new TestDefinition(
            new TestId('tests/SlowFixture.php', 'crawls'),
            static function (array $values): void {
                usleep(1_050_000);
                Assert::assertTrue(true);
            },
            MetadataCollection::from(),
        );

        // Unbounded without the switch, and unbounded with the switch
        // when nothing declares a budget.
        $this->assertSame(1, $this->runWith([$slow], new RunnerOptions(defaultTimeLimit: 1))->summary->passed);
        $this->assertSame(1, $this->runWith([$slow], new RunnerOptions(enforceTimeLimit: true))->summary->passed);

        $finish = $this->runWith([$slow], new RunnerOptions(enforceTimeLimit: true, defaultTimeLimit: 1));

        $this->assertSame(1, $finish->summary->risky);
        $this->assertSame(0, $finish->summary->passed);
    }

    /**
     * @param list<TestDefinition> $definitions
     */
    private function runWith(array $definitions, RunnerOptions $options): RunFinished
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));

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

        (new TestRunner($emitter, $options))->run([new TestGroup('complete fixtures', $definitions)]);

        $lastEnvelope = array_last([...$log]);

        self::assertNotNull($lastEnvelope);

        $last = $lastEnvelope->event;

        self::assertInstanceOf(RunFinished::class, $last, 'The stream did not end with run:finish.');

        return $last;
    }
}
