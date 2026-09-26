<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutationJournal;
use LucianoPereira\Crucible\Mutation\MutationOutcome;
use LucianoPereira\Crucible\Mutation\MutationVerdict;

use function file_put_contents;
use function getmypid;
use function is_file;
use function json_encode;
use function sys_get_temp_dir;
use function unlink;

/**
 * The journal that makes a killed mutation run resumable.
 *
 * ✓ Two real runs died at 2443 of 4095 and lost every verdict, which is
 * what this exists to stop.
 */
#[CoversClass(MutationJournal::class)]
final class MutationJournalTest extends TestCase
{
    /** @var non-empty-string */
    private string $path = '/tmp/journal';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/crucible-journal-' . getmypid() . '.ndjson';

        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /** @param positive-int $line */
    private function mutant(string $source = '<?php // a', int $line = 1): Mutant
    {
        return new Mutant('/project/src/Foo.php', 'Foo', $line, 'arithmetic', $source);
    }

    public function testAnAbsentJournalIsEmptyRatherThanAnError(): void
    {
        $journal = MutationJournal::load($this->path);

        self::assertSame(0, $journal->count());
        self::assertSame([], $journal->verdictsFor([$this->mutant()]));
        self::assertFalse($journal->has($this->mutant(), 'judge-a'));
    }

    public function testAVerdictSurvivesBeingReloaded(): void
    {
        $mutant = $this->mutant();

        MutationJournal::load($this->path)->append($mutant, MutationVerdict::escaped($mutant, 1.5), 'judge-a');

        // A fresh load is the whole point: the process that wrote it is
        // the one assumed to have died.
        $reloaded = MutationJournal::load($this->path);

        self::assertTrue($reloaded->has($mutant, 'judge-a'));
        self::assertSame(1, $reloaded->count());

        $verdicts = $reloaded->verdictsFor([$mutant]);

        self::assertCount(1, $verdicts);
        self::assertSame(MutationOutcome::Escaped, $verdicts[0]->outcome);
        self::assertSame('/project/src/Foo.php', $verdicts[0]->mutant->file);
        self::assertSame(1, $verdicts[0]->mutant->line);
        self::assertSame('arithmetic', $verdicts[0]->mutant->mutatorId);
    }

    public function testEveryOutcomeRoundTrips(): void
    {
        $journal = MutationJournal::load($this->path);
        $seeds   = [
            MutationVerdict::killed($this->mutant('<?php // k'), 't.php::a', 0.5),
            MutationVerdict::escaped($this->mutant('<?php // e'), 0.5),
            MutationVerdict::errored($this->mutant('<?php // r'), 'boom', 0.5),
            MutationVerdict::timedOut($this->mutant('<?php // t'), 0.5),
            MutationVerdict::notCovered($this->mutant('<?php // n')),
            MutationVerdict::equivalent($this->mutant('<?php // q'), 'the bound is never reached'),
        ];

        $expected = [];

        foreach ($seeds as $verdict) {
            $journal->append($verdict->mutant, $verdict, 'judge-a');
            $expected[] = $verdict->outcome->value;
        }

        $scope    = [];
        $outcomes = [];

        foreach ($seeds as $verdict) {
            $scope[] = $verdict->mutant;
        }

        foreach (MutationJournal::load($this->path)->verdictsFor($scope) as $verdict) {
            $outcomes[] = $verdict->outcome->value;
        }

        self::assertEqualsCanonicalizing($expected, $outcomes);
        self::assertCount(6, $outcomes, 'every outcome kind survives the round trip');
    }

    /**
     * The identity is the mutated SOURCE, not file:line:mutator.
     *
     * Two mutants share that triple routinely — `Snapshots.php:111
     * logical` twice in one measured run — so keying on it would make a
     * resumed run skip a mutant it had never decided.
     */
    public function testTwoMutantsOnOneLineAreTwoEntries(): void
    {
        $first  = $this->mutant('<?php $a = 1 + 2;');
        $second = $this->mutant('<?php $a = 1 - 2;');

        $journal = MutationJournal::load($this->path);
        $journal->append($first, MutationVerdict::escaped($first, 0.1), 'judge-a');

        self::assertTrue($journal->has($first, 'judge-a'));
        self::assertFalse($journal->has($second, 'judge-a'), 'same file, line and mutator — different mutation');

        $journal->append($second, MutationVerdict::escaped($second, 0.1), 'judge-a');

        self::assertSame(2, MutationJournal::load($this->path)->count());
    }

    /**
     * A verdict outside this run's mutants is not this run's business.
     *
     * ⚠ The journal outlives the source it describes. Reporting every
     * entry it holds put 99 verdicts for mutants that no longer existed
     * into a 4143-mutant report — a score over a denominator containing
     * dead mutants. ✓ Measured 2026-09-20 on the first complete run.
     */
    public function testVerdictsOutsideTheScopeAreLeftOut(): void
    {
        $current = $this->mutant('<?php // current');
        $stale   = $this->mutant('<?php // from a source that has moved');

        $journal = MutationJournal::load($this->path);
        $journal->append($current, MutationVerdict::escaped($current, 0.1), 'judge-a');
        $journal->append($stale, MutationVerdict::killed($stale, 't.php::a', 0.1), 'judge-a');

        $reloaded = MutationJournal::load($this->path);

        self::assertSame(2, $reloaded->count(), 'both are still on disk');
        self::assertCount(1, $reloaded->verdictsFor([$current]), 'only the one in scope is reported');
        self::assertSame(
            MutationOutcome::Escaped,
            $reloaded->verdictsFor([$current])[0]->outcome,
            'and it is the right one',
        );
    }

    /**
     * Rewrite a test and its verdicts stop matching, source untouched.
     *
     * ⚠ This is the one that shipped broken. SvgDocument's tests were
     * rewritten so 79 of 95 escapes died; the source never changed, so
     * every mutant hashed the same and a resumed run served the stale
     * "escaped" verdicts back. ✓ Measured 2026-09-20: the score came back
     * unchanged and read as if the work had done nothing.
     */
    public function testADifferentJudgeDoesNotMatchARecordedVerdict(): void
    {
        $mutant = $this->mutant();

        MutationJournal::load($this->path)->append($mutant, MutationVerdict::escaped($mutant, 0.1), 'judge-a');

        $reloaded = MutationJournal::load($this->path);

        self::assertTrue($reloaded->has($mutant, 'judge-a'), 'same tests, same answer');
        self::assertFalse($reloaded->has($mutant, 'judge-b'), 'different tests, decide again');
    }

    /** Edit the source and its verdicts stop matching, with no bookkeeping. */
    public function testAChangedSourceInvalidatesItsOwnVerdict(): void
    {
        $before = $this->mutant('<?php // before');
        $after  = $this->mutant('<?php // after');

        MutationJournal::load($this->path)->append($before, MutationVerdict::escaped($before, 0.1), 'judge-a');

        self::assertFalse(MutationJournal::load($this->path)->has($after, 'judge-a'));
    }

    /**
     * A torn final line is what a killed append leaves, and refusing to
     * read past it would throw away the thousands of records before it.
     */
    public function testAHalfWrittenLastLineIsSkippedNotFatal(): void
    {
        $mutant = $this->mutant();
        $good   = json_encode([
            'h'       => MutationJournal::keyOf($mutant),
            't'       => 'judge-a',
            'file'    => '/project/src/Foo.php',
            'class'   => 'Foo',
            'line'    => 1,
            'mutator' => 'arithmetic',
            'outcome' => 'escaped',
        ]);

        file_put_contents($this->path, $good . "\n" . '{"h":"deadbeef","fi');

        $journal = MutationJournal::load($this->path);

        self::assertSame(1, $journal->count(), 'the intact record survives the torn one');
        self::assertTrue($journal->has($mutant, 'judge-a'));
    }

    public function testARecordMissingItsIdentityIsIgnored(): void
    {
        file_put_contents($this->path, json_encode(['h' => 'abc', 'outcome' => 'escaped']) . "\n");

        // Loaded as an entry, but it cannot be rebuilt into a verdict:
        // a report cannot print a mutant with no file or line.
        self::assertSame([], MutationJournal::load($this->path)->verdictsFor([$this->mutant()]));
    }
}
