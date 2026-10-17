# Crucible PHP — Research

This is the study behind Crucible's design. Before deciding how the engine should run tests,
report them, select them and isolate them, we looked at how others had answered the same
questions: runners in other languages, the JavaScript tools, Pest, the research literature, and
the PHP runners that came before. `DESIGN.md` records the decisions. This document records what
they were based on.

**How it was done.** Every claim comes from a primary source fetched and checked on the date
given in its section: documentation, published papers, release notes, or the observable
behaviour of an installed package. Nothing was taken from memory. Where a package was studied by
running it, only its public interface and its behaviour were used, never its source. That is the
same clean-room rule the engine follows: these are formats, algorithms and published results,
and no code from any of them is in Crucible.

**How to read it.** Each part asks one question, describes what was examined and what was
found, then says what Crucible did with it. The tables at the end of each part list those
consequences with the build phase they landed in. Where Crucible chose not to follow a source,
or a plan changed after the study, the part says so.

## 1. How do mature runners supervise tests and report results?

_Checked 2026-07-14 against nexte.st, pkg.go.dev, testanything.org and nodejs.org._

The question was what a test runner's process model and output format should be, judged by
runners that have lived with theirs for years.

### cargo-nextest (0.9.140, 2026-07-05)

- **Process model.** A list phase enumerates the tests in each binary, then a run phase starts
  **one process per test**, in parallel, and collects the results centrally. That works because a
  Rust test binary is cheap to start. What carries over to PHP is the supervision, not the
  process-per-test cost.
- **Retries and flakiness.** `--retries N`, configured as `retries = {count, backoff:
  fixed|exponential, delay, max-delay, jitter}`. A test that fails and then passes gets its own
  **FLAKY** status, and `flaky-result = pass|fail` decides what that does to the exit code (by
  default flaky counts as success). Per-test overrides use a filter language. JUnit output marks
  `<flakyFailure>` and `<rerunFailure>`.
- **Sharding.** `--partition hash:m/n` assigns a test by a deterministic hash of its binary id and
  name, so adding or removing tests never moves the others. `slice:m/n` distributes evenly.
  Round-robin `count:` is deprecated, being neither stable nor even.
- **Slow tests and signals.** `slow-timeout` marks a test SLOW without killing it.
  `terminate-after = k` periods sends SIGTERM to the test's **process group**, waits a grace
  period (10 s by default), then sends SIGKILL. SIGINT and SIGTERM are forwarded to every process
  group, and SIGTSTP pauses the timers so a suspended run doesn't trip its own timeouts.
- **Machine-readable output.** JUnit XML is the stable mechanism. libtest-json is experimental, and
  a first-class NDJSON run format is planned but not shipped.

### Go `test2json` (`go test -json`)

A stream of newline-delimited `TestEvent`s: `Time` (RFC 3339), `Action` (`start run pause cont
pass bench fail output skip`), `Package`, `Test`, `Elapsed` (seconds, as a float), `Output`, and
since Go 1.24 `FailedBuild`, which ties a build failure to the tests it broke. The stream always
begins with `start`, is unbuffered, and **concatenating the `Output` events gives the test's
exact output**.

### TAP 14

A plan line `1..N`, test points `ok` / `not ok`, `# TODO` and `# SKIP` directives, **YAML
diagnostic blocks** (`---` … `...`, indented) for structured failure detail, nested subtests by
indentation, and `Bail out!`.

### Node.js built-in runner (stable since v20)

Each file runs in its own child process by default, with `isolation: 'none'` to opt out. The
built-in reporters (`spec`, `tap`, `dot`, `junit`, `lcov`) come with an explicit warning **not to
parse their output**. The programmatic contract is the `TestsStream` event stream (`test:start`,
`test:pass`, `test:fail`, `test:plan`, `test:diagnostic`, `test:stdout`, `test:coverage`,
`test:summary`, …), which several reporters can consume at once.

### A lesson from Rust's stabilisation

eRFC 3558 "libtest-json" (2024) and a 2025H2 project goal both exist because tools grew to depend
on JSON output that was unstable and unversioned. The lesson is to version the format from the
first release.

### What Crucible took

| Area | What Crucible does | Phase |
|---|---|---|
| Event stream | NDJSON, one event per line, in a **versioned envelope** `{"ver":1,"event":…,"ts":…,"seq":…}`. Events: `run:start`, `suite:start/finish`, `test:start`, `test:pass/fail/skip/todo`, `test:output` (chunked; concatenation gives the exact output), `test:slow`, `test:retry` (with the attempt number), `run:interrupted`, `run:summary`. Failures carry structured `error{message, diff{expected,actual}, trace[]}`. The stream starts with `run:start` and is unbuffered | 1 |
| Test identity | Stable, dialect-neutral ids (D-008) that can be hashed for sharding | 1 |
| Process model | A **persistent pool of PHP workers**, since PHP's startup and autoloading make a process per test wasteful, supervised the way nextest supervises: per-test process groups, SLOW marking without a kill, SIGTERM then a grace period then SIGKILL, recycling a worker after a crash or a leak, an opt-in process-per-test mode, and timers paused on SIGTSTP | 6 |
| Retries | nextest's semantics: a distinct FLAKY status, backoff configuration, an exit-code policy for flaky tests, and an event per attempt | G4 |
| Sharding | nextest's stable hash partition (D-028). Even slicing was not built: a hash keeps every other test where it was when tests are added or removed, and that stability was chosen over balance | G1 |
| CI | A JUnit XML writer with the flaky-run markup | 8 |

## 2. What has the JavaScript ecosystem learned?

_Checked 2026-07-14 against vitest.dev, jestjs.io, github.com/vitest-dev, github.com/jestjs and
bun.com._

The JavaScript runners have been through more redesigns than any PHP runner, so the question
was what they kept and what they threw away.

### Vitest (4.1, 2026-03)

- **Worker pools.** Workers run per file, with `forks` as the default (compatibility over speed),
  plus `threads` and `vmThreads`, and isolation can be turned off per project. Version 4
  **replaced tinypool with a pool of its own** (PR #8705), because tinypool could not share one
  resource budget across pools (workspace runs over-allocated on small machines) or mix
  per-task isolation flags. The lesson: one global `maxWorkers` budget and per-task isolation
  flags, designed in from the start.
- **Watch and impact selection.** The module graph falls out of Vite's transform step, built
  lazily rather than by crawling the repository first. There are `--changed[=gitref]`,
  `--related <files>` (static import analysis, so dynamic imports are invisible) and
  `--standalone` (a warm process that runs on change). The interactive keys are `a f u p t q h`.
  A known gap: failed-only mode (`f`) is forgotten when a file changes (issue #10247).
- **Reporter API (v3 and later).** Typed lifecycle methods (`onTestRunStart`,
  `onTestModuleQueued/Collected/Start/End`, `onTestSuiteReady/Result`,
  `onTestCaseReady/Result`, `onHookStart/End`, `onTestRunEnd(modules, errors, reason)`) that
  receive immutable `TestModule`, `TestSuite` and `TestCase` objects. Version 4.1 added a GitHub
  job-summary reporter and a token-minimal "agent" reporter.
- **Snapshots.** A standalone package: a `SnapshotClient` with a pluggable environment, inline
  snapshots located through the stack trace and written back by rewriting the code, and one
  serializer shared with the diff output.
- **Configuration.** `projects` (globs, files or inline, with an explicit `extends: true` rather
  than a silent merge, some options allowed only at the root, and a `--project` filter). Version
  4.1 added **tags** that carry shared options such as retries and timeouts, with a boolean filter
  language, and `--shard=1/3` with a blob reporter and `--merge-reports`.

### What Jest taught

- **What Vitest dropped.** jest-haste-map crawls the whole repository up front and keeps a module
  map in memory. It was built for Facebook-scale monorepos and brings a crawl cost and name
  collisions. Building the graph lazily avoids both.
- **Impact flags**, in the documentation's words: `--onlyChanged` "requires a static dependency
  graph (no dynamic requires)"; `--findRelatedTests <files>` for pre-commit hooks;
  `--changedSince`.
- **The jest-diff pipeline.** Serialise with pretty-format, diff the lines (diff-sequences), then
  colour. Strings also get the changed substring highlighted within the line (in inverse
  colour). There are Expected/Received labels, collapsed context and change counts, and when two
  serialisations are equal it says so ("looks equal, differs by identity or type").

### Bun

The runner is built into the runtime: no per-file bootstrap, native transpilation, a native
`expect()`. It runs sequentially in one process by default, with concurrency as an option. It
trades isolation for speed, the opposite default from Vitest and Node. That makes it a useful
reference for a no-isolation mode, not a model for the default.

### What Crucible took

| Area | What Crucible does | Phase |
|---|---|---|
| Dependency graph for impact selection | PHP has no transform step to hook, so two sources are merged: static analysis (use, extends, implements, attributes, through the autoloader's classmap; dynamic container lookups are a documented blind spot) and the per-test covered-file maps from coverage, cached incrementally, then inverted to file → tests | G3 |
| Watch | `--changed[=ref]`, `--related`, `--standalone`; keys `a f u p t`; failed-only mode that **stays on** across changes (the Vitest gap); failures first on every re-run | G3 |
| Reporter API | Vitest's v3 shape: typed lifecycle methods over immutable values. Default, tree and dot output, JUnit, GitHub annotations and job summary, blob and merge for shards, and a token-minimal reporter | 8 |
| Failure diffs | The jest-diff pipeline over one canonical serialiser for PHP values, shared with snapshots: line diff with in-line highlighting, Expected/Received, collapsed context, and the identity-versus-equality note | 2 |
| Worker budget | One global budget with per-task isolation flags, the problem tinypool could not solve | 6 |
| Configuration | `projects` with explicit inheritance and root-only options; tags that carry shared options, with a boolean filter | 7, G1 |

## 3. What does Pest specify?

_Checked 2026-07-14 against pestphp.com/docs. The full inventory is in
[`spec/pest-api.md`](spec/pest-api.md)._

Crucible runs Pest suites, so Pest's public behaviour is a second specification alongside
PHPUnit's. The question was what that specification contains and which parts belong in the
engine rather than in the dialect.

Pest was at **v4** (4.7.5, 2026-07-06), MIT-licensed, PHP 8.3+, built as a wrapper around
PHPUnit. Its command line inherits most of PHPUnit's options, which Crucible's PHPUnit-based
command line already covers.

### Pest 5

_Observed 2026-08-01 by installing the released package (5.0.2) in a scratch project and reading
its `--help` output and the JSON files it writes. No Pest source was read._

`spec/pest-api.md` still describes v4; refreshing the whole dialect surface for v5 is separate
work. Pest 5 bundles phpunit/phpunit **^13.2**, the major version Crucible already targets.

- **Test impact analysis (`--tia`).** It needs pcov or Xdebug (`XDEBUG_MODE=coverage`). Without
  either, the run prints "TIA... skipped" and quietly runs everything; there is no static-analysis
  fallback. The graph is one JSON file **outside the project**, at
  `~/.pest/tia/<project-name>-<hash>/graph.json`. It holds a `fingerprint` (hashes of
  `composer.lock`, `phpunit.xml`, the Vite config and `package.json`, plus the PHP minor version)
  that invalidates the whole graph when the environment changes; `files` → `edges` (each file to
  the source it covered, index-encoded); and `baselines` keyed **per git branch**, each with the
  commit it was recorded at and a **stored result for every test** (status, message, time,
  assertion count). The stored results explain the "10 min → 4 sec" claim: tests the change
  doesn't reach are not skipped, their last result is *replayed* into the report, so the run
  still reads like a full suite. The `test_tables`, `test_inertia_components` and
  `js_file_to_components` keys (empty in a plain PHP project) show the graph also tracks database
  tables and Inertia/JS components, not only PHP coverage. `--tia --baselined` and `--refetch`
  fetch a **graph recorded by CI** instead of building one locally, a team-scale mode Crucible
  has no equivalent of.
- **The PHPStan plugin** (`pestphp/pest-plugin-phpstan`, a separate package, wired through the
  same `extra.phpstan.includes` mechanism as Crucible's own extension, D-049). Its service list
  shows two halves. The type half (narrowing along an `expect()` chain, `$this` inside test
  closures, higher-order chains, dynamic properties) is what Crucible's extension also covers,
  for both dialects. The dynamic-property part was only matched in 1.1.5: a property a
  `beforeEach` sets is now typed for the file's tests, as the plugin types it (D-144). The other
  half is about eleven **lint rules** for test-writing mistakes: duplicate test descriptions,
  empty test closures, impossible or redundant expectations, `$this` inside `beforeAll`, invalid
  group names, a redundant local `uses()`, static test closures, `describe` blocks with no
  tests, and calls not allowed inside `describe()`. Crucible has one rule of that kind
  (`PropertyParameterRule`). None of these mistakes is specific to Pest — an empty test body or a
  duplicate description is just as wrong in a PHPUnit file — so a rule set covering both dialects
  would be the better version.
- **The rest of the release** (an agent plugin with a single verify command and browser driving,
  "Evals" that score LLM output through `expect()`, Rector rules that turn raw assertions into
  matchers, and time-balanced sharding with `--update-shards`) was read from `--help` and the
  release notes only. On time-balanced sharding, **Pest is ahead**: Crucible schedules its
  `--parallel` workers by recorded duration (Graham's LPT, §4), but `--shard` splits by a stable
  hash and never reads a duration (D-028). An earlier version of this document said the
  opposite, and was wrong.

### What Crucible took

| Area | What Crucible does | Phase |
|---|---|---|
| Scope of the Pest dialect | The core is `test()`, `it()`, `describe()`, `expect()` (70+ matchers, modifiers, `extend`, `intercept`, `pipe`), hooks and `Pest.php` semantics, and datasets (named, bound, lazy, combined as a Cartesian product). Architecture rules, mutation, stress, browser and type coverage are separate decisions | G2 |
| Features shared with the engine | Snapshots, sharding, flaky retries and `--dirty` overlap what Crucible planned anyway, so each is built once in the engine and exposed through every dialect | 6, G1, G3, G4 |
| One constraint engine | `toMatchConstraint` accepts a PHPUnit constraint, which confirms that `expect()` and `assert*()` must sit on the same constraint engine (D-008) | 2, G2 |
| Datasets | Pest's datasets (bound closures resolved after `beforeEach`, Cartesian combination, per-folder scoping) do everything PHPUnit's data providers do and more, so the dialect-neutral test definition models parameters richly enough for both | 4 |
| `--ci`, focus, todo | Focus mode (`only()`), `--ci` ignoring it, and todo metadata (assignee, issue): cheap engine features, surfaced in each dialect | G2 |
| Impact selection compared with TIA | Crucible's `--changed` and `--dirty` start from static analysis and work with no setup; TIA needs coverage and runs everything without it. Three ideas from TIA are worth adopting: a fingerprint of `composer.lock`, `crucible.php` and the PHP minor version that invalidates the graph on its own; replaying cached results so a `--changed` run reports like a full suite; and fetching a baseline recorded by CI | G3 |
| PHPStan | Crucible's extension covers the type half for both dialects (D-049, D-144). The gap is lint rules, best built for both dialects at once | D-049 follow-up |
| Pest 5 | Bundles PHPUnit ^13.2. Of its new options, `--retry` and `--dirty` exist in Crucible; `--tia` is discussed above; `--update-shards` has no equivalent. `--ai`, the agent plugin, Evals and the Rector rules are product features rather than test syntax, so nothing is owed to them | G1, G2 |

## 4. What does the research literature say?

_Citations checked 2026-07-14 against publisher pages, author PDFs, dblp and project sites._

The question was which techniques have measured results behind them, so that Crucible spends
its effort where it pays.

| Problem | Source | Measured result | What Crucible does with it |
|---|---|---|---|
| Ordering tests | Rothermel, Untch, Chu, Harrold, IEEE TSE 27(10), 2001; Elbaum, Malishevsky, Rothermel, IEEE TSE 28(2), 2002; cost-aware APFD_c, ICSE 2001 | Orderings driven by coverage or failure history detect faults significantly sooner (APFD). APFD_c weights by a test's cost, so "fast tests first, recent failures first" has a basis | Phase 6: the `defects` order is failure history weighted by recency, with duration breaking ties |
| Selecting tests | Ekstazi: Gligoric, Eloussi, Marinov, ISSTA 2015 (Distinguished Paper); HyRTS: Zhang, ICSE 2018 | Tracking checksums per file or class cut end-to-end time by **32% on average** over 615 revisions of 32 projects; coarse granularity wins because its overhead is low. HyRTS (file plus method) beats file level over 2,707 revisions | G3: Ekstazi's file-level design maps almost directly onto PHP autoloading; HyRTS only if file level proves too coarse |
| Flaky tests | Luo, Hariri, Eloussi, Marinov, FSE 2014; DeFlaker: Bell et al., ICSE 2018; iDFlakies: Lam, Oei, Shi, Marinov, Xie, ICST 2019; survey: Parry et al., ACM TOSEM 31(1), 2021; Google (Micco, 2016): about 16% of tests show flakiness, in about 1.5% of runs | Asynchronous waits, concurrency and order dependence cause **78%** of flaky tests. DeFlaker (a failure that executed no changed code is flaky) reaches **95.5% recall with 1.5% false alarms and no reruns**. iDFlakies: **50.5%** of flaky tests depend on order | G4: seeded random order with replay, a DeFlaker-style "did the failure touch the change?" check, and quarantine, all deterministic |
| The cost of isolation | VMVM: Bell & Kaiser, ICSE 2014 (Distinguished Paper); PolDet: Gyori, Shi, Hariri, Marinov, ISSTA 2015 | Resetting only the state a test polluted, inside the process, cut suite time by **62% on average (up to 97%)** compared with a process per test, with the same faults found. PolDet found 324 polluting tests among 6,105 | Phase 6: a cheap snapshot-and-reset of state is the default, and a separate process is the exception. PHP's mutable global state (`$GLOBALS`, statics, the environment) can be enumerated, which makes this unusually tractable |
| Property-based testing | QuickCheck: Claessen & Hughes, ICFP 2000; Hypothesis: MacIver & Hatfield-Dodds, JOSS 4(43), 2019; its internals: MacIver & Donaldson, ECOOP 2020 | Generation records a sequence of choices, and shrinking regenerates from shorter sequences, so every shrunk example is valid by construction, whatever the generator, with deterministic replay and a database of failures | G5: Crucible follows the ECOOP 2020 design rather than classic QuickCheck shrinkers |
| Making mutation testing practical | PIT: Coles et al., ISSTA 2016, and pitest.org | Cheap because per-test line coverage limits which tests run for each mutant (fastest first), workers persist, and results are cached incrementally | The prerequisites land in Phase 6 and G3. Mutation stays open to outside tools through the event stream and the worker API |
| Scheduling | Graham, SIAM J. Appl. Math 17(2), 1969 | Longest-processing-time-first finishes within (4/3 − 1/(3m)) of the optimal time | Phase 6: the `--parallel` worker queue. Sharding stayed hash-stable instead (D-028, §3) |
| Machine learning, for contrast | Meta's predictive selection: Machalica et al., ICSE-SEIP 2019 | Halved infrastructure cost while still catching over 95% of failures, but not deterministically | Confirms the choice not to use ML: Crucible's results must be deterministic, which rules this trade out |

### Order of work

Ranked by expected benefit on a single machine, with determinism as a constraint:

1. Prioritisation by failure history and cost: nearly free, and it helps every run (Phase 6).
2. Cheap isolation and pollution detection in the VMVM/PolDet style: the largest single-machine
   speed-up measured (Phase 6).
3. File-level selection in the Ekstazi style (G3).
4. Seeded random order and DeFlaker/iDFlakies flaky classification (G4).
5. LPT scheduling by recorded duration. It landed in the worker pool (Phase 6); sharding went
   hash-stable instead (G1, D-028).
6. Mutation infrastructure in the PIT style, which depends on 1 to 3 (after G3).
7. Property-based testing with choice sequences: a differentiator rather than core runner work
   (G5).

_Not verified: HyRTS's exact speed-up percentages and the accuracy figures for predictive
mutation._

## 5. What happened to the independent PHP runners?

_Checked 2026-07-14 against each project's repository and documentation. Behaviour was taken from
documentation only._

PHPUnit has had challengers for twenty years. The question was what each of them did well, and
why so few of them lasted.

| Runner | Status | What it does well, and where Crucible uses it |
|---|---|---|
| **atoum** | **Archived 2026-07-02** (final release 4.4.1, BSD-3) | A typed tree of asserters (→ the `expect()` surface, 2/G2). Named execution engines `inline`, `isolate` and `concurrent`, chosen per test, where Crucible's in-process reset is the missing middle option (→ 6). A **loop mode**: after a failure it replays only the failed tests, then reruns the full suite once they pass (→ G3 watch). Function mocks by namespace shadowing, the cheapest kind of double (→ 5) |
| **Kahlan** | Active (6.1.0, 2026-01, MIT) | Instruments source just in time at Composer autoload, patching methods and `new`, and documents its hard limits (no final classes, no files included by hand) — a model for stating a boundary honestly (→ 5). A single `allow()` verb whose strategy is inferred from its target, much easier to hold in your head than createMock / willReturn / expects (→ 5). Coverage drivers kept separate from exporters (→ 8) |
| **Patchwork** | Maintained (2.2.3, MIT) | Two tiers of instrumentation: rewrite userland code through a stream wrapper, special-case internals and `exit`. It says plainly that the cost is "prohibitively slow for production", so instrumentation stays test-only and opt-in per file. It documents the hazards around magic constants. Depending on it (MIT) as an optional backend for deep doubles would be better than writing one (→ 5) |
| **Peridot** | Inactive since 2017 | A **complete set of events** for a runner's vocabulary, including `suite.halt` (cooperative cancellation) and the difference between `suite.define` and run time, both easy to forget (→ 1). A configuration file that receives the event emitter, so custom reporting needs no plugin API (→ 1/7) |
| **Codeception** | Active (5.3.5, 2026-02, MIT) | Actors composed from modules, with a generated, typed helper surface (→ a distant dialect or plugin idea). Modules, bootstrap and environment per suite, with an `--env` matrix (→ 7, configuration profiles). It survives by *wrapping* PHPUnit: compatibility beat superiority. **A Cest dialect was declined, not deferred** (2026-07-22). The demand probe (D-070) came back negative: Codeception is healthy (91M installs), but Cest cannot be separated from the module framework (Db, REST, WebDriver) that Crucible will not provide, so "Cest on Crucible" has no audience. Install counts alone are not a reason to reopen this |
| **Behat** | Active (3.32; 4.0 alpha in 2026) | A Gherkin dialect is **feasible, with moderate effort**: steps bound by attribute (`#[Given(':count monsters')]`) map directly onto the frontend model (D-008), and a fresh context per scenario is the existing isolation model. `--append-snippets`, which writes the missing step for you, is a habit worth adopting everywhere (→ CLI) |
| **PHPSpec** | Active (8.3.1; 9.0 beta targets PHP 8.5, MIT) | **The clearest doubles semantics in the field**: one double, three timings of verification — `willReturn` (stub), `shouldBeCalled` (mock), `shouldHaveBeenCalled` (spy) (→ 5). Collaborators doubled automatically from their type hints, which pairs well with PHP 8.4's lazy objects (→ 5). `shouldThrow(...)->during(...)` as a composition (→ 2) |
| **SimpleTest** | Historical | Nothing to take |

**The ideas with the most value for Crucible**, in order: PHPSpec's stub/mock/spy timing model;
Peridot's event set (`suite.halt`, define versus run); atoum's loop of failed tests first, then
the full suite; Kahlan's single `allow()` verb; doubles built from type hints through lazy
objects; atoum's named engines with a per-test isolation choice; Patchwork as an optional
dependency for instrumentation; the event bus exposed in configuration; Behat's attribute-bound
steps and snippet generation; atoum's typed asserter tree.

**Why so few lasted.** With atoum archived, the pattern is complete. Every independent runner
that asked users to migrate is gone or niche: atoum is archived, Peridot inactive, PHPSpec
limited to its niche. The ones that survived wrap the incumbent (Codeception), serve a different
audience (Behat), or own one capability nobody else has (Kahlan, Patchwork). None of them offered
drop-in compatibility. That is the gap Crucible is built to fill.

## 6. What do the companion tools know?

_Checked 2026-07-14 against each project's repository and documentation. Behaviour was taken from
documentation only._

Around PHPUnit sits a ring of tools that each solved one problem PHPUnit left open. The question
was what each had to learn the hard way.

| Tool | Status | What it knows, and where Crucible uses it |
|---|---|---|
| **ParaTest** | 7.23.0, 2026-06, MIT; supports only the latest PHPUnit, since it depends on `@internal` classes | Version 7 removed process-per-class; **persistent workers (WrapperRunner) are the only model left**, which supports Crucible's pool as the default (→ 6). **`TEST_TOKEN`** (stable per worker) and **`UNIQUE_TEST_TOKEN`** (per run and process) must match byte for byte, because Laravel and project fixtures depend on them (→ G1). Its documented pitfalls are ones Crucible avoids: setup meant to run once per suite runs once per process (hence an explicit **once-per-run hook**), and static state crosses classes (hence the in-process reset). `--max-batch-size` is a worker-recycling policy (→ 6). Merging coverage is its fragile area (three bug-fix releases in 2026), so a central merge of each worker's coverage is a cheap way to be more reliable (→ 6/8) |
| **Mockery** | 1.6.12 (2024-05), BSD-3; the repository is active but there has been **no stable release in over two years**, and PHP 8.5 support was unverified | The doubles specification Laravel is built on (`$this->mock`, `partialMock`, `spy`), so a stalled release is an opening for a compatible implementation (→ 5, a third specification). Worth adopting: the `allows` / `expects` verbs for stub versus mock; its matcher algebra, including `capture($var)`, which PHPUnit has no equivalent of; ordering across mocks; partial proxies that break type hints, which **lazy objects can make type-safe**; alias and overload mocks of static methods, which need `#[RunInSeparateProcess]` there and that **the in-process reset makes safe without a separate process**, Mockery's worst problem solved (→ 5, 6) |
| **Prophecy** | 1.26.0, 2026-02, MIT, PHP ≤ 8.5 | One model in which dummy, stub and mock *emerge* from how a single prophecy is configured (→ 5). PHPUnit dropped its bundled integration because of tangled dependencies and low use. The lesson: **never bundle a doubles library; be a good host for one** (teardown order, assertion counting for `ProphecyTrait`) (→ 4/5) |
| **Symfony PHPUnit Bridge** | 8.1.1, 2026-06, MIT; deliberately narrowed for PHPUnit 10+ | **The most complete deprecation grammar there is, and now without a home**: attribution as self, direct, indirect or legacy (by *who called* the deprecated code), `max[]` thresholds per group, `baselineFile` and `generateBaseline`, `ignoreFile`, `quiet[]`, and excluding a legacy test group. PHPUnit 11+'s own handling does less (no attribution, no thresholds per group). Crucible ships all of it natively (→ 8). **ClockMock** (time, sleep, hrtime and the rest through namespace shadowing, opted into with `#[TimeSensitive]`, with the `\time()` caveat), **DnsMock** and **ClassExistsMock**: hermetic mocking proven in large codebases, as opt-in attributes (→ 5/6) |
| **Infection** | 0.34.0, 2026-06, BSD-3, PHP ^8.3 | Its pipeline is a history of working around a command line: a rigid two-file contract (XML coverage plus JUnit), a custom `phpunit.xml` for each mutant, and a fresh process every time. What would make a tool like it about ten times cheaper is in the runner: **an index of coverage and timing per test and per line that can be queried** (→ 1), **an API to rerun on persistent workers** (swap the code, run an ordered list of tests fastest first, stop at the first failure, return a structured verdict of killed, escaped, error or timeout) (→ 6), a mutant chain that doesn't care what kills it, and the MSI vocabulary. Pest built mutation into the framework, which shows the demand for first-class hooks (→ the mutation surface) |
| **PHPBench** | 1.7.0, 2026-06, MIT | The vocabulary of measurement: revolutions versus iterations versus warm-up; a **retry threshold** that rejects unstable iteration sets ("rstdev above 2% is suspicious"); and reporting **the mode and rstdev, not the mean**, since the mean is dominated by noise. Built into the event stream's timing fields (→ 1) and into `--profile` and flaky-test classification (→ G4). Performance assertions relative to a baseline with a tolerance are a ready specification for a later performance step |
| **PHPT** | Alive in php-src and PECL; PHPUnit 13 still discovers it automatically; almost no use in userland | **No `.phpt` dialect at the compatibility tier.** Crucible recognises `.phpt` files and says clearly that it doesn't run them; a separate dialect package can come later if anyone asks. The EXPECTF specifiers overlap `assertStringMatchesFormat`, which is needed anyway (→ 2) |
| **Laravel's testing layer** | Laravel 13.x; Pest and PHPUnit both first-class | A thin layer over exactly **three contracts: PHPUnit's assertions and `TestCase`, ParaTest's tokens, and Mockery**. Matching those three makes most of G1 come for free. The `ParallelTesting` lifecycle must run: `setUpProcess(token)`, `setUpTestCase`, `setUpTestDatabase(db, token)`, the teardowns, and `token()`. `artisan test` adds pretty output, `--coverage --min`, `--profile` and `--recreate-databases`; Laravel 13 adds `#[UnitTest]` and `#[Seed]`. Among the database traits, `RefreshDatabase` wraps each test in a transaction, so events must not assume one connection can see another's writes. Time travel is sugar over `Carbon::setTestNow()`, which complements ClockMock rather than replacing it (→ G1) |

**The ideas with the most value for Crucible**, in order: a query API for per-test coverage and
timing in the runner itself; byte-compatible `TEST_TOKEN` / `UNIQUE_TEST_TOKEN` and hooks shaped
like `ParallelTesting`; everything `SYMFONY_DEPRECATIONS_HELPER` does, natively; an API to rerun
mutants on persistent workers; alias and overload static mocks made safe by isolation; separate
once-per-run and once-per-worker hooks, with a recycling policy; a Mockery-compatible doubles
specification; mode, rstdev and retry-threshold statistics for timing; ClockMock, DnsMock and
ClassExistsMock as opt-in attributes; merging coverage in the engine.

_Not verified: ParaTest's exact list of options; Infection's PHPUnit ceiling; "Pest is the default
in Laravel 13" (secondary sources only); whether `LazilyRefreshDatabase` is in the 13.x docs.
Settled 2026-07-16: Mockery 1.6.12 **runs on PHP 8.5.7**, probed from outside (see
spec/mockery-api.md). The core paths work, but readonly classes and some API name collisions
are fatal; the stalled release predates PHP 8.5's features._

## 7. How should a test report look on paper?

These are the inputs for the PDF report (D-068) and for any later report a person reads: the
typography and layout canon, and the existing test-report tools, studied for their structure and
not copied.

| Source | What it settles |
|---|---|
| **The booktabs manual** (Simon Fear, "Publication quality tables in LaTeX") | The rules for printed tables: no vertical rules inside a data table, few heavy horizontal ones, hierarchy from spacing and weight. That is why the result tables dropped their grid for header bands and zebra stripes |
| **Stephen Few, *Show Me the Numbers*** | The order in which people scan status tables: status first, identity second, measures right-aligned at the edge. Hence the column order #, Outcome, Test, Time |
| **Edward Tufte, *The Visual Display of Quantitative Information*** | The data-ink ratio: every rule and every fill has to earn its ink, so zebra stripes are only as dark as it takes to follow a row |
| **Matthew Butterick, *Practical Typography*** | Point sizes, line spacing and margins for A4 pages |
| **Allure Report, pytest-html, ReportPortal, Maven Surefire HTML** | The conventions of the genre: numbered rows, a status badge at the start of each row, totals per suite, failure details grouped and numbered. Readers already know these, so the PDF follows them |
