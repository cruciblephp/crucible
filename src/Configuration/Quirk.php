<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

/**
 * A measured bug in an incumbent that Crucible does not reproduce by
 * default, and can be asked to reproduce by name.
 *
 * Where Crucible and an incumbent disagree, Crucible answers correctly
 * and records the divergence rather than copying the defect down as a
 * specification. That is the wrong default for exactly one audience: a
 * suite being ported that already asserts the quirk, where the loud
 * failure is noise rather than a finding.
 *
 * Each is opted into **individually**, never as a mode. A single
 * "compatibility" switch becomes a bucket whose contents nobody can
 * audit, and turning it on to silence one failure silently accepts
 * every other bug in it. One name per quirk keeps the trade visible at
 * the point it is made.
 *
 * The name does three jobs at once, so there is nothing to keep in
 * sync: it is the value written in `crucible.php`, the heading the
 * documentation uses, and the key the runtime reads.
 *
 * Two names were REMOVED rather than deprecated: `quirk_falsy_slug` and
 * `quirk_falsy_hostname`. They were never incumbent bugs. `toBeSlug`
 * reduces its subject and asks whether the result is empty, and
 * `empty('0')` is true in PHP, so `'0'` failed there for exactly the
 * reason `'!!!'` and `'---'` do; `toBeHostname` is the same shape over
 * `filter_var()`'s valid-but-falsy return. Crucible disagreed only
 * because those two matchers spelled the predicate out by hand where
 * `IsEmpty` already existed. Composing the primitive made the
 * incumbent's answer fall out and left both quirks bridging nothing, so
 * keeping them would have shipped config values that silently do
 * nothing -- worse than removing them. See DESIGN.md D-109.
 */
enum Quirk: string
{
    // No cases, and that is the parity claim rather than an omission.
    //
    // Over 3,344 grid cells, 44 matchers and 76 corpus values, in both
    // forms, Crucible answers exactly as Pest 5.1.1 does. There is no
    // incumbent bug it declines to reproduce, so there is nothing to opt
    // back into. compare.php re-proves that every run and fails the
    // moment a cell moves.
    //
    // The mechanism stays because the bar it enforces is the valuable
    // part: any future divergence must NAME the quirk that restores the
    // incumbent, and the probe checks that the quirk actually does. A
    // correction nobody can opt out of is a fork, not a fix.
    //
    // Three cases were removed rather than deprecated. FalsySlug and
    // FalsyHostname were never incumbent bugs -- they were artifacts of
    // Crucible hand-writing a predicate where IsEmpty already existed
    // (D-109). StringifiedSubject was real but gated the wrong way: it
    // left a migrated Pest suite FAILING on `expect([])->toBeAlpha()`
    // until someone opted in, which is the D-004 violation the rule
    // exists to prevent. Casting by default is fidelity; the broader
    // reading belongs to the crucible dialect, once that has a seam.
}
