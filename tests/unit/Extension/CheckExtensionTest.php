<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Extension;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Event\CheckFinished;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Extension\Artifact\Artifact;
use LucianoPereira\Crucible\Extension\Artifact\Claim;
use LucianoPereira\Crucible\Extension\Check;
use LucianoPereira\Crucible\Extension\CheckRunner;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;

/**
 * The check role of the extension surface (D-078): a PHP-native plugin
 * inspects the project and presents a {@see Claim} of facts; Crucible tests
 * `actual === expected`, owns the outcome and the message, and a failing
 * or errored check votes the exit code — the artifact model, in-process.
 */
#[CoversClass(CheckRunner::class)]
#[CoversClass(Claim::class)]
final class CheckExtensionTest extends TestCase
{
    /**
     * @return list<CheckFinished>
     */
    private function run(Check $check): array
    {
        $captured = new class implements Listener {
            /** @var list<CheckFinished> */
            public array $events = [];

            public function handle(Envelope $envelope): void
            {
                if ($envelope->event instanceof CheckFinished) {
                    $this->events[] = $envelope->event;
                }
            }
        };

        $emitter = new Emitter(new SystemClock());
        $emitter->subscribe($captured);

        (new CheckRunner($emitter))->run([], [$check], new WorkingDirectory('/tmp'));

        return $captured->events;
    }

    public function testASatisfiedClaimPasses(): void
    {
        $events = $this->run(new class implements Check {
            public function label(): string
            {
                return 'Duplication';
            }

            public function inspect(WorkingDirectory $workingDirectory): Artifact
            {
                return new Claim(actual: 0, expected: 0);
            }
        });

        $this->assertCount(1, $events);
        $this->assertSame('Duplication', $events[0]->name);
        $this->assertSame(Outcome::Passed, $events[0]->outcome);
        $this->assertNull($events[0]->reason);
    }

    public function testAnUnsatisfiedClaimFailsNamingBothOperandsAndTheDetail(): void
    {
        $events = $this->run(new class implements Check {
            public function label(): string
            {
                return 'Duplication';
            }

            public function inspect(WorkingDirectory $workingDirectory): Artifact
            {
                return new Claim(actual: 3, expected: 0, detail: '3 clones found: src/A.php:10 ↔ src/B.php:20');
            }
        });

        $this->assertSame(Outcome::Failed, $events[0]->outcome);
        $this->assertNotNull($events[0]->reason);
        $this->assertStringContainsString('expected 0, got 3', $events[0]->reason);
        $this->assertStringContainsString('3 clones found', $events[0]->reason);
    }

    public function testAThrowingCheckErrorsInsteadOfCrashing(): void
    {
        $events = $this->run(new class implements Check {
            public function label(): string
            {
                return 'Broken';
            }

            public function inspect(WorkingDirectory $workingDirectory): Artifact
            {
                throw new RuntimeException('scan blew up');
            }
        });

        $this->assertSame(Outcome::Errored, $events[0]->outcome);
        $this->assertStringContainsString('scan blew up', (string) $events[0]->reason);
    }
}
