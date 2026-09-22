<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Why each divergence is allowed to exist: a pair of the incumbent's OWN
 * verdicts that cannot both be right, executed against it on every run.
 *
 * It is EMPTY because divergences.php is. There is nothing to justify.
 *
 * Every entry that once lived here was withdrawn by measurement rather
 * than by argument, which is what the file was for. The falsy-'0' pair
 * ("'9' is a slug and '0' is not, though nothing about slugs separates
 * them") was refuted outright: something does separate them -- PHPUnit's
 * IsEmpty, the primitive the assertion is composed from -- and '!!!',
 * '---' and '   ' fail identically while '00' passes. The stringified
 * pairs went when the cast became the default, since a difference nobody
 * can opt out of is not a difference at all.
 *
 * The bar, if this ever refills: a contradiction proves the incumbent
 * inconsistent, which is NOT the same as proving Crucible right, so each
 * entry also carries `truth` -- the independent check that settles which
 * side is correct, measured against something with no stake in either
 * engine. Corroborate before recording, not after.
 *
 * @return list<array{
 *     group: non-empty-string,
 *     quirk: non-empty-string,
 *     claim: non-empty-string,
 *     truth: non-empty-string,
 *     proof: list<array{0: non-empty-string, 1: mixed, 2: 'pass'|'fail'}>
 * }>
 */

return [];
