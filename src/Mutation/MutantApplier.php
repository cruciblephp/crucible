<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use RuntimeException;
use Throwable;

use function class_exists;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function ini_set;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const SIGKILL;
use const WNOHANG;

/**
 * Applies a mutant *warm* and returns its verdict. The parent process
 * boots once; each mutant runs in a `pcntl_fork`ed child that loads the
 * mutated bytes on first reference — through a prepended autoloader that,
 * while a mutant is active, serves it from a unique temp path (a fresh
 * path means opcache compiles it cleanly, so no invalidate is ever
 * needed). The child reports its verdict to a file and dies by SIGKILL,
 * skipping every shutdown handler the parent registered.
 *
 * The precondition is redeclare-safety: a class already loaded cannot be
 * re-mutated in a forked child (its declaration is inherited), so callers
 * check {@see canApply} first and cold-fallback when it is false. This is
 * the mechanism proven before the engine was built; the covering-test
 * ordering and catalog live above it.
 *
 * Warm application needs `pcntl` and `posix` — the fork and the signal.
 * They are absent on Windows and on minimal PHP builds, so a runner asks
 * {@see isSupported} first and cold-forks each mutant through `proc_open`
 * (the portable path the rest of Crucible already uses) when it is false.
 * The rest of the test runner never touches these extensions.
 */
final readonly class MutantApplier
{
    private const int POLL_MICROSECONDS = 1_000;

    /**
     * The memory a single mutant may use before it is cut off.
     *
     * ✓ Measured 2026-09-20, and it is why this exists: a mutant that
     * turns a bounded loop into an unbounded one grows ~130 MiB/s when
     * the loop writes to anything that accumulates — a FakeTerminal
     * collecting frames does exactly that. The wall-clock timeout is no
     * defence, because 10s of that is 1.3 GB and the cold path's 30s is
     * 3.9 GB, so the OOM killer reaches the whole process tree first. It
     * did: three runs died with SIGKILL around mutant 2444, and the
     * journal named the mutant each time.
     *
     * A limit turns that into an ordinary errored verdict, because a
     * child that dies without writing one is already read as "the mutant
     * crashed the run" rather than as an escape.
     *
     * Generous against a worker running a handful of covering tests —
     * the mutate parent itself peaks near 245 MiB — and still an order
     * of magnitude under the runaway.
     *
     * Shared with ColdMutantExecutor on purpose: one budget for a mutant,
     * whichever way it runs, or the two paths disagree about what is
     * survivable.
     */
    public const string MEMORY_LIMIT = '512M';

    private MutationAutoloader $autoloader;

    public function __construct()
    {
        // Dormant in the parent; each fork arms it for its own mutant. The
        // same loader serves the cold worker from the environment.
        $this->autoloader = new MutationAutoloader();
        $this->autoloader->register();
    }

    /**
     * Whether this platform can run the warm path at all: the fork and the
     * signal it terminates the child with. False on Windows and minimal
     * builds — the runner cold-falls-back through `proc_open` instead.
     */
    public static function isSupported(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill')
            && function_exists('posix_getpid');
    }

    /**
     * Whether the mutant's class is still unloaded in this process — the
     * warm precondition. Once a class is declared it cannot be replaced
     * without a redeclare fatal, so a false here means cold-fallback.
     */
    public function canApply(Mutant $mutant): bool
    {
        return !class_exists($mutant->class, false);
    }

    /**
     * Run the covering tests against the mutant in an isolated child.
     * $covering runs the ordered covering tests and returns the id of the
     * first that failed — the kill — or null when every one passed.
     *
     * @param callable(): ?non-empty-string $covering
     */
    public function run(Mutant $mutant, callable $covering, float $timeoutSeconds = 10.0): MutationVerdict
    {
        // A safety net, not the routing point: a runner checks isSupported()
        // up front and cold-falls-back, so this only fires on direct misuse
        // — and reports it plainly rather than fataling on an undefined fork.
        if (!self::isSupported()) {
            return MutationVerdict::errored($mutant, 'Warm mutation requires the pcntl and posix extensions.', 0.0);
        }

        $started    = microtime(true);
        $mutatedTmp = tempnam(sys_get_temp_dir(), 'crucible-mutant-');
        $resultTmp  = tempnam(sys_get_temp_dir(), 'crucible-verdict-');

        if ($mutatedTmp === false || $resultTmp === false) {
            return MutationVerdict::errored($mutant, 'Could not allocate a temp file for the mutant.', 0.0);
        }

        file_put_contents($mutatedTmp, $mutant->mutatedSource);

        // Remembered BEFORE the fork, so the child can prove it is not
        // the parent. ✓ Measured 2026-09-20: mutating the `$pid === 0`
        // branch below makes the parent take the child's path, and the
        // child's last act is SIGKILL on its own pid — uncatchable, so
        // the whole run dies with no verdict and no log line. Three
        // mutation runs ended exactly there.
        //
        // The guard is worth more than that one mutant: any fork bug
        // reaching child() in the parent takes the run with it, and this
        // is the one invariant that cannot be true by accident.
        $parent = posix_getpid();
        $pid    = pcntl_fork();

        if ($pid === -1) {
            @unlink($mutatedTmp);
            @unlink($resultTmp);

            return MutationVerdict::errored($mutant, 'Could not fork to isolate the mutant.', microtime(true) - $started);
        }

        if ($pid === 0) {
            $this->child($mutant, $mutatedTmp, $resultTmp, $covering, $parent);
        }

        // Guarded in BOTH directions, because either branch being wrong is
        // unrecoverable in a different way. A parent that reaches child()
        // must not SIGKILL itself — that is the guard inside child(). A
        // CHILD that skips child() must not reach here and resume the
        // parent's work: it would finish the parent's run, fork again from
        // inside the loop, and multiply. Its own pid is the proof.
        if (posix_getpid() !== $parent) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        $verdict = $this->await($pid, $mutant, $resultTmp, $timeoutSeconds, $started);

        @unlink($mutatedTmp);
        @unlink($resultTmp);

        return $verdict;
    }

    /**
     * The child: activate the mutant, run the covering tests, write the
     * verdict, and terminate hard.
     *
     * @param non-empty-string              $mutatedTmp
     * @param non-empty-string              $resultTmp
     * @param callable(): ?non-empty-string $covering
     * @param int                           $parentPid the pid that forked this one
     */
    private function child(Mutant $mutant, string $mutatedTmp, string $resultTmp, callable $covering, int $parentPid): never
    {
        // Only the child is capped. The parent is bookkeeping and must
        // outlive every mutant it runs.
        ini_set('memory_limit', self::MEMORY_LIMIT);

        $this->autoloader->activate($mutant->class, $mutatedTmp);

        try {
            $killedBy = $covering();
            $payload  = $killedBy === null
                ? ['outcome' => 'escaped']
                : ['outcome' => 'killed', 'killedBy' => $killedBy];
        } catch (Throwable $throwable) {
            $payload = ['outcome' => 'errored', 'reason' => $throwable->getMessage()];
        }

        file_put_contents($resultTmp, (string) json_encode($payload));

        // The one check that must hold before an uncatchable signal: this
        // is a child, not the process that forked it. Throwing satisfies
        // `never` and leaves a run that can still report; SIGKILL here in
        // the parent would end it with no verdict and no message.
        if (posix_getpid() === $parentPid) {
            throw new RuntimeException('child() ran in the process that forked it; refusing to signal the parent.');
        }

        // Hard exit: the verdict file is the only thing this child leaves
        // behind — no shutdown handler, no flushed output buffer.
        posix_kill(posix_getpid(), SIGKILL);

        exit(1); // unreachable once the signal lands; the `never` guarantee.
    }

    /**
     * Wait for the child, enforcing the timeout, then read its verdict.
     *
     * @param non-empty-string $resultTmp
     */
    private function await(int $pid, Mutant $mutant, string $resultTmp, float $timeoutSeconds, float $started): MutationVerdict
    {
        $deadline = $started + $timeoutSeconds;
        $status   = 0;

        while (true) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                break;
            }

            if (microtime(true) >= $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);

                return MutationVerdict::timedOut($mutant, microtime(true) - $started);
            }

            usleep(self::POLL_MICROSECONDS);
        }

        return $this->verdictFrom($mutant, $resultTmp, microtime(true) - $started);
    }

    /**
     * @param non-empty-string $resultTmp
     */
    private function verdictFrom(Mutant $mutant, string $resultTmp, float $duration): MutationVerdict
    {
        $raw     = file_get_contents($resultTmp);
        $decoded = $raw === false || $raw === '' ? null : json_decode($raw, true);

        if (!is_array($decoded) || !is_string($decoded['outcome'] ?? null)) {
            // No verdict written — the child died before finishing, i.e. a
            // fatal in the mutated code. That is an error, never a silent
            // escape.
            return MutationVerdict::errored($mutant, 'The mutant crashed the run before a verdict was recorded.', $duration);
        }

        $killedBy = is_string($decoded['killedBy'] ?? null) && $decoded['killedBy'] !== '' ? $decoded['killedBy'] : 'unknown';
        $reason   = is_string($decoded['reason'] ?? null) && $decoded['reason'] !== '' ? $decoded['reason'] : 'The mutant errored.';

        return match ($decoded['outcome']) {
            'killed'  => MutationVerdict::killed($mutant, $killedBy, $duration),
            'escaped' => MutationVerdict::escaped($mutant, $duration),
            default   => MutationVerdict::errored($mutant, $reason, $duration),
        };
    }
}
