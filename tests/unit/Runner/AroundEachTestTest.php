<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use ArrayObject;
use DateTimeImmutable;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Dialect\PhpUnit\TestBuilder;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Extension\AroundEachTest;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\RetryPolicy;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;
use LucianoPereira\Crucible\TestFixtures\Framework\DemoTest;
use LucianoPereira\Crucible\TestFixtures\Framework\RefusingSetupTest;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_search;
use function array_values;
use function implode;
use function substr_count;

/**
 * The around-test role (D-172): every executed test is bracketed by the
 * configured extensions, in the process that runs it, including when
 * setUp() throws — the case dama/doctrine-test-bundle needs and a
 * `#[Before]`/`#[After]` trait cannot cover, because the after phase is
 * skipped when setUp() fails (F-04, ticket #2 item 4).
 */
#[CoversClass(TestRunner::class)]
#[CoversClass(AroundEachTest::class)]
final class AroundEachTestTest extends TestCase
{
    /**
     * A hook that records what it saw, in the DAMA shape: begin, the
     * test, then the rollback in a finally — so a throwing setUp is
     * still rolled back.
     *
     * @param ArrayObject<int, string> $log
     */
    private function hook(ArrayObject $log, string $mark = 'hook'): AroundEachTest
    {
        return new readonly class ($log, $mark) implements AroundEachTest {
            /**
             * @param ArrayObject<int, string> $log
             */
            public function __construct(
                private ArrayObject $log,
                private string $mark,
            ) {}

            public function around(TestId $id, callable $test): mixed
            {
                // The dataset suffix rides along, so every parameterized
                // row has its own bracket entry.
                $name = $id->name . ($id->dataset === null ? '' : '#' . $id->dataset);

                $this->log[] = $this->mark . ':begin:' . $name;

                try {
                    return $test();
                } finally {
                    $this->log[] = $this->mark . ':end:' . $name;
                }
            }
        };
    }

    /**
     * @param list<TestGroup>      $groups
     * @param list<AroundEachTest> $around
     *
     * @return list<Envelope>
     */
    private function run(array $groups, array $around = [], RunnerOptions $options = new RunnerOptions()): array
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));

        /** @var ArrayObject<int, Envelope> $events */
        $events = new ArrayObject();

        $emitter->subscribe(new readonly class ($events) implements Listener {
            /**
             * @param ArrayObject<int, Envelope> $events
             */
            public function __construct(
                private ArrayObject $events,
            ) {}

            public function handle(Envelope $envelope): void
            {
                $this->events->append($envelope);
            }
        });

        (new TestRunner($emitter, $options, aroundEachTest: $around))->execute($groups);

        return [...$events];
    }

    /**
     * The single test:finish event a one-test run produced.
     *
     * @param list<Envelope> $events
     */
    private function finished(array $events): TestFinished
    {
        $finished = array_values(array_map(
            static fn(Envelope $envelope) => $envelope->event,
            array_filter($events, static fn(Envelope $envelope): bool => $envelope->event instanceof TestFinished),
        ));

        self::assertCount(1, $finished, 'The run did not report exactly one test outcome.');

        $event = $finished[0];

        self::assertInstanceOf(TestFinished::class, $event);

        return $event;
    }

    public function testTheHookBracketsEveryExecutedTest(): void
    {
        require_once __DIR__ . '/../../_fixtures/framework/DemoTest.php';

        DemoTest::$lifecycle = [];

        /** @var ArrayObject<int, string> $log */
        $log    = new ArrayObject();
        $groups = [(new TestBuilder())->build(DemoTest::class, 'tests/_fixtures/framework/DemoTest.php')];

        $events  = $this->run($groups, [$this->hook($log)]);
        $entries = [...$log];
        $joined  = implode("\n", $entries) . "\n";

        // Every test the run started is a test the hook bracketed —
        // skips and incompletes throw inside the body, so their
        // brackets closed too. Nothing it did not begin appears, and
        // nothing it began is missing its end or closed it early.
        $started = [];

        foreach ($events as $envelope) {
            if ($envelope->event instanceof TestStarted) {
                $started[] = $envelope->event->test->name
                    . ($envelope->event->test->dataset === null ? '' : '#' . $envelope->event->test->dataset);
            }
        }

        self::assertNotSame([], $started, 'The fixture ran no tests.');

        foreach ($started as $name) {
            self::assertSame(
                1,
                substr_count($joined, 'hook:begin:' . $name . "\n"),
                'The hook did not bracket ' . $name . ' exactly once.',
            );
            self::assertSame(
                1,
                substr_count($joined, 'hook:end:' . $name . "\n"),
                'The hook never closed its bracket for ' . $name . '.',
            );

            $beginAt = array_search('hook:begin:' . $name, $entries, true);
            $endAt   = array_search('hook:end:' . $name, $entries, true);

            self::assertIsInt($beginAt, 'No begin entry for ' . $name . '.');
            self::assertIsInt($endAt, 'No end entry for ' . $name . '.');
            self::assertLessThan($endAt, $beginAt, 'The bracket for ' . $name . ' closed before it opened.');
        }
    }

    public function testTheHookStillRunsWhenSetUpThrows(): void
    {
        require_once __DIR__ . '/../../_fixtures/framework/RefusingSetupTest.php';

        RefusingSetupTest::$lifecycle = [];

        /** @var ArrayObject<int, string> $log */
        $log    = new ArrayObject();
        $groups = [(new TestBuilder())->build(RefusingSetupTest::class, 'tests/_fixtures/framework/RefusingSetupTest.php')];

        $event = $this->finished($this->run($groups, [$this->hook($log)]));

        // The one thing the trait workaround could not promise (ticket
        // #2 item 4): setUp() threw, Crucible skipped the after phase —
        // and the hook's finally still ran its cleanup.
        self::assertContains('hook:begin:testNeverReachesItsBody', [...$log]);
        self::assertContains('hook:end:testNeverReachesItsBody', [...$log]);

        self::assertSame(Outcome::Errored, $event->outcome, 'A throwing setUp is an error.');
        self::assertNotNull($event->failure);
        self::assertStringContainsString('setUp refused to start', $event->failure->message);
        self::assertSame([], RefusingSetupTest::$lifecycle, 'The body and tearDown never ran.');
    }

    public function testFirstRegisteredRunsOutermost(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log    = new ArrayObject();
        $groups = [new TestGroup('nested', [
            new TestDefinition(
                new TestId('tests/nested.php', 'nested'),
                static function () use ($log): void {
                    $log[] = 'body';

                    Assert::assertTrue(true);
                },
                MetadataCollection::from(),
            ),
        ])];

        $events = $this->run($groups, [$this->hook($log, 'outer'), $this->hook($log, 'inner')]);

        self::assertSame(
            ['outer:begin:nested', 'inner:begin:nested', 'body', 'inner:end:nested', 'outer:end:nested'],
            [...$log],
            'Registration order is nesting order: first registered, outermost.',
        );

        self::assertSame(Outcome::Passed, $this->finished($events)->outcome);
    }

    public function testAHookThatThrowsBeforeTheTestErrorsIt(): void
    {
        /** @var ArrayObject<int, string> $reached */
        $reached  = new ArrayObject();
        $refusing = new readonly class implements AroundEachTest {
            public function around(TestId $id, callable $test): mixed
            {
                throw new RuntimeException('the connection refused to begin a transaction');
            }
        };

        $groups = [new TestGroup('refusing', [
            new TestDefinition(
                new TestId('tests/refusing.php', 'never started'),
                static function () use ($reached): void {
                    $reached[] = 'body';
                },
                MetadataCollection::from(),
            ),
        ])];

        $event = $this->finished($this->run($groups, [$refusing]));

        self::assertSame([], [...$reached], 'The test body must not run after a refusing hook.');
        self::assertSame(Outcome::Errored, $event->outcome, "The hook's throw is the test's outcome, like a failing setUp().");
        self::assertNotNull($event->failure);
        self::assertStringContainsString('refused to begin a transaction', $event->failure->message);
    }

    public function testARetriedTestReEntersTheHook(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log     = new ArrayObject();
        $attempt = 0;

        $groups = [new TestGroup('flaky', [
            new TestDefinition(
                new TestId('tests/flaky.php', 'flaky'),
                static function () use (&$attempt): void {
                    $attempt++;

                    throw new RuntimeException('boom on attempt ' . $attempt);
                },
                MetadataCollection::from(),
            ),
        ])];

        $events = $this->run($groups, [$this->hook($log)], new RunnerOptions(retries: new RetryPolicy(1)));
        $joined = implode("\n", [...$log]) . "\n";

        // A fresh bracket per attempt: a retried test re-opens its
        // transaction rather than reusing the dead one.
        self::assertSame(2, substr_count($joined, 'hook:begin:flaky' . "\n"), 'The hook opened one bracket per retry attempt.');
        self::assertSame(2, substr_count($joined, 'hook:end:flaky' . "\n"), 'The hook closed one bracket per retry attempt.');
        self::assertSame(Outcome::Errored, $this->finished($events)->outcome, 'A RuntimeException is an error, and the error survived both attempts.');
    }

    public function testATestThatNeverExecutesIsNotBracketed(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();

        $groups = [new TestGroup('planned', [
            new TestDefinition(
                new TestId('tests/planned.php', 'later'),
                static fn(): mixed => throw new RuntimeException('a todo never executes'),
                MetadataCollection::from(new Todo()),
            ),
        ])];

        $events = $this->run($groups, [$this->hook($log)]);

        self::assertSame([], [...$log], 'A todo-blocked test never reaches its hook: there is nothing to wrap.');
        self::assertSame(Outcome::Incomplete, $this->finished($events)->outcome);
    }

    public function testTheValueTheHookPassesThroughFeedsDependencies(): void
    {
        /** @var ArrayObject<int, list<mixed>> $seen */
        $seen     = new ArrayObject();
        $producer = new TestDefinition(
            new TestId('tests/values.php', 'produces'),
            static function (): mixed {
                Assert::assertTrue(true);

                return 42;
            },
            MetadataCollection::from(),
        );
        $consumer = new TestDefinition(
            new TestId('tests/values.php', 'consumes'),
            static function (array $values) use ($seen): void {
                $seen[] = $values;

                Assert::assertTrue(true);
            },
            MetadataCollection::from(),
            ['produces'],
        );

        /** @var ArrayObject<int, string> $unused */
        $unused = new ArrayObject();

        $events = $this->run(
            [new TestGroup('values', [$producer, $consumer])],
            [$this->hook($unused)],
        );

        self::assertSame([[42]], [...$seen], 'The hook must return what the test returned, or #[Depends] injects what the hook wants instead.');

        // Both tests passed: the producer fed the consumer through the
        // hook's transparent return.
        $outcomes = [];

        foreach ($events as $envelope) {
            if ($envelope->event instanceof TestFinished) {
                $outcomes[] = $envelope->event->outcome;
            }
        }

        self::assertSame([Outcome::Passed, Outcome::Passed], $outcomes);
    }

    public function testNoHookRunsWhenNoneIsConfigured(): void
    {
        // The control for every case above: with no around-test
        // extensions the lifecycle runs as it did before the role.
        require_once __DIR__ . '/../../_fixtures/framework/DemoTest.php';

        DemoTest::$lifecycle = [];

        $groups = [(new TestBuilder())->build(DemoTest::class, 'tests/_fixtures/framework/DemoTest.php')];

        $this->run($groups);

        self::assertContains('setUp', DemoTest::$lifecycle, 'The lifecycle ran unbracketed, as before.');
        self::assertContains('testPasses', DemoTest::$lifecycle);
    }
}
