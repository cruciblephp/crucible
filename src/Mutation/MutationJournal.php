<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use function count;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function fopen;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function md5;
use function mkdir;

use const JSON_THROW_ON_ERROR;

/**
 * Verdicts on disk, appended as they land, so a killed run resumes.
 *
 * ✓ Measured 2026-09-18/20: a full run here is 4095 mutants and one
 * process each, and two real attempts died at 2443 — a hibernate cycle,
 * an OOM killer, a closed laptop. Both threw away every verdict. Nothing
 * about a run this long makes finishing in one sitting the normal case,
 * so not resuming is the defect rather than the interruption.
 *
 * One JSON object per line, appended and flushed per verdict: a file
 * rewritten whole would lose the run at exactly the moment it is being
 * killed, which is the moment that matters.
 *
 * **The key is a hash of the MUTATED SOURCE, not file:line:mutator.**
 * Two mutants share a line and a mutator often — ✓ `Snapshots.php:111
 * logical` appears twice in one run — so that triple is not an identity.
 * The content hash also invalidates itself for free: edit the source and
 * every mutant of it hashes differently, so its verdicts are re-run
 * rather than resurrected against code that has moved.
 */
final class MutationJournal
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    /** @param non-empty-string $path */
    private function __construct(private readonly string $path) {}

    /** @param non-empty-string $path */
    public static function load(string $path): self
    {
        $journal = new self($path);

        if (!is_file($path)) {
            return $journal;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return $journal;
        }

        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }

            // A half-written last line is what an interrupted append
            // leaves behind; it is skipped rather than fatal, because
            // refusing to resume over one torn record would throw away
            // the thousands before it.
            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                continue;
            }

            $hash = $entry['h'] ?? null;

            if (!is_string($hash) || $hash === '') {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $journal->entries[$hash] = $entry;
        }

        return $journal;
    }

    public static function keyOf(Mutant $mutant): string
    {
        return md5($mutant->mutatedSource);
    }

    /**
     * Decided already, AND decided by the same tests.
     *
     * ⚠ The content hash alone is not enough, and shipping it that way
     * cost a whole run's worth of trust. A verdict is a statement about
     * a mutant AND the tests that judged it: 2026-09-20, SvgDocument's
     * tests were rewritten so 79 of its 95 escapes died, the source was
     * never touched, so every mutant hashed the same and the stale
     * "escaped" verdicts were resumed over the top of the fix. The score
     * came back unchanged and looked like the work had done nothing.
     *
     * $judgedBy fingerprints the covering tests' contents, so improving
     * a test re-runs exactly the mutants that test covers and nothing
     * else.
     */
    public function has(Mutant $mutant, string $judgedBy): bool
    {
        $entry = $this->entries[self::keyOf($mutant)] ?? null;

        return $entry !== null && ($entry['t'] ?? null) === $judgedBy;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function append(Mutant $mutant, MutationVerdict $verdict, string $judgedBy): void
    {
        $entry = [
            'h'        => self::keyOf($mutant),
            't'        => $judgedBy,
            'file'     => $mutant->file,
            'class'    => $mutant->class,
            'line'     => $mutant->line,
            'mutator'  => $mutant->mutatorId,
            'outcome'  => $verdict->outcome->value,
            'killedBy' => $verdict->killedBy,
            'reason'   => $verdict->reason,
            'duration' => $verdict->duration,
        ];

        $this->entries[$entry['h']] = $entry;

        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            return;
        }

        $handle = fopen($this->path, 'a');

        if ($handle === false) {
            return;
        }

        fwrite($handle, json_encode($entry, JSON_THROW_ON_ERROR) . "\n");
        fclose($handle);
    }

    /**
     * The recorded verdicts for THIS run's mutants, rebuilt for the report.
     *
     * ⚠ Scoped, and the scope is the point. A journal outlives the source
     * it describes: edit a file and its old entries stop matching any
     * current mutant, but they are still in the file. Returning all of
     * them put 99 verdicts for mutants that no longer existed into a
     * report of 4143 — ✓ measured 2026-09-20, a score over a denominator
     * that included dead mutants.
     *
     * ⚠ The Mutant carried by each of these has an EMPTY mutatedSource.
     * It exists to be printed — file, line, mutator — and must never be
     * handed to an executor. A resumed run skips these mutants precisely
     * because their verdict is already known.
     *
     * @param list<Mutant> $scope the mutants this run generated
     *
     * @return list<MutationVerdict>
     */
    public function verdictsFor(array $scope): array
    {
        $wanted = [];

        foreach ($scope as $mutant) {
            $wanted[self::keyOf($mutant)] = true;
        }

        $verdicts = [];

        foreach ($this->entries as $hash => $entry) {
            if (!isset($wanted[$hash])) {
                continue;
            }

            $outcome = MutationOutcome::tryFrom(is_string($entry['outcome'] ?? null) ? $entry['outcome'] : '');
            $file    = $entry['file'] ?? null;
            $class   = $entry['class'] ?? null;
            $line    = $entry['line'] ?? null;
            $mutator = $entry['mutator'] ?? null;

            if (!$outcome instanceof MutationOutcome
                || !is_string($file) || $file === ''
                || !is_string($class) || $class === ''
                || !is_int($line) || $line < 1
                || !is_string($mutator) || $mutator === ''
            ) {
                continue;
            }

            $mutant   = new Mutant($file, $class, $line, $mutator, '');
            $duration = is_float($entry['duration'] ?? null) ? $entry['duration'] : 0.0;
            $killedBy = $entry['killedBy'] ?? null;
            $reason   = $entry['reason'] ?? null;

            // Through the named constructors, because MutationVerdict's
            // own constructor is private — the journal reads a recorded
            // outcome back, it does not invent a new kind of verdict.
            $verdicts[] = match ($outcome) {
                MutationOutcome::Killed => MutationVerdict::killed(
                    $mutant,
                    is_string($killedBy) && $killedBy !== '' ? $killedBy : 'unknown',
                    $duration,
                ),
                MutationOutcome::Escaped => MutationVerdict::escaped($mutant, $duration),
                MutationOutcome::Errored => MutationVerdict::errored(
                    $mutant,
                    is_string($reason) && $reason !== '' ? $reason : 'unknown',
                    $duration,
                ),
                MutationOutcome::TimedOut   => MutationVerdict::timedOut($mutant, $duration),
                MutationOutcome::NotCovered => MutationVerdict::notCovered($mutant),
                MutationOutcome::Equivalent => MutationVerdict::equivalent(
                    $mutant,
                    is_string($reason) && $reason !== '' ? $reason : 'unknown',
                ),
            };
        }

        return $verdicts;
    }
}
