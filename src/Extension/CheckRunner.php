<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension;

use LucianoPereira\Crucible\Event\CheckFinished;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Extension\Artifact\Artifact;
use LucianoPereira\Crucible\Extension\Artifact\Claim;
use LucianoPereira\Crucible\Extension\Artifact\ExitStatus;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use Throwable;

use function hrtime;
use function sprintf;
use function var_export;

/**
 * Runs the configured checks and, for each, tests the artifact it
 * presents — this is where the verdict is always Crucible's. A check
 * (a shell {@see CommandGate} or a PHP-native {@see Check} extension)
 * hands over facts as an {@see Artifact}; Crucible applies the one
 * requirement per kind — `code === 0` for an {@see ExitStatus},
 * `actual === expected` for a {@see Claim} — and emits a run-scoped
 * {@see CheckFinished} with the outcome and a named reason. Called from
 * the runner's after-tests hook, so its events land inside the run
 * bracket, before `run:finish`.
 */
final readonly class CheckRunner
{
    public function __construct(
        private Emitter $emitter,
        private CommandGateRunner $runner = new CommandGateRunner(),
    ) {}

    /**
     * @param list<CommandGate> $gates
     * @param list<Check>       $checks
     */
    public function run(array $gates, array $checks, WorkingDirectory $workingDirectory): void
    {
        foreach ($gates as $gate) {
            $status             = $this->runner->run($gate, $workingDirectory);
            [$outcome, $reason] = $this->judgeStatus($status, $gate);

            $this->emitter->emit(new CheckFinished($gate->label, $outcome, $status->duration, $reason));
        }

        foreach ($checks as $check) {
            $this->emitter->emit($this->inspect($check, $workingDirectory));
        }
    }

    private function inspect(Check $check, WorkingDirectory $workingDirectory): CheckFinished
    {
        $started = hrtime(true);

        // A check that throws never reached a verdict — it errored, and
        // the run learns why rather than crashing.
        try {
            $artifact = $check->inspect($workingDirectory);
        } catch (Throwable $throwable) {
            return new CheckFinished(
                $check->label(),
                Outcome::Errored,
                (hrtime(true) - $started) / 1e9,
                sprintf('Check "%s" errored: %s', $check->label(), $throwable->getMessage()),
            );
        }

        $duration           = (hrtime(true) - $started) / 1e9;
        [$outcome, $reason] = $this->judge($check->label(), $artifact);

        return new CheckFinished($check->label(), $outcome, $duration, $reason);
    }

    /**
     * The one requirement for a shell command: a gate passes only on
     * exit zero. A command Crucible had to kill, or could not start,
     * errored — it never ran to a real verdict.
     *
     * @return array{0: Outcome, 1: ?non-empty-string}
     */
    private function judgeStatus(ExitStatus $status, CommandGate $gate): array
    {
        if ($status->timedOut) {
            return [Outcome::Errored, sprintf('Check "%s" timed out after %ds.', $gate->label, (int) $gate->timeout)];
        }

        if ($status->code === ExitStatus::SPAWN_FAILED) {
            return [Outcome::Errored, sprintf('Check "%s" could not be started (command not found or not executable).', $gate->label)];
        }

        if ($status->code === 0) {
            return [Outcome::Passed, null];
        }

        return [Outcome::Failed, sprintf('Check "%s" failed (exit %d).', $gate->label, $status->code)];
    }

    /**
     * The verdict on an artifact a Check presented, by kind.
     *
     * @param non-empty-string $label
     *
     * @return array{0: Outcome, 1: ?non-empty-string}
     */
    private function judge(string $label, Artifact $artifact): array
    {
        return match (true) {
            $artifact instanceof Claim      => $this->judgeClaim($label, $artifact),
            $artifact instanceof ExitStatus => $artifact->code === 0
                ? [Outcome::Passed, null]
                : [Outcome::Failed, sprintf('Check "%s" failed (exit %d).', $label, $artifact->code)],
        };
    }

    /**
     * A claim passes when its operands are identical; otherwise it
     * fails, naming both operands and the plugin's own detail.
     *
     * @param non-empty-string $label
     *
     * @return array{0: Outcome, 1: ?non-empty-string}
     */
    private function judgeClaim(string $label, Claim $claim): array
    {
        if ($claim->actual === $claim->expected) {
            return [Outcome::Passed, null];
        }

        return [Outcome::Failed, sprintf(
            'Check "%s" failed: expected %s, got %s.%s',
            $label,
            var_export($claim->expected, true),
            var_export($claim->actual, true),
            $claim->detail !== null ? "\n" . $claim->detail : '',
        )];
    }
}
