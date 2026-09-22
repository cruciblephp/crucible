# Crucible PHP — Research Inputs

> Verified design inputs for the engine: primary sources fetched and checked, not recalled
> from memory. Each section closes with the concrete consequences for Crucible and the phase
> they land in. Companion document: `DESIGN.md` (decisions taken).
>
> Provenance note: everything here is *design knowledge* — formats, algorithms, published
> results, documented public APIs. The clean-room rule (no code copied from anything)
> is unaffected.

## 1. Systems test runners and machine-readable output

_Verified 2026-07-14 against nexte.st docs, pkg.go.dev, testanything.org, nodejs.org._

### cargo-nextest (0.9.140, 2026-07-05)

- **Execution model**: list phase (enumerate tests per binary), then run phase — one
  **process per test**, parallel, results aggregated centrally. Works because Rust test
  binaries are cheap to exec; the *supervision* semantics are the transferable part.
- **Retries/flaky**: `--retries N` + config `retries = {count, backoff: fixed|exponential,
  delay, max-delay, jitter}`; a test passing after failing is a distinct **FLAKY** status;
  `flaky-result = pass|fail` sets exit-code policy (default: flaky = success); per-test
  overrides via a filterset DSL. JUnit output marks `<flakyFailure>`/`<rerunFailure>`.
- **Sharding**: `--partition hash:m/n` — deterministic hash of binary id + test name, so
  adding/removing tests never moves others between buckets; `slice:m/n` for even
  distribution; round-robin `count:` is deprecated (neither stable nor even).
- **Slow tests / signals**: `slow-timeout` marks SLOW without killing; `terminate-after = k`
  periods → SIGTERM to the test's **process group** → grace period (10s default) → SIGKILL.
  SIGINT/SIGTERM forwarded to all process groups; SIGTSTP pauses running timers so a
  suspended run doesn't trip timeouts.
- **Machine-readable**: JUnit XML is the stable mechanism; libtest-json is experimental;
  a first-class NDJSON run format is planned, unshipped.

### Go `test2json` (`go test -json`)

Newline-delimited `TestEvent`: `Time` (RFC3339), `Action` (`start run pause cont pass bench
fail output skip`), `Package`, `Test`, `Elapsed` (float seconds), `Output`, `FailedBuild`
(Go 1.24+, links build breakage to test failure). Guarantees: stream begins with `start`;
unbuffered; **concatenation of `Output` events = exact test output**.

### TAP 14

Plan `1..N`, test points `ok/not ok`, `# TODO`/`# SKIP` directives, **YAML diagnostic
blocks** (`---`…`...`, indented) for structured failure detail, nested subtests via
indentation, `Bail out!`.

### Node.js built-in runner (stable since v20)

Per-file child processes by default, `isolation: 'none'` opt-out. Built-in reporters
(`spec`, `tap`, `dot`, `junit`, `lcov`) with an explicit warning **not to parse their
output** — the programmatic contract is the `TestsStream` event stream (`test:start`,
`test:pass`, `test:fail`, `test:plan`, `test:diagnostic`, `test:stdout`, `test:coverage`,
`test:summary`, …), consumable by multiple reporters simultaneously.

### Rust stabilization lesson

eRFC 3558 "libtest-json" (2024) + a 2025H2 project goal exist because tooling grew to
depend on *unstable, unversioned* JSON output. Version the format from day one.

### Consequences for Crucible

| Decision area | Consequence | Phase |
|---|---|---|
| Event stream | NDJSON, one event per line, **versioned envelope** `{"ver":1,"event":…,"ts":…,"seq":…}`; events `run:start`, `suite:start/finish`, `test:start`, `test:pass/fail/skip/todo`, `test:output` (chunked; concatenation = exact output), `test:slow`, `test:retry` (attempt no.), `run:interrupted`, `run:summary`; failures carry structured `error{message, diff{expected,actual}, trace[]}`; stream starts with `run:start`, unbuffered | 1 |
| Test identity | Stable dialect-neutral ids (D-008); hash-shardable | 1 |
| Process model | **Persistent PHP worker pool** (PHP startup/autoload makes process-per-test wasteful) + nextest supervision: per-test process groups, SLOW marking without kill, SIGTERM→grace→SIGKILL, worker recycling after crash/leak, opt-in process-per-test mode; SIGTSTP pauses timers | 6 |
| Retries | nextest semantics: distinct FLAKY status, backoff config, `flaky-result` exit policy, per-attempt events | G4 |
| Sharding | `--partition hash:m/n` (stable) + `slice:m/n` (even); no round-robin | G1 |
| CI interop | JUnit XML emitter with flaky-run markup | 8 |

## 2. JS ecosystem (Vitest, Jest, Bun)

_Verified 2026-07-14 against vitest.dev, jestjs.io, github.com/vitest-dev + /jestjs, bun.com._

### Vitest (4.1, 2026-03)

- **Pools**: per-file workers; `forks` default (compat over speed), `threads`, `vmThreads`;
  isolation opt-out per project. v4 **removed tinypool for a custom in-house pool**
  (PR #8705): tinypool couldn't share one resource budget across pools (workspace runs
  over-allocated on small machines), nor mix per-task isolation flags. Lesson: one global
  `maxWorkers` budget + per-task isolation flags, designed in from day one.
- **Watch / impact selection**: module graph falls out of Vite's transform step (lazy —
  the anti-haste-map lesson); `--changed[=gitref]`, `--related <files>` (static import
  analysis; dynamic imports invisible), `--standalone` (warm process, run on change).
  Interactive keys `a f u p t q h`. Known gap: failed-only mode (`f`) doesn't persist
  across file changes (issue #10247).
- **Reporter API (v3+)**: typed lifecycle methods (`onTestRunStart`,
  `onTestModuleQueued/Collected/Start/End`, `onTestSuiteReady/Result`,
  `onTestCaseReady/Result`, `onHookStart/End`, `onTestRunEnd(modules, errors, reason)`)
  receiving immutable `TestModule/TestSuite/TestCase` objects. v4.1 added a GitHub
  job-summary reporter and a token-minimal **agent reporter** (for AI coding agents).
- **Snapshots**: standalone package; `SnapshotClient` + pluggable environment; inline
  snapshots via stack-trace location + code rewrite; serializer shared with diff output.
- **Config**: `projects` (globs/files/inline; explicit `extends: true`, no silent merge;
  root-only options; `--project` filter). 4.1: **tags** carrying shared options (retries,
  timeouts) + boolean filter language; `--shard=1/3` with blob reporter + `--merge-reports`.

### Jest lessons

- **Discarded by Vitest**: jest-haste-map — upfront whole-repo crawl + in-memory module
  map, built for Facebook-scale monorepos; brings crawl cost and name collisions. Derive
  the graph lazily instead.
- **Impact flags** (doc-quoted): `--onlyChanged` "requires a static dependency graph (no
  dynamic requires)"; `--findRelatedTests <files>` for pre-commit hooks; `--changedSince`.
- **jest-diff pipeline**: pretty-format serialize → line diff (diff-sequences) → colorize;
  strings get intra-line changed-substring highlighting (inverse color); Expected/Received
  annotations, context collapsing, change counts; short-circuits when serializations are
  equal ("looks equal, differs by identity/type").

### Bun

Runner built into the runtime: no per-file bootstrap, native transpilation, native
`expect()`. Sequential single-process by default; opt-in concurrency. Trades isolation
for raw speed — the opposite default from Vitest/Node; useful as the "no-isolation mode"
data point, not as the default.

### Consequences for Crucible

| Decision area | Consequence | Phase |
|---|---|---|
| Dependency graph for impact selection | No transform hook in PHP → merge two sources: static analysis (use/extends/implements/attributes via autoloader classmap; documented blind spot: dynamic container lookups) + per-test covered-files maps (pcov) cached incrementally; invert to file→tests | G3 |
| Watch UX | `--changed[=ref]`, `--related`, `--standalone`; keys `a f u p t`; make failed-only **sticky** (fix Vitest's gap); failed-first ordering every re-run | G3 |
| Reporter API | Vitest v3 shape: typed lifecycle methods over immutable value objects; ship default/tree/dot, JUnit, GH annotations + job summary, blob+merge for shards, and an **agent reporter** (token-minimal) | 8 |
| Failure diffs | jest-diff pipeline on one canonical PHP-value serializer (shared with snapshots): line diff + intra-line substring highlight, Expected/Received, context collapse, identity-vs-equality short-circuit | 2 |
| Scheduler budget | One global worker budget + per-task isolation flags (the tinypool failure) | 6 |
| Config | `projects` with explicit inheritance + root-only options; tags carrying shared options + boolean filter | 7/G1 |

## 3. Pest public API as a second spec

_Verified 2026-07-14 against pestphp.com/docs. Full inventory: [`spec/pest-api.md`](spec/pest-api.md)._

Pest is at **v4** (4.7.5, 2026-07-06), MIT, PHP 8.3+, implemented as a PHPUnit wrapper —
its CLI inherits most PHPUnit options, which Crucible's phpunit-spec CLI already covers.

### Pest 5 (5.0.2, released after the above) — verified 2026-08-01 by installing the real
package in a throwaway scratch project and observing `--help` output plus the JSON
artifacts it writes; no Pest source read (same clean-room rule, same method already used
for PHPUnit — public interface + black-box behavior only). `spec/pest-api.md` still
targets v4; a full v5 dialect-surface refresh is separate, larger follow-up work, not
done here. Bundles real phpunit/phpunit **^13.2** — same major Crucible already targets.

- **Tia (`--tia`)**: needs pcov or Xdebug (`XDEBUG_MODE=coverage`) — with neither, the run
  prints "TIA... skipped" and silently falls back to a full run. Coverage-only; no static
  analysis fallback. The graph is a single JSON file **outside the project**, at
  `~/.pest/tia/<project-name>-<hash>/graph.json`, containing: a `fingerprint` (hashes of
  `composer.lock`/`phpunit.xml`/`vite`/`package.json` + PHP minor version) that
  self-invalidates the whole graph on environment drift; `files`→`edges` (file → covered
  source, index-encoded); and `baselines`, keyed **per git branch**, each holding the
  commit SHA it was recorded at plus a **full stored result per test** (status, message,
  time, assertion count). That stored-result store is how "10 min → 4 sec" actually
  happens: unaffected tests aren't skipped, their last-known result is *replayed* into the
  report, so the run still looks and reports like a full suite. `test_tables`/
  `test_inertia_components`/`js_file_to_components` (empty in a plain-PHP probe) show the
  graph also tracks DB-table and Inertia/JS-component edges, not just PHP coverage.
  `--tia --baselined`/`--refetch` fetch a **shared graph recorded by CI** instead of
  building one locally — a team-scale mode Crucible has no equivalent of yet.
- **PHPStan plugin** (`pestphp/pest-plugin-phpstan`, separate package, same
  `extra.phpstan.includes` wiring Crucible's own D-049 extension already uses):
  extension.neon inventory (declarative service list, not implementation) shows two
  halves. Type-narrowing/reflection half — expect()-chain narrowing, `$this` inside test
  closures, higher-order chain reflection, dynamic property typing — is matched or
  exceeded by Crucible's D-049 extension already, and D-049 covers *both* dialects (assert
  narrowing, PBT generator typing, Mockery reflection), not just Pest's expect(). The real
  gap is the **lint-rule half**: Pest ships ~11 test-authoring-mistake rules (duplicate
  test descriptions, empty test closures, impossible/redundant expectations, `$this` used
  in `beforeAll`, invalid group names, redundant local `uses()`, static test closures,
  describe blocks with no tests inside, disallowed calls inside `describe()`); Crucible
  ships one (`PropertyParameterRule`). None of these are dialect-specific in spirit —
  "empty test body" and "duplicate test description" apply just as much to the PHPUnit
  dialect — so a Crucible rule set could cover both and be the broader win.
- **Everything else in the release** (Agent Plugin single-verify-command +
  Browser-plugin driving, Evals scoring LLM output through `expect()`, Rector rules that
  convert raw assertions to matchers, Time-Balanced Sharding's `--update-shards`) is
  noted from `--help`/the release description only, not investigated further — lower
  priority per this round's ask. Time-Balanced Sharding is the one worth flagging as
  "already ahead, not behind": Crucible's Graham-LPT sharding (§4) already schedules by
  recorded duration, which is the same goal Pest's feature is reaching for from a
  count-based baseline.

### Consequences for Crucible

| Decision area | Consequence | Phase |
|---|---|---|
| G2 scope split | Core dialect = test()/it()/describe(), expect() (70+ matchers + modifiers + extend/intercept/pipe), hooks/Pest.php semantics, datasets (named/bound/lazy/Cartesian). Extended surfaces (arch, mutation, stress, browser, type coverage) are separate growth decisions | G2 |
| Shared engine features | Pest's snapshots, sharding (`--shard` + time-balanced `--update-shards`), flaky retries (`--flaky`), `--dirty` (git-aware selection) overlap Crucible's planned native capabilities — build once in the engine, expose through every dialect | 6, G1, G3, G4 |
| Constraint bridge | `toMatchConstraint` accepts a PHPUnit constraint — confirms expect() must compile onto the same constraint engine as assert*() (D-008) | 2, G2 |
| Datasets > data providers | Pest's dataset semantics (bound closures resolved after beforeEach, Cartesian combination, per-folder scoping) are a superset of PHPUnit data providers — the dialect-neutral TestDefinition must model parameterization richly enough for both | 4 |
| `--ci` / focus / todo | Focus mode (`only()`) + `--ci` ignoring it, todo metadata (assignee/issue) — cheap engine-level features surfaced per dialect | G2 |
| Impact selection vs. Tia | Crucible's `--changed`/`--dirty` floor (static analysis, works with zero setup) is already stricter than Tia's (coverage-only, silently full-runs without pcov/Xdebug). Worth harvesting, better than copying: (1) a structural fingerprint (composer.lock/crucible.php/PHP-minor hashes) that auto-invalidates the graph on environment drift instead of trusting the caller to know when to force a full run; (2) cached-result replay so a `--changed` run reports full-suite-shaped output, not just the affected subset; (3) a shared/CI-recorded-baseline fetch mode for team-scale use | G3 |
| PHPStan integration vs. Pest's plugin | D-049 already matches/exceeds Pest's type-narrowing half across both dialects. The gap is lint rules: build a dialect-spanning set covering duplicate test descriptions, empty test bodies, impossible/redundant expectations or assertions, misplaced `$this`, invalid group names, redundant local `uses()` — same category as Pest's ~11 rules but not Pest-only | D-049 follow-up |
| Pest 5 compat surface | Bundles phpunit/phpunit ^13.2 (Crucible already targets that major); CLI additions worth tracking as they get investigated further: `--retry` (have retries), `--dirty` (have it), `--tia` (see impact row above), `--update-shards` (already ahead via Graham-LPT). `--ai`, Agent Plugin, Evals, and the Rector-rules package are product surface, not dialect syntax — no forced compat action, deferred | G1/G2 |

## 4. Academic literature

_Citations verified 2026-07-14 against publisher pages, author PDFs, dblp, project sites._

### Verified results

| Line of work | Citation | Verified result | For Crucible |
|---|---|---|---|
| Test prioritization | Rothermel, Untch, Chu, Harrold, IEEE TSE 27(10), 2001; Elbaum, Malishevsky, Rothermel, IEEE TSE 28(2), 2002; cost-aware APFD_c: ICSE 2001 | Coverage/history-greedy orderings significantly improve fault-detection rate (APFD); APFD_c weights by test cost — "fast tests first, recently-failed first" is principled | Phase 6: `defects` ordering = recency-weighted failure history, duration tie-break |
| Test selection | Ekstazi: Gligoric, Eloussi, Marinov, ISSTA 2015 (Distinguished Paper); HyRTS: Zhang, ICSE 2018 | File/class-level checksum tracking: **32% avg end-to-end time reduction** on 615 revisions/32 projects; coarse granularity wins because overhead is low. HyRTS (hybrid file+method) beats file-level on 2,707 revisions | G3: Ekstazi's file-level design maps ~1:1 onto PHP autoloading; HyRTS only if file-level proves too coarse |
| Flaky tests | Luo, Hariri, Eloussi, Marinov, FSE 2014; DeFlaker: Bell et al., ICSE 2018; iDFlakies: Lam, Oei, Shi, Marinov, Xie, **ICST** 2019; survey: Parry et al., ACM TOSEM 31(1), 2021; Google (Micco 2016): ~16% of tests show flakiness, ~1.5% of runs | Async wait + concurrency + order dependency = **78%** of flaky tests; DeFlaker (failure that executed no changed code → flaky): **95.5% recall, 1.5% false alarms, no reruns**; iDFlakies: **50.5%** of flaky tests are order-dependent | G4: seeded random order + replay, DeFlaker-style "did the failure touch the diff?" check, quarantine — all deterministic |
| Isolation cost | VMVM: Bell & Kaiser, ICSE 2014 (Distinguished Paper); PolDet: Gyori, Shi, Hariri, Marinov, ISSTA 2015 | In-process reset of only the polluted state: **avg 62% (up to 97%) suite-time reduction** vs process-per-test, same fault-finding; PolDet found 324 polluting tests / 6,105 | Phase 6: cheap snapshot/diff state reset as default, process isolation the exception; PHP's mutable global surface ($GLOBALS, statics, env) is enumerable — unusually tractable |
| Property-based testing | QuickCheck: Claessen & Hughes, ICFP 2000; Hypothesis: MacIver & Hatfield-Dodds, JOSS 4(43), 2019; internals: MacIver & Donaldson, **ECOOP 2020** | Choice-sequence architecture: generation records a byte sequence; shrinking regenerates from smaller sequences — every shrunk example valid by construction, generator-agnostic, deterministic replay + failure database | G5: copy the ECOOP 2020 design, not classic QuickCheck shrinkers |
| Mutation practicality | PIT: Coles et al., ISSTA 2016 + pitest.org docs | Cheap because: per-test line coverage limits which tests run per mutant (fastest first), persistent workers, incremental cache | Engine prerequisites land in Phases 6 + G3; mutation itself stays third-party-friendly (event stream + worker API) |
| Scheduling | Graham, SIAM J. Appl. Math 17(2), 1969 | LPT guarantees makespan ≤ (4/3 − 1/(3m)) × optimal | Phase 6 workers + G1 sharding: greedy, deterministic, near-optimal |
| ML contrast case | Meta predictive selection: Machalica et al., ICSE-SEIP 2019 | ML halved infra cost at >95% failure recall — but non-deterministic | Confirms the declined-ML stance: Crucible's determinism principle forgoes this trade |

### Priority ranking (deterministic, single-machine-first)

1. Failure-history + cost-aware prioritization — near-zero cost, wins on every run (Phase 6)
2. VMVM/PolDet cheap isolation + pollution detection — biggest measured single-machine speedup (Phase 6)
3. Ekstazi-style file-level selection (G3)
4. Seeded random order + DeFlaker/iDFlakies flaky classification (G4)
5. Graham LPT sharding by recorded duration (Phase 6/G1)
6. PIT-style mutation infrastructure — depends on 1–3 (post-G3)
7. Choice-sequence PBT — differentiator, not runner-core (G5)

_Noted as unverified: HyRTS exact speedup percentages; predictive-mutation accuracy figures._

## 5. PHP prior art — harvest (independent runners & paradigms)

_Verified 2026-07-14 against project repos/docs. Semantics harvested from documentation only._

| Framework | Status | Harvest (→ Crucible area) |
|---|---|---|
| **atoum** | **ARCHIVED 2026-07-02** (final 4.4.1, BSD-3) | Typed asserter inheritance tree (→ expect() surface, 2/G2); named execution engines `inline`/`isolate`/`concurrent` with per-test override — Crucible's VMVM reset is the missing middle tier (→ 6); **loop mode**: on failure replay only failed tests, then auto-rerun full suite once green (→ G3 watch); namespace-shadowing function mocks as the zero-cost doubles tier (→ 5) |
| **Kahlan** | Active (6.1.0, 2026-01, MIT) | JIT source instrumentation at Composer autoload — patches methods and `new`, documented hard limits (no final classes, no manually-included files) worth copying verbatim as the honest boundary statement (→ 5); single-verb `allow()` DSL, strategy inferred from target — far lower cognitive load than the createMock/willReturn/expects triangle (→ 5); coverage driver/exporter separation (→ 8) |
| **Patchwork** | Active-maintenance (2.2.3, MIT) | Two-tier instrumentation (rewrite userland via stream wrapper; special-case internals/`exit`); explicit "prohibitively slow for production" cost model → instrumentation is test-only, per-file opt-in; magic-constant hazards documented; **option: depend on it (MIT) as the optional deep-doubles backend** rather than writing one (→ 5) |
| **Peridot** | Dead (2017) | **Complete event taxonomy** for the kernel vocabulary — incl. `suite.halt` (cooperative cancellation) and `suite.define` vs run-time distinction, both easy to forget (→ 1); config file that receives the event emitter — custom reporting without a plugin API (→ 1/7) |
| **Codeception** | Active (5.3.5, 2026-02, MIT) | Actor/module capability composition with generated typed helper surface (→ far-future dialect/plugin idea); per-suite modules/bootstrap/env with `--env` matrix (→ 7 config profiles); verdict: survives by *wrapping* PHPUnit — compatibility beats superiority. **A Cest dialect is DECLINED, not deferred** (2026-07-22): the D-070 demand probe ran and came back negative — Codeception itself is healthy (91M installs), but Cest is inseparable from the module framework (Db/REST/WebDriver) that Crucible will not provide, so "Cest on Crucible" has no addressable audience. Do not re-open on install counts alone |
| **Behat** | Active (3.32; 4.0-alpha 2026) | Gherkin dialect frontend is **feasible, moderate effort**: attribute-bound steps (`#[Given(':count monsters')]`) map 1:1 onto the D-008 frontend model; scenario-per-fresh-context = existing isolation model; `--append-snippets` "generate the missing thing" UX worth stealing everywhere (→ CLI) |
| **PHPSpec** | Active (8.3.1; 9.0-beta targets PHP 8.5, MIT) | **The field's best doubles semantics**: one double, three verification timings — `willReturn` (stub) / `shouldBeCalled` (mock) / `shouldHaveBeenCalled` (spy) (→ 5); auto-doubled collaborators injected by type-hint — pairs with PHP 8.4 lazy objects (→ 5); `shouldThrow(...)->during(...)` composition (→ 2) |
| **SimpleTest** | Historical | Nothing to harvest |

**Top harvested ideas** (agent's ranking): 1. PHPSpec's stub/mock/spy timing model; 2. Peridot's event taxonomy (`suite.halt`, define-vs-run); 3. atoum's failed-first loop with full-suite regression escalation; 4. Kahlan's `allow()` single-verb DSL; 5. type-hint auto-doubles via lazy objects; 6. atoum's named engines + per-test isolation override; 7. Patchwork as optional instrumentation dependency; 8. event bus exposed in config; 9. Behat attribute step-binding + snippet generation; 10. atoum's typed asserter tree.

**Adoption lesson (now complete with atoum's archival):** every independent runner that demanded migration is dead or niche (atoum archived, Peridot dead, PHPSpec capped); survivors wrap the incumbent (Codeception), serve a different audience (Behat), or own one irreplaceable capability (Kahlan/Patchwork). Parity-first drop-in compatibility is the moat none of them built.

## 6. PHP companion tooling — harvest

_Verified 2026-07-14 against project repos/docs. Semantics harvested from documentation only._

| Tool | Status | Harvest (→ Crucible area) |
|---|---|---|
| **ParaTest** | 7.23.0, 2026-06, MIT; tracks only latest PHPUnit (depends on `@internal` classes) | v7 deleted process-per-class; **WrapperRunner (persistent workers) is the only model** — validates Crucible's pool as default (→ 6). **`TEST_TOKEN`** (stable per-worker) + **`UNIQUE_TEST_TOKEN`** (per run+process) must be byte-compatible — Laravel and userland fixtures key off them (→ G1). Documented footguns Crucible eliminates: once-per-suite init runs per-process (add explicit **once-per-run hook**), cross-class static state (VMVM reset). `--max-batch-size` = worker recycling policy (→ 6). Coverage merging is its fragile zone (three 2026 bugfix releases) — central per-worker coverage-stream merge is cheap credibility (→ 6/8) |
| **Mockery** | 1.6.12 (2024-05!), BSD-3; repo alive but **no stable release in 2+ years**; PHP 8.5 support unverified | The de-facto doubles spec Laravel builds on (`$this->mock/partialMock/spy`) — release-stalled = real opening for a compatible implementation (→ 5, third spec candidate). Harvest: `allows`/`expects` stub-vs-mock verbs; matcher algebra incl. `capture($var)` (no PHPUnit equivalent); global cross-mock ordering; proxied partials that fail typehints — **lazy objects make them typehint-safe**; alias/overload static mocks need `#[RunInSeparateProcess]` — **VMVM isolation makes them safe without a process**, Mockery's worst pain solved (→ 5, 6) |
| **Prophecy** | 1.26.0, 2026-02, MIT, PHP ≤8.5 | Unified model: dummy/stub/mock *emergent* from configuration of one prophecy (→ 5). PHPUnit dropped bundled integration (dependency entanglement + low adoption): **lesson — never bundle a doubles library; be a good host** (teardown hook ordering, assertion counting for `ProphecyTrait`) (→ 4/5) |
| **Symfony PHPUnit Bridge** | 8.1.1, 2026-06, MIT; strategically narrowed for PHPUnit 10+ | **Deprecation grammar goldmine, now orphaned**: self/direct/indirect/legacy attribution (by *who called* the deprecated code), per-group `max[]` thresholds, `baselineFile`+`generateBaseline`, `ignoreFile`, `quiet[]`, legacy-test group exclusion — PHPUnit 11+ native handling is weaker (no attribution, no per-group thresholds). Crucible ships the superset natively (→ 8). **ClockMock** (time/sleep/hrtime… via namespace shadowing, `#[TimeSensitive]` opt-in, `\time()` caveat), **DnsMock**, **ClassExistsMock** — enterprise-proven hermetic mocking as opt-in attributes (→ 5/6) |
| **Infection** | 0.34.0, 2026-06, BSD-3, PHP ^8.3 | Pipeline is CLI-workaround archaeology: rigid XML-coverage+JUnit two-artifact contract, bespoke phpunit.xml per mutant, fresh process each time. Kernel APIs that make an Infection-class tool ~10x cheaper: **queryable per-test/per-line coverage+timing index** (→ 1), **persistent-worker re-run API** (swap code, ordered test list fastest-first, stop-on-first-fail, structured verdict killed/escaped/error/timeout) (→ 6), killer-agnostic mutant chain, MSI metrics vocabulary. Pest built mutation *in-framework* — proof of demand for first-class hooks (→ mutation surface) |
| **PHPBench** | 1.7.0, 2026-06, MIT | Measurement vocabulary: revs vs iterations vs warmup; **retry-threshold** (reject unstable iteration sets; "rstdev >2% is suspicious"); report **mode+rstdev, not mean** (mean is noise-dominated) — bake into event-stream timing fields (→ 1) and `--profile`/flakiness discrimination (→ G4); baseline-relative perf assertions with tolerance = ready spec for a perf growth step |
| **PHPT** | Alive in php-src/PECL; PHPUnit 13 still auto-discovers; userland usage ≈ nil | **Skip decision: no phpt dialect at parity tier.** Recognize `.phpt`, fail with a clear message; optional dialect package later only on demand. EXPECTF specifiers overlap `assertStringMatchesFormat`, needed anyway (→ 2) |
| **Laravel testing layer** | Laravel 13.x; Pest and PHPUnit first-class | Thin sugar over exactly **three contracts: PHPUnit assertions/TestCase, ParaTest tokens, Mockery** — parity on those three makes G1 mostly free. Must trigger `ParallelTesting` lifecycle: `setUpProcess(token)`, `setUpTestCase`, `setUpTestDatabase(db, token)`, teardowns, `token()`. `artisan test` adds pretty output, `--coverage --min`, `--profile`, `--recreate-databases`; Laravel 13: `#[UnitTest]`, `#[Seed]`. DB traits spectrum (RefreshDatabase transaction-wrap ⇒ events must not assume cross-connection visibility). Time travel = `Carbon::setTestNow()` sugar — complements, doesn't replace, ClockMock (→ G1) |

**Top harvested ideas** (agent's ranking): 1. per-test coverage+timing query API in the kernel; 2. byte-compatible TEST_TOKEN/UNIQUE_TEST_TOKEN + ParallelTesting-shaped hooks; 3. native superset of SYMFONY_DEPRECATIONS_HELPER; 4. persistent-worker mutant re-run API; 5. isolation-safe alias/overload static mocks; 6. once-per-run vs once-per-worker hooks + recycling policy; 7. Mockery-compatible doubles spec; 8. mode/rstdev/retry-threshold timing statistics; 9. ClockMock/DnsMock/ClassExistsMock as opt-in attributes; 10. engine-side coverage merge.

_Unverified flags: exact ParaTest CLI flag list; Infection's PHPUnit ceiling; "Pest default in Laravel 13" (secondary sources); LazilyRefreshDatabase in 13.x docs. Resolved 2026-07-16: Mockery 1.6.12 **runs on PHP 8.5.7** (probed black-box, see spec/mockery-api.md) — core paths clean, but readonly classes fatal and API-name collisions fatal; the stalled release predates 8.5 features._

## §7 Report design (harvested for D-068's PDF and future report surfaces)

Design inputs for human-facing report documents — typography and layout canon, plus the
test-report state of the art (studied for structure, never copied):

| Source | What it settles |
|---|---|
| **Booktabs manual** (Simon Fear, "Publication quality tables in LaTeX") | The print-table canon: no vertical rules inside data tables, few heavy horizontal rules, hierarchy from spacing and weight — the reason the result tables dropped their grid in favor of header bands + zebra |
| **Stephen Few, *Show Me the Numbers*** | Scanning order for tabular status data: status first, identity second, measures right-aligned at the edge — the # / Outcome / Test / Time column order |
| **Tufte, *Visual Display of Quantitative Information*** | Data-ink ratio: every rule and fill must earn its ink; zebra stripes only as light as needed to track rows |
| **Butterick's Practical Typography** | Point sizes, line spacing, margins for A4 documents |
| **Allure Report / pytest-html / ReportPortal / Maven Surefire HTML** | The genre's conventions: numbered rows, status badge leading each row, per-suite tallies, failure details grouped and numbered — conventions readers already know, so the PDF follows them |
