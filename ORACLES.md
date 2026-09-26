# Oracles

Some of Crucible's tests are only worth anything if they run against **the real thing**: the
real `phpunit` binary, the real `@inertiajs/core`, a real Laravel with real Livewire. Those
installs are called *oracles*, and none of them is vendored into this repository.

Two reasons they stay out of the tree:

**Provenance.** Crucible is a clean-room implementation — PHPUnit is the behavioral *spec*,
never the source. Vendoring the incumbents would blur a line the project depends on. They are
installed to be **executed**, never read.

**Honesty.** A test that verifies behavior against a copy of a library verifies the copy. The
Inertia and Livewire assertions exist because the real packages behave in opposite ways —
Livewire rewrites `wire:snapshot` in the DOM on every round trip, Inertia never updates
`data-page` after a client-side visit — and no amount of reasoning would have established
that. Running them did.

Every test that needs an oracle **skips itself** when the oracle is absent, so a fresh clone
is green without any of this. CI is the exception: it builds all four and then asserts that
nothing skipped, because a skipped browser test is indistinguishable from a passing one in a
summary line.

---

## `browser-oracle/` — Playwright

Backs the whole browser tier: `visit()`, page assertions, `waitForNetworkIdle()`.

```bash
mkdir -p browser-oracle && cd browser-oracle
npm init -y
npm install playwright
npx playwright install chromium
```

`crucible.php` points at it with `->browser(playwrightRoot: __DIR__ . '/browser-oracle')`. Set
`CRUCIBLE_PLAYWRIGHT_ROOT` to use an install you already have somewhere else.

## `inertia-oracle/` — a real `@inertiajs/core`

The only oracle whose build files are **tracked**, because they are genuine source: an entry
point and its pinned dependencies.

```bash
cd inertia-oracle
npm install
npm run build          # esbuild bundles the real library into inertia.js
```

`node_modules/` and the built `inertia.js` are ignored; `entry.mjs`, `package.json` and the
lockfile are not.

## `livewire-oracle/` — a real Laravel + Livewire application

Livewire's client is inseparable from its server: the round trip `wireClick()` waits for is an
HTTP request to Livewire's own update endpoint, which recomputes the component and rewrites
the snapshot. Only a running application can answer it, so this oracle is a whole app rather
than a bundled asset.

```bash
php conformance/install-oracles.php livewire-oracle
cd livewire-oracle
php artisan make:livewire Counter

cp ../.github/fixtures/counter.blade.php resources/views/components/*counter.blade.php
cp ../.github/fixtures/counter-page.blade.php resources/views/counter-page.blade.php
cp ../.github/fixtures/web.php routes/web.php
```

The three fixture files are tracked in [`.github/fixtures/`](.github/fixtures) — the component
(with a deliberate 600 ms delay in `increment()`, so a helper that fails to wait for the round
trip is actually caught), the page that renders it, and the route.

`LivewireTest` boots `php artisan serve` itself on an ephemeral port and shuts it down again.

## `phpunit-main/` and `mockery-main/` — the conformance oracles

`composer conformance` runs each fixture suite against **both** binaries and asserts identical
observable results, so it cannot run without them.

```bash
php conformance/install-oracles.php phpunit-main mockery-main
```

Or by hand, which is what that runs:

```bash
composer create-project phpunit/phpunit phpunit-main

git clone --depth 1 https://github.com/mockery/mockery.git mockery-main
cd mockery-main && composer install
```

Mockery is cloned rather than `create-project`'d, and that is not a style choice. Its
`.gitattributes` export-ignores `/tests` while its `autoload-dev.files` requires
`tests/Bootstrap.php`, so a dist install fatals inside its own generated autoloader before
PHPUnit starts — and the lane then reports as drift with every oracle outcome "(missing)"
rather than as a broken oracle, which is a genuinely misleading way to fail.

`composer create-project --prefer-source` also fixes it, but at a real cost: composer then
clones full history for *every dependency*, and the checkout comes out at 2.5 GB against
147 MB for the shallow clone above. Same conformance result either way.

**Every install command lives in `conformance/oracles-registry.php` and nowhere else.** The
skip messages, the installer and the CI workflow all read it. They did not always: the
command was written out in four places, correcting two of them left the CI workflow
installing mockery in the form that cannot start, and the lane then reported as drift
rather than as a broken oracle. One copy is the fix.

Kept solely to *run*. No source in either is a design input — see `DESIGN.md`.

---

## Why a fresh clone is still green

| Missing | Effect |
|---|---|
| `browser-oracle` | browser, Inertia and Livewire tests skip, named |
| `inertia-oracle/inertia.js` | Inertia tests skip, named |
| `livewire-oracle` | Livewire tests skip, named |
| `phpunit-main` / `mockery-main` | `composer conformance` cannot run; `analyse:oracles` skips its compatibility tier |
| `phpcpd-main` | the duplication check's tests skip, named |
| `livewire-oracle` | `analyse:oracles` skips its bridge tier |

A skip is reported, never silent. If you want the full suite proven locally, build all of the
above; otherwise `composer test` is green and honest about what it did not cover.

## What Crucible consumes from phpcpd-next

The duplication check is the one place Crucible calls into another tool's object graph, so the
surface it depends on is written down. It is eight members and nothing else:

```php
class_exists(Phpcpd::class)                       // the "is it installed" probe
Phpcpd::detect(paths:, minLines:, minTokens:, algorithm:,
               exclude:, suffixes:, preset:, fuzzy:, typeAnchored:): CodeCloneMap
CodeCloneMap::count(): int          CodeCloneMap::clones(): list<CodeClone>
CodeClone::files(): list<CodeCloneFile>          CodeClone::numberOfLines(): int
CodeCloneFile::$name                CodeCloneFile::$startLine
```

**The properties, not `name()`/`startLine()`.** phpcpd-next 2.0 drops those accessors from
`CodeCloneFile` while keeping the public readonly properties 1.4 already exposed, so reading the
properties binds against both. ✓ Checked in both trees.

**Why this is written down at all.** The break above would not have shown up in a smoke test:
`DuplicationCheck::locations()` only runs once the clone count exceeds the configured maximum, so
the check stays green through every clean run and fails at the exact moment it finally has
something to report. `testDuplicationIsPresentedAsFactsRatherThanAVerdict` reaches that path
deliberately, and it is the only reason the fix is verified rather than reasoned.

`detect()` is called with **named arguments**, so removing a parameter upstream is a hard break
even when the value would have been its default — and it is why 2.0's granted `defaultExcludes:`
cannot be passed while 1.4 is still supported. The full consumer contract — the six asks and their
status, what Crucible does *not* need built, and the proposed shape for the deferred ones — is
`PHPCPD.md`, taken in from phpcpd-next on 2026-09-03 so the consumer carries its own requirements.

## Static analysis needs them too

Some of Crucible's source compiles against packages it deliberately does not depend on —
the PHPUnit and Mockery compatibility surfaces, and the Laravel, Livewire and Pest bridges.
Requiring those packages to *analyse* Crucible would contradict the thing Crucible is, so
`composer analyse` excludes them and `composer analyse:oracles` analyses them instead,
taking their symbols from the oracle installs:

```bash
composer analyse:oracles
```

Each tier runs only when its oracle is present and is skipped by name otherwise, exactly
like the conformance lanes.

It is deliberately **not** part of `composer check`, for the same reason `composer
conformance` is not: a gate should mean the same thing on every machine. With the oracles
installed it analyses 1,407 more lines than without, and a check whose verdict depends on
what happens to be on disk is a weaker check than one that does not. Run it alongside
`composer conformance` when you have the oracles; CI builds all of them and runs both.

## What has already been proved, and against what

The oracles are hundreds of megabytes and none of them is needed to *use* Crucible, so the
point of building them is to prove something once rather than to make everyone carry them.
A record of that is only worth reading if it says which code was proved against, so:

```bash
php conformance/oracle-fingerprint.php
```

prints the identity of whatever is installed — a commit for a checkout, the tool's own
reported version and lock hash for a dist install, locked package references for an
application. Not a hash of the directory: those drift with timestamps, caches and vendor
layout, and two identical installs would compare unequal.

Proved on 2026-08-22, PHP 8.5.9, against every oracle at once:

```
phpunit-main 13.3.1 lock:2d40c0ccae11
mockery-main c6401d35bcd28bd997382f2dae3545355ee77865
livewire-oracle laravel/framework v13.26.1 e4a1bc52ef55
livewire-oracle livewire/livewire v4.4.1 0c925c55b4a5
livewire-oracle spatie/phpunit-snapshot-assertions 5.4.0 b5ad3efab36e
browser-oracle playwright 1.62.1
browser-oracle axe-core 4.13.0
inertia-oracle @inertiajs/core 3.6.1
php 8.5.9

fingerprint sha256:0d5b28d8eb5c5e69
```

| Proved | Result |
|---|---|
| `composer conformance`, all 19 fixtures | every fixture conforms, none skipped |
| `composer analyse:oracles`, compatibility tier | clean at level max |
| `composer analyse:oracles`, bridge tier | clean at level max, after 15 findings fixed |
| `crucible --filter Browser` | **59 of 59, nothing skipped** — real Chromium, a real Laravel serving real Livewire |
| `conformance/probes/21-coverage-xml/compare.php` | **25 of the incumbent's 25 XML shapes**, no divergence in either direction |
| `conformance/probes/22-report-formats/compare.php` | **every shape, all five formats** — Clover 10/10, OpenClover 10/10, Cobertura 11/11, Crap4J 20/20, JUnit 7/7 |
| `conformance/probes/23-report-values/compare.php` | **every number, all four formats** — Clover 9/9, Cobertura 6/6, Crap4J 5/5, JUnit 5/5 |
| `conformance/probes/24-teamcity/compare.php` | **every service message** — 10/10, no divergence either way |
| `conformance/probes/25-coverage-php/compare.php` | plain data, announces its format, agrees with the same run's XML |
| `conformance/probes/26-human-coverage/compare.php` | text, HTML and Clover agree, and match the incumbent — 3 of 7 |
| `conformance/probes/27-parallel-parity/compare.php` | every test's verdict identical sequential vs `--parallel 2`, both runs describing themselves the same |
| `conformance/probes/28-pest-matchers/compare.php` | **2,483 verdicts** — 44 zero-argument matchers over a 55-value corpus, plus 63 hand-picked rows — checked in both directions: Crucible against the record, and the record re-proved against a live Pest 5.1.1. **97 divergences are carried as known**, awaiting triage; a 98th fails the probe |
| `conformance/probes/30-extension-types/compare.php` | **147 fixture lines** — what the analyser knows after every narrowing assertion (three spellings, both forms) and every Pest matcher (chain and subject) — Crucible's PHPStan extension against a record of phpstan-phpunit 2.0.18 and pest-plugin-phpstan 5.2.1, the record re-proved against them live from `pest-oracle`. All agree on the chain; **20 lines named** where Crucible narrows the subject variable and the incumbent does not (D-128, D-129) |
| `tests/unit/Types/TypeExpressionAgreementTest.php` | **81 values** over the type-string grammar: Crucible's run-time reading of a PHPStan type against PHPStan's own (each value narrowed and dumped by the real phpstan). All agree (D-131) |
| `crucible --filter SarifSchema` | the SARIF report **validates against OASIS sarif-schema-2.1.0.json**, every outcome the driver declares a rule for exercised in one run |
| `conformance/probes/29-reproducible/compare.php` | JSON, SARIF and Clover **byte-identical across two `--reproducible` runs**, and varying without the flag — the control that stops the probe passing vacuously |
| `crucible` (full suite) | 1086 of 1088, the 2 skips xdebug's coverage mode |
| `crucible -d xdebug.mode=coverage` | **1088 of 1088, nothing skipped** |

The browser run found two things worth recording, because both were invisible without
the oracles: `assertNoAccessibilityIssues` *errored* rather than skipping when axe-core
was absent, and an incompletely-installed Livewire oracle made those tests **hang** rather
than fail. Both are fixed; the second is why an oracle's probe path is a file it cannot
work without rather than its directory.

The suite reports two different numbers because one capability is switched off by
default, not because a test is unreliable: xdebug ships in `develop` mode, and the two
tests that watch the coverage driver collect cannot run without `coverage` in it. Under
`-d xdebug.mode=coverage` nothing in the suite skips at all. Coverage mode instruments
every line, so it is a flag rather than a default — the plain run is the fast one, and
its two skips are the honest cost.

The five report formats CI actually parses had never been compared to anything, and
four of the five were wrong. Clover and OpenClover emitted no `<package>`, no `<class>`
and no method lines, and declared `loc="0"` for every file — a document that parses
cleanly and renders in Jenkins as a project containing no code. Cobertura's `<methods/>`
was always empty and its complexity always zero. JUnit carried no `assertions`, `file`,
`line` or `class`, so nothing could navigate from a CI report back to the test. None of
it was catchable by the writers' own tests, which assert what Crucible emits — the
question already answered.

**Shape parity is not value parity**, which is why probe 23 exists and asks the second
question separately. It opened with eleven recorded divergences and closed with none: all
eleven were defects, and every one lived in the coverage subsystem rather than in any
writer. A file's last line went uncounted when the file ended in a newline. A method's
closing brace counted as a statement, because xdebug reports it and nothing filtered it
back out. A method's range stopped at its last statement instead of its brace, so a body
that is only a comment reported itself untested — the token walk cannot see the brace,
since `token_get_all` hands back a bare string for `}` with no line attached. And a test
that never reached a verdict — skipped, incomplete, errored — still contributed its hits,
where the incumbent counts only tests that finished. A *failure* still counts: it reached
its assertion and disagreed with it, which is a complete measurement.

Together those made the same code report a 63.6% line rate where the incumbent reports
42.9%. Nothing else could have caught it: 1088 tests passed throughout, and all five
formats sat at perfect shape parity while the numbers inside them were wrong.

TeamCity started at **0 of 10** — nothing matched, because every message was missing the
`flowId` a reader needs to keep parallel runs apart, and the suite messages that group
tests under their class did not exist at all. It also carried no `locationHint`, so an IDE
could name a failing test but not open it. All ten agree now. Incomplete tests gained the
trace the incumbent carries for them, which is the one thing that separates a note about a
place in the code from a statement about the environment: a skip points nowhere, an
incomplete points at the line that said "not yet".

`--coverage-php` is the one report where matching the incumbent is the wrong goal, and its
probe says so rather than recording a divergence. The incumbent's file embeds a serialized
`SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData` — its own object, with
its own private properties. Writing objects under another library's class names so that
library's `unserialize()` accepts them is impersonation, not compatibility, and it breaks
the day a private property is renamed. What the probe asserts instead is what a consumer
actually depends on: the dump is plain data that needs no class of Crucible's to read, it
announces the format it is in, and it agrees with the other reports of the same run.

The two coverage reports a person reads had no probe either, and comparing them to the
incumbent's *layout* would have been the wrong question — those are presentations, and
Crucible's is deliberately its own. The number in them is not a presentation choice: until
the coverage fixes above, a developer reading the terminal saw 63.6% where the incumbent
said 42.9%. Probe 26 pins the property that would have caught it — every report of one run
states the same coverage, and it matches the incumbent to within the rounding each chooses.

The parallel half had nothing checking it at all: every probe here ran sequentially, and so
does the conformance harness, so the worker protocol's re-encode-ship-rebuild round trip
(D-022) was taken on trust. It had already cost something — the supervisor emitted
`run:start` without the plan size long after the sequential runner carried it, so a parallel
run reported no test count to anything reading it, and nothing failed because nothing
looked. Probe 27 compares the two streams test by test, and was verified by mutation:
reverting that fix makes it name the exact difference.

The XML comparison closed the same way. Five of its six gaps were work that had not been
done — the tokenized `<source>` dump, and the per-test outcomes the index carries, which
the coverage map cannot know because a line covered by a failing test is still covered.
The sixth was the build stamp's `phpunit` attribute, and that one was a decision: the
document already declares phpunit's schema, so the attribute says which release's format
it conforms to, from the compatibility statement Crucible already makes. It is a format
declaration, not a claim of lineage.

A different fingerprint does not mean something is wrong — it means the claim above was
made against other code, and is worth re-running rather than trusting.
