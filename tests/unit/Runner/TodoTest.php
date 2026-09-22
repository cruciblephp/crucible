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
use LucianoPereira\Crucible\Attributes\RequiresPhp;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

/**
 * Todo as engine-visible metadata (D-045): the runner blocks a todo
 * before anything else runs, wip and done run their bodies like Pest
 * (D-074), and the blocking marker's label carries assignee/issue/note.
 * The dialect frontends only construct the attribute — the semantics
 * live here, once.
 */
#[CoversClass(TestRunner::class)]
#[CoversClass(Todo::class)]
final class TodoTest extends TestCase
{
    /**
     * @param non-empty-string $name
     */
    private function definition(string $name, Closure $body, CrucibleAttribute ...$attributes): TestDefinition
    {
        return new TestDefinition(
            new TestId('tests/TodoFixture.php', $name),
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

        (new TestRunner($emitter))->execute([new TestGroup('todo fixtures', [$definition])]);

        self::assertInstanceOf(TestFinished::class, $captured->last, 'No test:finish event was emitted.');

        return $captured->last;
    }

    public function testATodoNeverExecutesAndReportsIncomplete(): void
    {
        $executed = false;

        $event = $this->finished($this->definition(
            'planned',
            static function () use (&$executed): void {
                $executed = true;
            },
            new Todo(),
        ));

        $this->assertSame(Outcome::Incomplete, $event->outcome);
        $this->assertSame('TODO', $event->reason);
        $this->assertFalse($executed);
    }

    public function testAWipRunsItsBodyLikePest(): void
    {
        // The drift fix (D-074): Pest runs ->wip() bodies, so Crucible does
        // too. The marker stays as documentation but no longer blocks.
        $executed = false;

        $event = $this->finished($this->definition(
            'underway',
            static function () use (&$executed): void {
                $executed = true;
                self::assertTrue(true);
            },
            new Todo(TodoStatus::Wip, assignee: 'luciano', issue: 31, note: 'after G2'),
        ));

        $this->assertSame(Outcome::Passed, $event->outcome);
        $this->assertTrue($executed);
    }

    public function testADoneTodoRunsItsBodyNormally(): void
    {
        $executed = false;

        $event = $this->finished($this->definition(
            'landed',
            static function () use (&$executed): void {
                $executed = true;
                self::assertTrue(true);
            },
            new Todo(TodoStatus::Done, note: 'kept as documentation'),
        ));

        $this->assertSame(Outcome::Passed, $event->outcome);
        $this->assertTrue($executed);
    }

    public function testTheTodoMarkerWinsOverUnmetRequirements(): void
    {
        // A placeholder's requirements are irrelevant — there is no
        // body to have them. Incomplete, not skipped.
        $event = $this->finished($this->definition(
            'planned for a future runtime',
            static fn(): bool => true,
            new Todo(),
            new RequiresPhp('>= 99.0'),
        ));

        $this->assertSame(Outcome::Incomplete, $event->outcome);
    }

    /**
     * The phpunit-dialect surface, dogfooded twice over: this very
     * method is a todo — the metadata parser reads the attribute off
     * the method, the runner blocks it, and `crucible --todos` lists it —
     * and it declares that block as its expected outcome (D-073), so it
     * passes instead of swelling the incomplete tally. The runner
     * reconciles the todo's Incomplete against the expectation at the
     * single test:finish choke point; the body never runs.
     */
    #[Todo(assignee: 'luciano', note: 'the attribute surface, proven by being one')]
    #[ExpectedOutcome(Outcome::Incomplete)]
    public function testTheAttributeFormOnARealSuiteMethod(): never
    {
        self::fail('A todo test must never execute.');
    }
}
