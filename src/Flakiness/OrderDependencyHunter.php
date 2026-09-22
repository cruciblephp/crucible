<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Flakiness;

use Closure;
use LucianoPereira\Crucible\Test\TestId;

use function array_keys;
use function count;
use function sprintf;

/**
 * The iDFlakies protocol, deterministic edition (growth G4): run the
 * suite in declaration order, then in N seeded random orders; every
 * new failure is a suspect. A suspect that fails again when its
 * exact seed is replayed but passes alone is ORDER-DEPENDENT — the
 * seed is the reproduction. One that passes even on replay is
 * non-deterministic (no order to blame), and one that fails alone
 * is simply broken.
 *
 * Pure protocol: how a "run" happens is injected — the CLI hands in
 * a child-process runner, tests hand in a fake.
 */
final readonly class OrderDependencyHunter
{
    /**
     * @param Closure(list<non-empty-string>): array<string, string> $run  extra CLI arguments → test id => outcome value
     * @param Closure(non-empty-string): void                        $note progress line
     */
    public function __construct(
        private Closure $run,
        private Closure $note,
    ) {}

    /**
     * @param positive-int $rounds
     * @param int<0, max>  $baseSeed
     */
    public function hunt(int $rounds, int $baseSeed): OrderDependencyReport
    {
        $baseline = ($this->run)([]);

        $baselineFailures = $this->failuresIn($baseline);

        ($this->note)(sprintf(
            '[flakes] baseline: %d test(s), %d failure(s).',
            count($baseline),
            count($baselineFailures),
        ));

        /** @var array<non-empty-string, int> $suspects id → first exposing seed */
        $suspects = [];

        /** @var array<int, array<string, string>> $bySeed round outcomes, for replay comparison */
        $bySeed = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $seed = $baseSeed + $round;

            $outcomes      = ($this->run)(['--order-by', 'random', '--random-order-seed', (string) $seed]);
            $bySeed[$seed] = $outcomes;

            $fresh = 0;

            foreach (array_keys($this->failuresIn($outcomes)) as $id) {
                if (isset($baselineFailures[$id]) || isset($suspects[$id])) {
                    continue;
                }

                $suspects[$id] = $seed;
                $fresh++;
            }

            ($this->note)(sprintf('[flakes] round %d/%d (seed %d): %d new suspect(s).', $round, $rounds, $seed, $fresh));
        }

        $orderDependent   = [];
        $nonDeterministic = [];
        $brokenAlone      = [];

        foreach ($suspects as $id => $seed) {
            // Fails alone → not flaky, just broken.
            if ($this->failsInIsolation($id)) {
                $brokenAlone[] = $id;

                continue;
            }

            // The seeded order is fully deterministic, so replaying it
            // separates order-dependence from noise: reproduce = the
            // order is to blame, and the seed is the repro recipe.
            $replayed = ($this->run)(['--order-by', 'random', '--random-order-seed', (string) $seed]);

            if (($replayed[$id] ?? '') === 'fail' || ($replayed[$id] ?? '') === 'error') {
                $orderDependent[$id] = $seed;
            } else {
                $nonDeterministic[] = $id;
            }
        }

        return new OrderDependencyReport(
            $orderDependent,
            $nonDeterministic,
            $brokenAlone,
            array_keys($baselineFailures),
            $rounds,
        );
    }

    /**
     * @param array<string, string> $outcomes
     *
     * @return array<non-empty-string, true>
     */
    private function failuresIn(array $outcomes): array
    {
        $failures = [];

        foreach ($outcomes as $id => $outcome) {
            if ($id !== '' && ($outcome === 'fail' || $outcome === 'error')) {
                $failures[$id] = true;
            }
        }

        return $failures;
    }

    /**
     * @param non-empty-string $id
     */
    private function failsInIsolation(string $id): bool
    {
        $parsed = TestId::fromString($id);
        $name   = $parsed instanceof TestId ? $parsed->name : $id;

        $alone   = ($this->run)(['--filter', $name]);
        $outcome = $alone[$id] ?? '';

        return $outcome === 'fail' || $outcome === 'error';
    }
}
