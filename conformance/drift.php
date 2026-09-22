<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Comparing what is true now against what was written down, in both
 * directions.
 *
 * Three checks grew this shape independently — the CLI surface ledger,
 * the coverage-XML vocabulary probe, and the suite's skip reasons — and
 * all three care about the same two failures:
 *
 *   unrecorded  observed, but nothing says it should be. New drift.
 *   stale       recorded, but no longer observed. The note outlived
 *               the thing it described.
 *   returned    recorded as *closed*, and observed anyway. A
 *               regression: something that was fixed came back.
 *
 * The second direction is the one that is easy to leave out and the one
 * that rots: an exemption nobody prunes stops being a decision and
 * becomes furniture, and the next reader cannot tell which it is.
 *
 * The third looks like a different shape — an intersection where the
 * others are differences — and was very nearly left out of here for
 * that reason. It is the same question. A closed set is a record too,
 * one that says "this must not be observed"; checking it is still
 * asking whether reality still matches what was written down. Only the
 * ledger has one, so it is an argument rather than a mode: pass a
 * closed set if you have one.
 */

final class Drift
{
    /**
     * @param list<string>          $observed
     * @param array<string, string> $recorded key => why it is recorded
     */
    private function __construct(
        public readonly array $observed,
        public readonly array $recorded,
        /** @var list<string> */
        public readonly array $unrecorded,
        /** @var list<string> */
        public readonly array $stale,
        /** @var list<string> */
        public readonly array $returned,
    ) {}

    /**
     * @param list<string>          $observed
     * @param array<string, string> $recorded entries that may be observed, each with why
     * @param array<string, string> $closed   entries that must *not* be observed any more
     */
    public static function between(array $observed, array $recorded, array $closed = []): self
    {
        return new self(
            $observed,
            $recorded,
            \array_values(\array_diff($observed, \array_keys($recorded))),
            \array_values(\array_diff(\array_keys($recorded), $observed)),
            \array_values(\array_intersect(\array_keys($closed), $observed)),
        );
    }

    public function clean(): bool
    {
        return $this->unrecorded === [] && $this->stale === [] && $this->returned === [];
    }

    /**
     * One line per observed entry with the note it was recorded with,
     * then the drift. The recorded notes are printed even when nothing
     * drifted, because a list of exemptions nobody ever reads is how
     * they stop being read.
     *
     * @param non-empty-string $label      what the observed set is, for the header
     * @param non-empty-string $unrecordedHint what to do about a new entry
     */
    public function report(string $label, string $unrecordedHint): string
    {
        $out = '';

        foreach ($this->observed as $entry) {
            $known = \array_key_exists($entry, $this->recorded);

            $out .= \sprintf(
                "  %s %-46s %s\n",
                $known ? ' ' : '!',
                $entry,
                $known ? $this->recorded[$entry] : 'UNRECORDED — ' . $unrecordedHint,
            );
        }

        foreach ($this->stale as $entry) {
            $out .= \sprintf("  ! %-46s RECORDED BUT ABSENT — delete the entry\n", $entry);
        }

        foreach ($this->returned as $entry) {
            $out .= \sprintf("  ! %-46s CLOSED BUT BACK — a regression, not bookkeeping\n", $entry);
        }

        return \sprintf(
            "%s: %d recorded, %d unrecorded, %d stale, %d returned\n%s",
            $label,
            \count($this->recorded),
            \count($this->unrecorded),
            \count($this->stale),
            \count($this->returned),
            $out,
        );
    }
}
