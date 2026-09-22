<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Every place the Pest dialect answers differently from Pest 5.1.1, as
 * [matcher, corpus index, the quirk that restores the incumbent].
 *
 * It is EMPTY, and that is the claim: over 3,344 cells, 44 matchers, 76
 * corpus values, both forms, Crucible answers exactly as the incumbent
 * does, with nothing to opt into. compare.php proves it on every run and
 * fails the moment a cell moves.
 *
 * Getting here took four passes, and the shape of them is the useful
 * part -- a divergence has four possible causes, not two:
 *
 *  1. The incumbent is genuinely wrong. Correct it by default and let a
 *     named quirk restore it -- but only with evidence from OUTSIDE both
 *     engines. None survived that test.
 *  2. The incumbent is merely narrower. A narrow answer is still a true
 *     one, so match it (D-004). This took 97 divergences to 42.
 *  3. Crucible lacks a capability PHP itself endorses -- an int, a float
 *     or a Stringable renders the way var_export and json_encode render
 *     it, and PHP accepts a Stringable wherever a string parameter is
 *     declared. Fill it by default; no quirk, no row (D-106, 18 rows).
 *  4. The disagreement is an artifact of OUR implementation shape: a
 *     predicate written out by hand where a primitive already existed.
 *     Compose the primitive and it vanishes (D-109, 7 rows, 2 quirks).
 *
 * What remained after those four were the (string) cast rows -- an array
 * reaching toBeAlpha as 'Array', a bool as '1'. They were quirk-gated on
 * the reading that PHP warning about the array cast made the incumbent
 * wrong. It does not: D-004 says a test written as a Pest test asks for
 * Pest's answers, and gating them meant a migrated suite FAILED here
 * until someone opted in -- the exact violation the rule exists to stop.
 * Crucible spells 'Array' literally rather than casting, so it reproduces
 * the answer without the engine's warning: fidelity at the level of the
 * answer, which is the level that matters (D-105).
 *
 * The cost, stated so it is not discovered later: `expect([])->toBeAlpha()`
 * now passes here exactly as it does there, and Crucible's broader reading
 * has nowhere to live until the crucible dialect gets a matcher seam.
 *
 * APPEND ONLY if this ever refills: rows address cells by corpus index.
 *
 * @return list<array{0: non-empty-string, 1: int, 2: non-empty-string}>
 */

return [];
