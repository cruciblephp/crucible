<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use LucianoPereira\Crucible\Test\TestGroup;

use function count;
use function spl_object_id;

/**
 * What impact selection decided: a narrowed group list, or null
 * groups meaning "run everything" with the reason in the notes —
 * the safe fallback is always the full suite, never silence.
 *
 * The list is carried in two tiers. `definite` is an edge the
 * dependency graph resolved — the changed file is in the group's
 * transitive closure. `possible` is anything inferred or declared: an
 * impact rule, a non-PHP path, a change whose reach cannot be computed.
 * `groups` stays the union, because that is what runs; the split exists
 * so a selection can say which half it can prove.
 *
 * The safety bias, stated once so every later judgement call has an
 * answer: **a tier that cannot be proven never shrinks the answer.**
 * Over-selecting costs seconds; under-selecting ships a regression
 * nobody sees, because a test that was never run cannot fail. When the
 * two trade off, widen.
 */
final readonly class ImpactResult
{
    /**
     * @param ?list<TestGroup>       $groups   null = the selection could not narrow safely
     * @param list<non-empty-string> $notes    printed to the console, one line each
     * @param list<TestGroup>        $definite groups a resolved graph edge selected
     * @param list<TestGroup>        $possible groups an impact rule put in doubt
     */
    private function __construct(
        public ?array $groups,
        public array $notes,
        public array $definite = [],
        public array $possible = [],
    ) {}

    /**
     * @param non-empty-string $reason
     */
    public static function everything(string $reason): self
    {
        return new self(null, [$reason . ' — running the full suite.']);
    }

    /**
     * The union is what runs. A group with a proven edge stays in the
     * definite tier even when a rule also names it: proof outranks
     * inference, and counting it twice would overstate what was guessed.
     *
     * @param list<TestGroup>        $definite
     * @param list<TestGroup>        $possible
     * @param list<non-empty-string> $notes
     */
    public static function tiered(array $definite, array $possible, array $notes): self
    {
        $seen = [];

        foreach ($definite as $group) {
            $seen[spl_object_id($group)] = true;
        }

        $onlyPossible = [];

        foreach ($possible as $group) {
            if (!isset($seen[spl_object_id($group)])) {
                $seen[spl_object_id($group)] = true;
                $onlyPossible[]              = $group;
            }
        }

        return new self(
            [...$definite, ...$onlyPossible],
            $notes,
            $definite,
            $onlyPossible,
        );
    }

    /**
     * @param list<TestGroup>        $groups
     * @param list<non-empty-string> $notes
     */
    public static function subset(array $groups, array $notes): self
    {
        return new self($groups, $notes, $groups, []);
    }

    /** How many selected groups rest on inference rather than a resolved edge. */
    public function possibleCount(): int
    {
        return count($this->possible);
    }

    /** @return list<TestGroup> */
    public function selected(): array
    {
        return $this->groups ?? [];
    }
}
