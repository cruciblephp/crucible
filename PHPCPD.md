# What Crucible needs from phpcpd-next

Crucible embeds phpcpd-next as an optional dev tool: `crucible.php` can declare a duplication
check, and the run reports it as an ordinary gate. This file is the consumer's side of that
contract — what Crucible actually calls, what would break it, and what would make it better.

**Taken in from phpcpd-next's `docs/embedders/crucible.md` on 2026-09-03, at its maintainer's
request.** A consumer's requirements doc belongs with the consumer; phpcpd should not carry a file
about one downstream. phpcpd-next may delete its copy.

**Evidence marks.** ✓ measured on this tree · ⓘ not measured here — either reported by
phpcpd-next or read from the published v2.0 tag. Neither is a run against the checkout that would
break, so ⓘ is not treated as checked until it has been.

**There is no 1.5.0 and there never will be.** Everything this file called 1.5.0 shipped as
**2.0**, tagged 2026-09-15 — the same surface, relicensed, plus further changes under the hood.
Read every "2.0" below as the release that granted the ask, not as a second round of asks.

**Two checkouts, and only one of them is a gate.** `../phpcpd-main-beta` is the harvest source
the inherited ⓘ marks refer to, and it is **not clean**: an uncommitted change to
`src/Util/FileFinder.php` (widening the generated-file markers) and an untracked `resume.txt`.
It reaches nothing here — no code in this repository names it, only this file does. What the
duplication check loads is `phpcpd-main/`, a **separate checkout, clean, moved to the `v2.0` tag
on 2026-09-16** (`a975e50`, `Version::NUMBER` = `2.0.0`) — the path
`DuplicationCheckTest::setUp()` resolves and `conformance/oracles-registry.php` installs. It stood
at `v1.4` (`0ab94a4`) until then, which is why everything below was ⓘ.
✓ Measured 2026-09-16: the suite's phpcpd tests RUN rather than skip against 2.0, and the only
skips are the two that cannot observe an absence while the tool is present. So the dirty tree
cannot move a verdict here.

**The seven statements, now run against 2.0 on this tree.** ✓ 2026-09-16, by reflection and by
executing the bench — six answer, one does not:

| # | statement | measured on this tree |
|---|---|---|
| 1 | `detect()` takes ten parameters, the tenth `bool $defaultExcludes = true` | ✓ exactly that |
| 2 | `CodeCloneFile` takes three constructor arguments, the third `?int $numberOfLines = null` | ✓ **on the ordinal, ✗ on the count** — see below |
| 3 | `CodeCloneFile::lastLine(int $cloneNumberOfLines): int` exists | ✓ |
| 4 | `composer.json` reads `"license": "MIT"`, `src/Detector/Strategy/SuffixTree/` gone | ✓ both |
| 5 | `bench/check-superset.php` passes 3/3 | ✗ **1 of 3 FAILS**, exit 1 — see below |
| 6 | `supports(string): bool` answers true for `default-excludes`, `divergences`, `line-spans`, false for `classification` and anything unrecognised | ✓ exactly that, including `nonsense-xyz` false |
| 7 | `$name` and `$startLine` are still public readonly | ✓ `public readonly string` / `public readonly int` |

**Item 2: the ordinal held, the count did not.** The constructor takes **five** promoted
parameters — `string $name, int $startLine, ?int $numberOfLines, ?int $numberOfTokens,
?int $startToken` — so `$numberOfLines` is third exactly as stated, and "three constructor
arguments" is two short. Crucible passes none of them and reads two, so nothing here moves; the
statement is what needs correcting, not the code. ⚠ An earlier revision of this file guessed
"six promoted properties, `$numberOfLines` fourth" from the published source on GitHub. That was
wrong in both halves, and reflection on the installed tree is what caught it — which is the
entire argument for the ✓/ⓘ split.

**Item 5 does not pass, and the way it fails is the interesting part.** `bench/check-superset.php`
exits 1 with `1 of 3 checks FAILED`, and the one that fails is the CONTROL: *"the baseline found
clones, so subsumption is being checked against something — 0 rabin-karp clones"*. At
`--min-tokens=100 --min-lines=5` over its own 110 files, rabin-karp finds nothing, so there is no
baseline to subsume and the other two checks pass over an empty set. That is a corpus answer
rather than an engine regression — and it is phpcpd-next's bench on phpcpd-next's tree, so the
call is its maintainer's, not this consumer's. Recorded because the statement said 3/3 and this
tree says otherwise.

**All seven were machine-verified upstream on 2026-09-03** and reported as passing. Six of the
seven now hold here too; item 5 is the one a local run answers differently, which is what a
consumer-side re-verification is for.

⚠ **2.0's floor is above Crucible's.** It requires `php: >=8.4` where Crucible requires `>=8.3`,
so the duplication check is unavailable to a user on Crucible's own baseline, and the
`class_exists()` guard is what makes that a named configuration answer rather than a fatal. The gap
NARROWED: v1.4 required `>=8.5`. This machine runs 8.5.10, which is why the tests above ran at all.

✓ **The whole consumer surface still binds.** The suite runs green against 2.0 — `detect()` with
its nine named arguments, `count()`, `clones()`, `files()`, `numberOfLines()`, and the two
properties — with no change to `DuplicationCheck` needed for the move.

Item 7 is the one worth keeping on the list even though it looks safe: promoted properties in a
`final readonly` class inherit `readonly`, so it *should* follow from the constructor widening —
and "it should follow" is not "reflection says so". Upstream checked rather than assumed, on the
one item where this consumer's contract is what breaks.

## Status of the six asks, at 2.0

| # | ask | outcome |
|---|---|---|
| 1 | `@api` markers or a facade | ⓘ deferred to the next minor |
| 2 | keep the properties public | ✓ granted — `$name` / `$startLine` stay public readonly |
| 3 | `defaultExcludes` on `detect()` | ✓ granted, tenth parameter — **but see "not yet usable here"** |
| 4 | `Phpcpd::supports()` | ✓ **granted, pulled into 2.0** (`647a242`, tagged `api-supports`) |
| 5 | a clone-type enum | ⓘ deferred; `isGapped()` / `isReordered()` / `divergences()` stay readable meanwhile |
| 6 | keep `fuzzy` / `typeAnchored` | ✓ granted — both still accepted, `algorithm` still unvalidated |

## What Crucible calls today

One entry point and four reads, all in `src/Extension/Duplication/DuplicationCheck.php`:

```php
class_exists(Phpcpd::class)            // the "is it installed" probe

Phpcpd::detect(                        // NAMED arguments, all nine
    paths:, minLines:, minTokens:, algorithm:,
    exclude:, suffixes:, preset:, fuzzy:, typeAnchored:,
): CodeCloneMap

$map->count(): int
$map->clones(): list<CodeClone>
$clone->files(): list<CodeCloneFile>
$clone->numberOfLines(): int
$file->name          // property
$file->startLine     // property
```

That is the whole surface. Nothing else in Crucible touches phpcpd.

## What 2.0 changes for this consumer

**Clone coverage replaces the per-clone sum — and changes nothing here.** ✓ Measured
2026-09-03: Crucible reads `count()` and nothing else numeric. `numberOfDuplicatedLines()` and
`percentage()` appear nowhere in `src/`, `tests/`, `conformance/` or `benchmarks/`, so there is no
pinned line total or percentage to re-baseline, in the check or in any test. The gate thresholds on
clone count, which ⓘ is unchanged on all six benchmark corpora.

**`lastLine()` — the wrong arithmetic was never here.** ✓ `locations()` renders `name:startLine`
only, never an end line, so `startLine + numberOfLines() - 1` does not appear. If an end line is
ever rendered, it must use `lastLine()`: occurrences of one clone class do not all span the same
number of lines, so the arithmetic is wrong for any occurrence the class was not led by.

**Order independence costs nothing here.** ✓ Crucible passes `paths:` — directories — and never
assembles its own file list, so sorting inside `Engine::detect()` cannot change what it sees.

**⚠ Ask #3 is granted and not spendable yet.** `detect()` is called with named arguments against
**both** 1.4 and 2.0 — that is the whole reason this consumer reads properties instead of
accessors. Passing `defaultExcludes:` to a 1.4 install is an `Unknown named parameter` fatal, so
adopting the granted parameter would break exactly the compatibility the rest of this contract
exists to keep. Same failure shape as the `name()` break: silent until the moment it matters.

**A first version of this file named the wrong remedy, corrected 2026-09-03 by phpcpd-next.**
`supports()` cannot rescue this one. Reaching `Phpcpd::supports()` on a 1.4 install requires
`method_exists(Phpcpd::class, 'supports')` first — and that probe *is already the 2.0 gate*, so
once it answers true you know `defaultExcludes` exists and `supports('default-excludes')` has told
you nothing you did not have. Reflection over `detect()`'s parameter list is likewise the wrong
tool.

The two honest ways to spend it:

```php
// Probe any 2.0-only symbol, then build the call as named arguments.
$args = ['paths' => $this->paths, /* ... */];

if (method_exists(Phpcpd::class, 'supports')) {
    $args['defaultExcludes'] = $this->defaultExcludes;
}

Phpcpd::detect(...$args);   // string keys spread as named arguments
```

— or drop 1.4. **Crucible passes nine parameters and does not expose `defaultExcludes` today**: it
is new constructor surface on an opt-in check nobody has asked it of, and the technique above makes
it a small change on the day someone does.

## 1. Declare the embedder surface — the one that matters

`CodeCloneFile` dropped `name()` and `startLine()` between 1.4 and 2.0, keeping only the
public properties. Crucible called the methods, so 2.0 would have raised
`Call to undefined method CodeCloneFile::name()`.

**The shape of that failure is the argument.** `locations()` only runs when the clone count exceeds
the configured maximum, so the check would have stayed green through every clean run and blown up
at the exact moment it finally had something to report. A smoke test on a clean project shows
nothing. `testDuplicationIsPresentedAsFactsRatherThanAVerdict` reaches that path deliberately, and
it is the only reason the fix is verified rather than reasoned.

Crucible now reads the properties, which bind against 1.4 and 2.0 alike, so this specific break is
closed. The general one is not: `CodeClone.php`, `CodeCloneMap.php` and `CodeCloneMapIterator.php`
are among the files still carrying inherited copyright, and `CodeCloneFile` changed shape
*precisely because* it was rewritten. Crucible reads all three. The next rewrite breaks it the same
way.

**Ask (deferred to the next minor):** mark the eight members above as `@api`, or put a thin facade
in front of them, so the copyright-shedding rewrites can proceed behind a surface that does not
move. Crucible does not care what is behind it — that is the point.

## 2. Keep the properties public, whatever happens to the accessors

Crucible reads `$file->name` and `$file->startLine` rather than calling accessors, because
properties are the only form present in **both** versions. That choice is only safe while the
properties stay public. Removing accessors is survivable; removing the properties is not.

✓ Granted at 2.0, measured 2026-09-16: `public readonly string $name`, `public readonly int $startLine`.

## 3. `defaultExcludes` on `Phpcpd::detect()`

✓ Granted at 2.0 as the tenth parameter, matching `Orphans::detect()`, and it also fixes a latent
bug — the facade never passed the setting to `FileFinder`, so `no-default-excludes` was inert on
this path. Not yet adoptable here; see "not yet usable" above.

## 4. A capability probe, not a version string

Crucible's only probe is `class_exists(Phpcpd::class)`. Both trees ship `Version::NUMBER`, but
parsing a version to decide which API is present is exactly the red tape worth avoiding — and it
gets worse while the surface is moving.

✓ **Granted and shipped in 2.0** rather than the next minor. The argument that decided it: a
probe that ships one release later can never gate the release that introduced it, so every
capability added in 2.1 would be undetectable by a consumer supporting 2.0 unless `supports()` were
already there. That cost is permanent and compounds per release; shipping it costs one method.

It does not describe `defaultExcludes` — see above, the probe that reaches it is already the 2.0
gate — and it was never asked to. Its value is entirely 2.1-and-later.

ⓘ Both constraints below are enforced by phpcpd-next's own tests rather than merely honoured: the
parameter's type is pinned to `string` by reflection, because an enum would pass every other test
in the file while silently breaking the consumers the probe exists for; and each recognised string
is asserted to name something that exists, with `classification` guarded in **both** directions, so
`CodeClone::type()` cannot appear without the string being recognised and the string cannot be
recognised before the method. That two-way guard is the same shape as this repo's own skip-reason
registry, and it is what keeps a vocabulary from drifting off the surface it describes.

## 5. Expose the classification — the feature Crucible would actually use

This is the one that makes Crucible's report better rather than merely unbroken.

Crucible currently renders `N lines duplicated across Alpha.php:12 ↔ Beta.php:40` — a count and two
locations. 2.0 knows considerably more: Type-1/2/3, gapped versus reordered, and the divergent
ranges named on both sides.

A count tells someone that duplication exists. **A type tells them what to do about it** — a Type-2
rename is a refactor, a Type-3 with named divergent ranges is a decision about which copy is right,
and a reorder is usually neither. Crucible's duplication check is a gate whose whole job is to say
what to act on.

**Ask (deferred):** expose the clone type as a first-class value on `CodeClone` rather than
something an embedder infers from which accessors return non-empty. Crucible will render it. The
proposed shape is at the end of this file.

## 6. Deprecate `fuzzy` / `typeAnchored`, do not remove them

Crucible passes both by name today because 1.4 needs them. If the unified engine subsumes them, let
them keep existing and be ignored. Because the call site uses named arguments, **removing a
parameter is a hard break** even when the value would have been the default.

The same applies to `algorithm`: Crucible passes whatever the user configured straight through and
does not validate it, so new values are free, but retiring an accepted one breaks configurations
Crucible cannot see.

✓ Granted at 2.0: both still accepted, `algorithm` still passes through unvalidated.

## What Crucible does not need

Stated so it does not get built:

- No opinion on which engine wins. Crucible passes `algorithm` through and reports what comes back.
- No reporting formats. Crucible renders its own gate output from the object graph; `Log/*` is
  irrelevant to it.
- No orphan integration in the duplication check. Crucible uses `--orphans` as a development
  practice on its own tree, not through this API.
- No performance guarantee. The check is opt-in and its cost is the user's choice.

## Closed — the licensing and superset item

The original doc's last section recorded that `src/Detector/Strategy/SuffixTree/` could not be
removed until `bench/check-superset.php` passed, and that this kept `composer.json` at
`(BSD-3-Clause AND Apache-2.0)`.

✓ **The licence half resolved in 2.0, and went further than the ask.** Measured 2026-09-16 on the
installed tree: the ConQAT suffix-tree engine is gone — no `src/Detector/Strategy/SuffixTree/` —
and `composer.json` reads `"license": "MIT"`, not the `BSD-3-Clause` this file asked for.

✗ **The superset half does not hold here.** `bench/check-superset.php` exits 1 with 1 of 3 checks
failed; see item 5 above for why, and for why that is phpcpd-next's call rather than this
consumer's. The harvest checkout still shows the dual license because it predates the release.

## Proposed shape for the deferred asks

Offered for the next minor, from the consumer that would render them.

**The type enum.** Three cases, because three is what the literature names and what a reader can
act on — and a fourth for the honest gap:

```php
enum CloneType: string {
    case Identical = 'identical';   // Type-1: the same text, whitespace and comments aside
    case Renamed   = 'renamed';     // Type-2: the same structure, different identifiers or literals
    case Gapped    = 'gapped';      // Type-3: Type-2 plus divergent ranges, which divergences() names
    case Unknown   = 'unknown';     // the strategy did not classify — never inferred, never guessed
}
```

`CodeClone::type(): CloneType`. Reordering is orthogonal — a property of a gapped clone, not a
fourth type — so `isReordered()` stays its own predicate.

**`Unknown` is the load-bearing case, and more so than this consumer first argued.** ⓘ Measured by
phpcpd-next 2026-09-03: `CloneClassifier` emits type-1, type-2, gapped, reordered and exact
internally, but `CodeClone` collapses that to two booleans and **discards the Type-1/Type-2
distinction**; Rabin-Karp and TokenBag emit no classification at all, so under the shipped default
(`phpcpd <dir>`, rk+tb merged) **every clone is unclassified**. Without `Unknown`, the most common
configuration would render every finding as a confident `Identical`. So this is not an accessor to
add — `kind` has to be threaded through `CodeClone`, and the two non-unified engines will answer
`Unknown` forever, correctly. Crucible renders nothing for `Unknown`.

**Capability strings.** Named for what an embedder can then *do*, never for the engine that
provides it, so a strategy change does not rename a capability:

| string | means |
|---|---|
| `classification` | `CodeClone::type()` returns a real case rather than `Unknown` |
| `divergences` | `divergences()` names ranges on both sides |
| `default-excludes` | `detect()` accepts `defaultExcludes:` |
| `line-spans` | `CodeCloneFile::$numberOfLines` is populated and `lastLine()` is meaningful |

**One rule, no exceptions: a capability answers "can I call this", never "will it be populated".**
That is what lets `supports()` stay static and engine-agnostic. `classification` and `divergences`
are only meaningful under `--algorithm=unified`, and the right reading is *the library can, given
the right algorithm* — the per-clone answer is `Unknown`, and `$numberOfLines` is likewise `null`
where the strategy did not measure it. The data says what it knows; the capability says what exists
to call. An algorithm argument on `supports()` would ask it a question it cannot answer anyway,
since the algorithm is chosen at `detect()` time from user configuration this consumer passes
straight through.

**The parameter must be a string, not an enum.** An enum argument cannot name a case the installed
version has never heard of, which defeats the forward-safety the whole probe exists for.

Recognised in 2.0 on that rule: `default-excludes` true, `line-spans` true, `divergences` true,
`classification` **false** until `type()` exists — a capability string that predates the surface it
describes is worse than no string at all. ✓ Measured 2026-09-16 on the installed tree, including an
unrecognised string answering false.
