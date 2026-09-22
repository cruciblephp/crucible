<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use LucianoPereira\Crucible\Test\TestId;

use function array_map;

final readonly class TestFinished implements Event
{
    /**
     * @param float             $duration    wall-clock seconds, from monotonic time
     * @param positive-int      $attempt     1 on the first run; >1 on retries
     * @param ?non-empty-string $reason      e.g. the skip message for Outcome::Skipped
     * @param list<Issue>       $issues      deprecations/notices/warnings the test triggered
     * @param bool              $quarantined the test is known-flaky (G4); its failure does not affect the exit code
     * @param ?array{key: non-empty-string, choices: list<int>} $property a falsified property's replay data (D-040)
     * @param list<Failure>     $retried     the failures of earlier attempts, when retries ran (D-043)
     * @param ?array{created: int, updated: int} $snapshots what an --update-snapshots attempt recorded (D-066)
     * @param list<non-empty-string> $propertyClean property keys whose stored counterexamples all replayed clean on a passing first attempt (D-071)
     * @param list<non-empty-string> $snapshotKeys  snapshot keys this test visited (D-071)
     * @param int                    $assertions    satisfied assertions this test performed, for the JUnit report's per-test count
     * @param bool                   $blocked       a Skipped test that could not run for a reason outside itself — an unmet #[Requires*] or an unmet dependency — so it went untested rather than being a defect; reporters lift it out of Problems (D-075)
     */
    public function __construct(
        public TestId $test,
        public Outcome $outcome,
        public float $duration,
        public ?Failure $failure = null,
        public int $attempt = 1,
        public ?string $reason = null,
        public array $issues = [],
        public bool $quarantined = false,
        public ?array $property = null,
        public array $retried = [],
        public ?array $snapshots = null,
        public array $propertyClean = [],
        public array $snapshotKeys = [],
        public bool $blocked = false,
        public int $assertions = 0,
    ) {}

    public function flaky(): bool
    {
        return $this->attempt > 1 && $this->outcome === Outcome::Passed;
    }

    public function name(): EventName
    {
        return EventName::TestFinished;
    }

    public function payload(): array
    {
        $payload = [
            'id'       => $this->test->toString(),
            'outcome'  => $this->outcome->value,
            'duration' => $this->duration,
        ];

        if ($this->attempt > 1) {
            $payload['attempt'] = $this->attempt;
        }

        if ($this->quarantined) {
            $payload['quarantined'] = true;
        }

        if ($this->blocked) {
            $payload['blocked'] = true;
        }

        if ($this->assertions > 0) {
            $payload['assertions'] = $this->assertions;
        }

        if ($this->property !== null) {
            $payload['property'] = $this->property;
        }

        if ($this->propertyClean !== []) {
            $payload['propertyClean'] = $this->propertyClean;
        }

        if ($this->snapshots !== null) {
            $payload['snapshots'] = $this->snapshots;
        }

        if ($this->snapshotKeys !== []) {
            $payload['snapshotKeys'] = $this->snapshotKeys;
        }

        if ($this->retried !== []) {
            $payload['retried'] = array_map(
                static fn(Failure $failure): array => $failure->toArray(),
                $this->retried,
            );
        }

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        if ($this->failure instanceof \LucianoPereira\Crucible\Event\Failure) {
            $payload['error'] = $this->failure->toArray();
        }

        if ($this->issues !== []) {
            $payload['issues'] = array_map(
                static fn(Issue $issue): array => $issue->toArray(),
                $this->issues,
            );
        }

        return $payload;
    }
}
