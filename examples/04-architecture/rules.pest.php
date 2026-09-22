<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Architecture rules.
 *
 * A rule states the shape the code must keep, and fails like any other
 * test when the shape drifts. The universe a rule reasons about is the
 * configured `->source(include: [...])` — the code you own — so vendor
 * and tests are outside every rule by construction.
 *
 * These rules are real: they run against Crucible's own source, which is
 * what `->source()` points at in this repository.
 */

// Targeting: a bare namespace is a prefix, so this means everything
// beneath Event. `*` matches within one namespace segment, `**` across
// segments, and an exact class name targets just that class.
\arch('every event object declares strict types')
    ->expect('LucianoPereira\Crucible\Event')
    ->toUseStrictTypes();

// The layering rule. It names what a module may depend on, and reports
// anything else as "X uses Y" — the part that makes a violation
// actionable rather than just red.
//
// Only references INTO your own source are considered: PHP built-ins and
// vendor packages are ignored, because otherwise every rule would need a
// whitelist of Closure and ReflectionClass before it said anything.
\arch('the impact tier does not reach the browser tier')
    ->expect('LucianoPereira\Crucible\Impact')
    ->toOnlyUse(
        'LucianoPereira\Crucible\Impact',
        'LucianoPereira\Crucible\Test',
        'LucianoPereira\Crucible\Vitest',
        'LucianoPereira\Crucible\Attributes',
        'LucianoPereira\Crucible\Filesystem',
    );

// The inverse rule, and the one that catches a boundary crossed from the
// far side — where the offending file is not the file the rule is about.
\arch('the retrigger endpoint stays inside the watch tier')
    ->expect('LucianoPereira\Crucible\Watch\RetriggerEndpoint')
    ->toOnlyBeUsedIn('LucianoPereira\Crucible\Watch');

// `ignoring()` subtracts from the target set, with the same grammar.
// These three hold caches and are deliberately mutable.
\arch('impact value objects are immutable')
    ->expect('LucianoPereira\Crucible\Impact')
    ->ignoring(
        'LucianoPereira\Crucible\Impact\DependencyGraph',
        'LucianoPereira\Crucible\Impact\DependencyIndex',
        'LucianoPereira\Crucible\Impact\ReferenceScanner',
        // An enum, and immutable by construction — but "readonly" is
        // not what an enum IS. Pest's own toBeReadonly excludes them
        // explicitly, so widening the matcher here would disagree with
        // the incumbent to say something the language already says.
        'LucianoPereira\Crucible\Impact\ImpactReason',
    )
    ->toBeReadonly();

/*
 * Worth knowing: a rule that matches NO class fails, as does a rule with
 * no expectation. A renamed namespace would otherwise leave a green test
 * that quietly enforces nothing, which is worse than having no rule.
 */
