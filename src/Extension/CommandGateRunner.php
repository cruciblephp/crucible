<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension;

use LucianoPereira\Crucible\Extension\Artifact\ExitStatus;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function hrtime;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function usleep;

/**
 * Runs a {@see CommandGate} and returns its {@see ExitStatus} — the
 * facts, not a verdict (Crucible tests those in {@see CheckRunner}).
 *
 * The child inherits the parent's standard streams, so its output
 * appears live on the console exactly as if it were run by hand — no
 * capture, no pipe to drain, no parse. argv is passed in list form, so
 * proc_open spawns without a shell: no quoting, no word-splitting, no
 * injection. A command that runs past its deadline is terminated and
 * reported as timed out — the run can never hang on a gate.
 */
final class CommandGateRunner
{
    private const int POLL_MICROSECONDS = 10_000;

    /**
     * The gate's own directory wins over the run's, when it pins one.
     */
    public function run(CommandGate $gate, WorkingDirectory $workingDirectory): ExitStatus
    {
        // Inherit stdin/stdout/stderr: the tool talks to the user
        // directly, Crucible reads only the exit status back.
        $descriptors = [STDIN, STDOUT, STDERR];
        $started     = hrtime(true);

        $process = proc_open(
            $gate->argv,
            $descriptors,
            $pipes,
            ($gate->workingDirectory ?? $workingDirectory)->path,
        );

        if (!is_resource($process)) {
            return new ExitStatus(ExitStatus::SPAWN_FAILED, $this->elapsed($started));
        }

        $deadline = $gate->timeout !== null ? $started + $gate->timeout * 1_000_000_000 : null;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                proc_close($process);

                return new ExitStatus($status['exitcode'], $this->elapsed($started));
            }

            if ($deadline !== null && hrtime(true) >= $deadline) {
                proc_terminate($process);
                proc_close($process);

                return new ExitStatus(ExitStatus::SPAWN_FAILED, $this->elapsed($started), timedOut: true);
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
