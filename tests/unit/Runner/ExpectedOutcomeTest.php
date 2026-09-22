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
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\ExpectedOutcome;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\IncompleteTestError;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

/**
 * The expected-outcome assertion (D-073): a test declares the terminal
 * outcome it expects, and the runner reconciles the actual outcome at
 * the single test:finish choke point — a match is the pass, a mismatch
 * the failure. The two non-result outcomes (skip, incomplete) that
 * otherwise only swell a separate tally become assertable, so the
 * demonstration fixtures for them can go green. The construct is
 * scoped to exactly those two: Failed and a throw are already served
 * by fails()/throws(), Passed is the default, and Risky/Errored are
 * defects, not declared outcomes.
 */
#[CoversClass(TestRunner::class)]
#[CoversClass(ExpectedOutcome::class)]
final class ExpectedOutcomeTest extends TestCase
{
    /**
     * @param non-empty-string $name
     */
    private function definition(string $name, Closure $body, CrucibleAttribute ...$attributes): TestDefinition
    {
        return new TestDefinition(
            new TestId('tests/ExpectedOutcomeFixture.php', $name),
            static fn(array $values): mixed => $body(),
            MetadataCollection::from(...$attributes),
        );
    }

    private function finished(TestDefinition $definition): TestFinished
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

        (new TestRunner($emitter))->execute([new TestGroup('expected-outcome fixtures', [$definition])]);

        self::assertInstanceOf(TestFinished::class, $captured->last, 'No test:finish event was emitted.');

        return $captured->last;
    }

    public function testAnExpectedSkipThatSkipsPasses(): void
    {
        $event = $this->finished($this->definition(
            'skips as promised',
            static function (): void {
                throw new SkippedTestError('not on this platform');
            },
            new ExpectedOutcome(Outcome::Skipped),
        ));

        $this->assertSame(Outcome::Passed, $event->outcome);
        $this->assertSame('Expected outcome met: skip.', $event->reason);
        $this->assertNull($event->failure);
    }

    public function testAnExpectedSkipThatDoesNotSkipFails(): void
    {
        $event = $this->finished($this->definition(
            'refuses to skip',
            static function (): void {
                self::assertTrue(true);
            },
            new ExpectedOutcome(Outcome::Skipped),
        ));

        $this->assertSame(Outcome::Failed, $event->outcome);
        $this->assertInstanceOf(Failure::class, $event->failure);
        $this->assertSame('Expected the test to skip, but it passed.', $event->failure->message);
    }

    public function testTheDeclaredReasonFragmentMustAppearInTheSkipReason(): void
    {
        $event = $this->finished($this->definition(
            'skips for another reason',
            static function (): void {
                throw new SkippedTestError('the database is offline');
            },
            new ExpectedOutcome(Outcome::Skipped, 'xdebug'),
        ));

        $this->assertSame(Outcome::Failed, $event->outcome);
        $this->assertInstanceOf(Failure::class, $event->failure);
        $this->assertStringContainsString('xdebug', $event->failure->message);
        $this->assertStringContainsString('the database is offline', $event->failure->message);
    }

    public function testAMatchingReasonFragmentPasses(): void
    {
        $event = $this->finished($this->definition(
            'skips for the stated reason',
            static function (): void {
                throw new SkippedTestError('xdebug is not loaded');
            },
            new ExpectedOutcome(Outcome::Skipped, 'xdebug'),
        ));

        $this->assertSame(Outcome::Passed, $event->outcome);
    }

    public function testAnExpectedIncompleteThatIsIncompletePasses(): void
    {
        $event = $this->finished($this->definition(
            'incomplete as promised',
            static function (): void {
                throw new IncompleteTestError('the body is not written yet');
            },
            new ExpectedOutcome(Outcome::Incomplete),
        ));

        $this->assertSame(Outcome::Passed, $event->outcome);
        $this->assertSame('Expected outcome met: incomplete.', $event->reason);
    }

    public function testAnExpectedIncompleteThatSkipsFailsNamingBothOutcomes(): void
    {
        $event = $this->finished($this->definition(
            'skips when incomplete was expected',
            static function (): void {
                throw new SkippedTestError('unavailable');
            },
            new ExpectedOutcome(Outcome::Incomplete),
        ));

        $this->assertSame(Outcome::Failed, $event->outcome);
        $this->assertInstanceOf(Failure::class, $event->failure);
        $this->assertSame('Expected the test to be incomplete, but it was skipped.', $event->failure->message);
    }

    public function testOnlySkipAndIncompleteCanBeExpected(): void
    {
        $this->expectException(ConfigurationException::class);

        new ExpectedOutcome(Outcome::Passed);
    }
}
