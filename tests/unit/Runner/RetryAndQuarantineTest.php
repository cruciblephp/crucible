<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use Closure;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Quarantined;
use LucianoPereira\Crucible\Attributes\Retry;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\Backoff;
use LucianoPereira\Crucible\Runner\FlakinessLog;
use LucianoPereira\Crucible\Runner\RetryPolicy;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function hrtime;

#[CoversClass(TestRunner::class)]
#[CoversClass(FlakinessLog::class)]
final class RetryAndQuarantineTest extends TestCase
{
    /**
     * @param non-empty-string $name
     */
    private function definition(string $name, Closure $body, CrucibleAttribute ...$attributes): TestDefinition
    {
        return new TestDefinition(
            new TestId('tests/RetryFixture.php', $name),
            static fn(array $values): mixed => $body(),
            MetadataCollection::from(...$attributes),
        );
    }

    /**
     * Runs one definition and returns its test:finish event.
     */
    private function finished(TestDefinition $definition, RunnerOptions $options = new RunnerOptions()): TestFinished
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

        (new TestRunner($emitter, $options))->execute([new TestGroup('retry fixtures', [$definition])]);

        self::assertInstanceOf(TestFinished::class, $captured->last, 'No test:finish event was emitted.');

        return $captured->last;
    }

    /**
     * A body that fails until the given attempt is reached.
     */
    private function flakyBody(int $passesOnAttempt): Closure
    {
        $calls = 0;

        return static function () use (&$calls, $passesOnAttempt): void {
            $calls++;

            Assert::assertGreaterThanOrEqual($passesOnAttempt, $calls, 'not yet');
        };
    }

    public function testAPassOnRetryIsFlakyNotSilentlyGreen(): void
    {
        $event = $this->finished($this->definition('settles on attempt two', $this->flakyBody(2)), new RunnerOptions(retries: new RetryPolicy(2)));

        self::assertSame(Outcome::Passed, $event->outcome);
        self::assertSame(2, $event->attempt);
        self::assertTrue($event->flaky());
        self::assertStringContainsString('Flaky: passed on attempt 2 of 3', (string) $event->reason);
        self::assertStringContainsString('not yet', (string) $event->reason);

        // The earlier attempt's failure rides along for the JUnit
        // flaky markup (D-043).
        self::assertCount(1, $event->retried);
        self::assertStringContainsString('not yet', $event->retried[0]->message);
    }

    public function testBackoffDelaysShapeUp(): void
    {
        $fixed = new RetryPolicy(3, Backoff::Fixed, 0.2);

        self::assertSame(0.2, $fixed->delayFor(1));
        self::assertSame(0.2, $fixed->delayFor(3));

        $exponential = new RetryPolicy(4, Backoff::Exponential, 0.1, maxDelaySeconds: 0.3);

        self::assertSame(0.1, $exponential->delayFor(1));
        self::assertSame(0.2, $exponential->delayFor(2));
        self::assertSame(0.3, $exponential->delayFor(3)); // capped
        self::assertSame(0.3, $exponential->delayFor(4)); // still capped

        self::assertSame(0.0, RetryPolicy::none()->delayFor(1));

        $jittered = new RetryPolicy(1, Backoff::Fixed, 1.0, jitter: true);
        $delay    = $jittered->delayFor(1);

        self::assertGreaterThanOrEqual(0.5, $delay);
        self::assertLessThanOrEqual(1.0, $delay);
    }

    public function testTheCliCountOverrideKeepsTheConfiguredShape(): void
    {
        $shaped = (new RetryPolicy(2, Backoff::Exponential, 0.5, 4.0, jitter: true))->withCount(7);

        self::assertSame(7, $shaped->count);
        self::assertSame(Backoff::Exponential, $shaped->backoff);
        self::assertSame(0.5, $shaped->delaySeconds);
        self::assertSame(4.0, $shaped->maxDelaySeconds);
        self::assertTrue($shaped->jitter);
    }

    public function testTheRunnerActuallyPausesBetweenAttempts(): void
    {
        $started = hrtime(true);

        $this->finished(
            $this->definition('settles on attempt three', $this->flakyBody(3)),
            new RunnerOptions(retries: new RetryPolicy(2, Backoff::Fixed, 0.03)),
        );

        $elapsed = (hrtime(true) - $started) / 1e9;

        // Two retries at 30ms each: the pauses are real.
        self::assertGreaterThanOrEqual(0.06, $elapsed);
    }

    public function testARetryAttributeCarriesItsOwnBackoff(): void
    {
        $started = hrtime(true);

        $event = $this->finished(
            $this->definition('shaped by its attribute', $this->flakyBody(2), new Retry(1, Backoff::Fixed, 0.03)),
        );

        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame(Outcome::Passed, $event->outcome);
        // The 30ms backoff was waited: without it the retry takes well under
        // a millisecond. Not an exact floor — Windows wakes a sleep a fraction
        // of a millisecond early on its coarser timer.
        self::assertGreaterThan(0.025, $elapsed);
    }

    public function testTheRetryBudgetRunsOut(): void
    {
        $event = $this->finished($this->definition('never settles', $this->flakyBody(99)), new RunnerOptions(retries: new RetryPolicy(2)));

        self::assertSame(Outcome::Failed, $event->outcome);
        self::assertSame(3, $event->attempt);
        self::assertFalse($event->flaky());
    }

    public function testARetryAttributeOverridesTheRunWideBudget(): void
    {
        $event = $this->finished($this->definition('has its own budget', $this->flakyBody(2), new Retry(1)));

        self::assertSame(Outcome::Passed, $event->outcome);
        self::assertSame(2, $event->attempt);
    }

    public function testSkipsAreNeverRetried(): void
    {
        $event = $this->finished($this->definition('skips itself', static function (): void {
            throw new SkippedTestError('not today');
        }), new RunnerOptions(retries: new RetryPolicy(5)));

        self::assertSame(Outcome::Skipped, $event->outcome);
        self::assertSame(1, $event->attempt);
    }

    public function testTheQuarantinedAttributeMarksTheEvent(): void
    {
        $event = $this->finished($this->definition('known flake', static function (): void {
            Assert::fail('still broken');
        }, new Quarantined('issue #7')));

        self::assertSame(Outcome::Failed, $event->outcome);
        self::assertTrue($event->quarantined);
    }

    public function testTheConfigurationQuarantineListMatchesByFileAndName(): void
    {
        $options = new RunnerOptions(quarantine: ['tests/RetryFixture.php::listed flake']);

        $event = $this->finished($this->definition('listed flake', static function (): void {
            Assert::fail('still broken');
        }), $options);

        self::assertTrue($event->quarantined);

        $other = $this->finished($this->definition('unlisted test', static function (): void {
            Assert::assertTrue(true);
        }), $options);

        self::assertFalse($other->quarantined);
    }

    public function testTheFlakinessLogTalliesFromTheStream(): void
    {
        $log     = new FlakinessLog();
        $emitter = new Emitter(new SystemClock());

        $emitter->subscribe($log);

        $runner = new TestRunner($emitter, new RunnerOptions(retries: new RetryPolicy(1), quarantine: ['tests/RetryFixture.php::listed flake']));

        $runner->execute([new TestGroup('retry fixtures', [
            $this->definition('settles on attempt two', $this->flakyBody(2)),
            $this->definition('listed flake', static function (): void {
                Assert::fail('still broken');
            }),
            $this->definition('quarantined but healthy', static function (): void {
                Assert::assertTrue(true);
            }, new Quarantined()),
            $this->definition('honestly failing', static function (): void {
                Assert::fail('a real defect');
            }),
        ])]);

        self::assertSame(['tests/RetryFixture.php::settles on attempt two'], $log->flakyTests());
        self::assertSame(['tests/RetryFixture.php::listed flake'], $log->quarantinedFailures());
        self::assertSame(0, $log->quarantinedErrorCount());
        self::assertSame(['tests/RetryFixture.php::quarantined but healthy'], $log->quarantinedPasses());

        // The D-048 candidates: honest failures only — a flaky pass is
        // not a failure, and a quarantined one is already classified.
        self::assertSame(['tests/RetryFixture.php::honestly failing'], $log->failures());
    }
}
