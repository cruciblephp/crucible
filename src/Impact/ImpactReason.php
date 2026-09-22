<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

/**
 * Why a changed file did what it did to the selection.
 *
 * Each case keys on a **structural** property — a resolved graph edge, a
 * declared rule, a deletion — never on a file extension or a guess about
 * intent. That is the whole point: "non-PHP" is a drawer, and a drawer
 * collects a README, a Blade template and an untested class together
 * when each one calls for a different fix (nothing, an impact rule, a
 * test). A reason that cannot say which fix it wants is not a reason.
 *
 * One name serves two jobs, as phpcpd's suppression rules do: the
 * machine-readable cause, and the heading its changes are reported
 * under. Keeping them the same string is what stops the report and the
 * model drifting apart.
 */
enum ImpactReason: string
{
    /** A group's closure contains the change: the edge is proven. */
    case Reached = 'reached';

    /** A declared impact rule (D-083) named groups for it. */
    case Ruled = 'ruled';

    /**
     * PHP the graph could read, that no group's closure reaches. Either
     * nothing tests it or nothing uses it — both worth knowing, and
     * neither is fixed by writing an impact rule.
     */
    case Uncovered = 'uncovered';

    /**
     * Owned by another tier of the same run — a configured Vitest suite
     * (D-080) answers for its own directory through Vitest's module
     * graph. Not the PHP tier's to explain, and reporting it as
     * unaccounted for would contradict the tier that did account for it.
     */
    case Claimed = 'claimed';

    /**
     * Outside the graph, matched by no rule, and claimed by no other
     * tier. The graph resolves class references, so a template, an asset
     * or a translation is invisible to it by construction; the fix is a
     * rule, not a test.
     */
    case Undeclared = 'undeclared';

    /** The change defines the run itself, so nothing can be narrowed. */
    case Environment = 'environment';

    public function heading(): string
    {
        return match ($this) {
            self::Reached     => 'reached by a resolved dependency edge',
            self::Ruled       => 'matched a declared impact rule',
            self::Uncovered   => 'reached by no test — untested or unused',
            self::Claimed     => 'answered by another tier of this run',
            self::Undeclared  => 'outside the dependency graph and matched by no impact rule',
            self::Environment => 'defines the environment',
        };
    }
}
