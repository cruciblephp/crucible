<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use Closure;

use function count;

/**
 * Routes each mutant to an executor and collects the verdicts. This is
 * where warm-or-cold is decided per mutant: the warm executor is used
 * when it exists (the platform can fork) and can run this mutant (its
 * class is still unloaded); otherwise the mutant runs cold. A mutant no
 * test covers is answered without spawning anything.
 *
 * The routing is the whole point of the user's fail-soft requirement:
 * mutation testing degrades from warm to cold, and it never crashes for
 * want of an extension nor errors a mutant it could have run cold.
 */
final readonly class MutationRunner
{
    /**
     * @param ?MutantExecutor                          $warm          null where the platform cannot fork
     * @param Closure(Mutant): list<non-empty-string>  $coveringTests fastest-first ids per mutant (D-077)
     */
    public function __construct(
        private MutantExecutor $cold,
        private ?MutantExecutor $warm,
        private Closure $coveringTests,
    ) {}

    /**
     * $onVerdict is called as each verdict lands, never at the end.
     *
     * A real run is thousands of mutants and one process each, so it
     * lasts hours — ✓ measured 2026-09-17 on this repo: 4083 mutants
     * over 476 covered files. Collecting silently and reporting once
     * meant an interrupted run — Ctrl-C, a CI timeout — threw away
     * every verdict it had already earned and told the user nothing.
     * The caller streams from here instead, so the findings survive the
     * interruption that a run this long invites.
     *
     * @param list<Mutant>                                $mutants
     * @param ?Closure(MutationVerdict, int, int): void   $onVerdict verdict, completed, total
     */
    public function run(array $mutants, ?Closure $onVerdict = null): MutationReport
    {
        $verdicts = [];
        $total    = count($mutants);

        foreach ($mutants as $mutant) {
            $covering = ($this->coveringTests)($mutant);

            if ($mutant->equivalent !== null) {
                $verdict = MutationVerdict::equivalent($mutant, $mutant->equivalent);
            } elseif ($covering === []) {
                $verdict = MutationVerdict::notCovered($mutant);
            } else {
                $executor = $this->warm instanceof MutantExecutor && $this->warm->canRun($mutant) ? $this->warm : $this->cold;
                $verdict  = $executor->execute($mutant, $covering);
            }

            $verdicts[] = $verdict;

            if ($onVerdict instanceof Closure) {
                $onVerdict($verdict, count($verdicts), $total);
            }
        }

        return new MutationReport($verdicts);
    }
}
