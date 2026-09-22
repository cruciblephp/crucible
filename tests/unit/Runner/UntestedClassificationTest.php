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
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
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
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

/**
 * Untested classification (D-075): a skip caused by something outside
 * the test — an unmet #[Requires*] or an unmet dependency — is blocked,
 * so reporters lift it out of Problems into a quiet "Untested" section
 * rather than letting it read as a defect. A deliberate skip is not
 * blocked, and an #[ExpectedOutcome] that reconciles the skip away
 * drops the flag, because the test is no longer untested.
 */
#[CoversClass(TestRunner::class)]
final class UntestedClassificationTest extends TestCase
{
    /**
     * @param non-empty-string       $name
     * @param list<non-empty-string> $dependencies
     */
    private function definition(string $name, Closure $body, array $dependencies = [], CrucibleAttribute ...$attributes): TestDefinition
    {
        return new TestDefinition(
            new TestId('tests/UntestedFixture.php', $name),
            static fn(array $values): mixed => $body(),
            MetadataCollection::from(...$attributes),
            $dependencies,
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

        (new TestRunner($emitter))->execute([new TestGroup('untested fixtures', [$definition])]);

        self::assertInstanceOf(TestFinished::class, $captured->last, 'No test:finish event was emitted.');

        return $captured->last;
    }

    public function testAnUnmetExtensionRequirementIsBlockedAndReadsAsMissing(): void
    {
        $event = $this->finished($this->definition(
            'needs an absent extension',
            static function (): void {
                self::fail('The body must never run when the requirement is unmet.');
            },
            [],
            new RequiresPhpExtension('a_crucible_extension_that_is_not_loaded'),
        ));

        $this->assertSame(Outcome::Skipped, $event->outcome);
        $this->assertTrue($event->blocked);
        $this->assertNotNull($event->reason);
        $this->assertStringContainsString('is missing', $event->reason);
    }

    public function testAnUnmetDependencyIsBlocked(): void
    {
        $event = $this->finished($this->definition(
            'depends on a test that did not pass',
            static function (): void {
                self::fail('The body must never run when a dependency is unmet.');
            },
            ['a_dependency_that_never_ran'],
        ));

        $this->assertSame(Outcome::Skipped, $event->outcome);
        $this->assertTrue($event->blocked);
    }

    public function testADeliberateSkipIsNotBlocked(): void
    {
        $event = $this->finished($this->definition(
            'skips itself on purpose',
            static function (): void {
                throw new SkippedTestError('not ready yet');
            },
        ));

        $this->assertSame(Outcome::Skipped, $event->outcome);
        $this->assertFalse($event->blocked, 'A body-level skip is a deliberate gap, not untested.');
    }

    public function testAnExpectedSkipReconcilingToPassedDropsTheBlockedFlag(): void
    {
        // The requirement is unmet, so the raw outcome is a blocked
        // skip — but the test asserted it would skip, so it reconciles
        // to Passed and is no longer untested (the D-075 gate).
        $event = $this->finished($this->definition(
            'expects to be skipped when the extension is absent',
            static function (): void {
                self::fail('The body must never run when the requirement is unmet.');
            },
            [],
            new RequiresPhpExtension('a_crucible_extension_that_is_not_loaded'),
            new ExpectedOutcome(Outcome::Skipped),
        ));

        $this->assertSame(Outcome::Passed, $event->outcome);
        $this->assertFalse($event->blocked);
    }
}
