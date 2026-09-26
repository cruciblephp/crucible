# Crucible PHP — Design Record

> The decision log. Every architectural choice is recorded here **before** it merges, with
> the rationale and — where a research result or another ecosystem's design informed it —
> the source. Companion document: `RELEASE.md` (shipped capability). There is no roadmap file:
> every scoped item has shipped, and new work is planned in its decision entry here before it
> merges. The method and principles that govern those decisions open this document.
>
> Entries below written before that retirement still mention `ROADMAP.md`, because they record
> what was true when the decision was taken. They are left as written: a decision log that is
> edited to match the present is no longer a record. Each such deferral either shipped — with its
> own entry here — or was declined where it was decided.
>
> Provenance rule (project invariant): Crucible is a clean-room implementation. PHPUnit defines
> the *behavioral spec* — established from public documentation and black-box observation of
> the `phpunit` binary — and is never read as source while implementing. No code, templates,
> doc comments, or message text are copied from any project. Sole copyright, every line.

## Method: clean-room against a behavioral spec

1. **The spec is observable behavior.** Established from public documentation and black-box
   observation of the `phpunit` binary. Upstream internals are never read as design input.
2. **Design first, from the state of the art.** Research results and other-language designs
   are admissible; legacy shape is not, even when it would be quicker.
3. **Conformance is executable.** Fixture suites run against both binaries, asserting
   identical observable results — the spec in executable form, and the regression net that
   makes internal redesign safe.

## Principles

| Principle | Meaning |
|---|---|
| **Spec, not source** | PHPUnit defines *what*; Crucible decides *how*. Sole copyright over every line is a project invariant (commercial licensing stays open). |
| **Parity first, growth after** | Achieved; the parity core is now guarded by the conformance suite, and new work must keep it green. |
| **If it can be done better, do it better** | Internals owe nothing to how the incumbent does it. Admitted on evidence, recorded in `DESIGN.md`. |
| **No XML, no legacy** | Typed PHP configuration; JSON for caches, baselines, event streams. XML survives only as the opt-in JUnit emitter. |
| **Deterministic** | Same input, same result. Randomness is always seeded and replayable. ML-flavored techniques are declined on this ground. |
| **PHP 8.3 idioms throughout** | readonly value objects, backed enums, promoted constructors, typed class constants, `#[Override]`, first-class callables. The `array_any`/`array_all`/`array_find` functional array helpers (8.4) are used too, backfilled below the floor via `src/Polyfill/` (D-001). |

## On demand gates, before release

Several items were once held behind a D-070 **demand probe** — build it when a real user base
demonstrates it is wanted. That test is sound for a released tool and **circular for this one**:
Crucible has no users yet, so no feature can ever demonstrate demand, and the gate quietly converts
into "never". Applying it uniformly is not discipline; it is a permanent excuse.

What survives from D-070 is the *verification* half, which is a different question: **can this be
proven against the genuine article?** Install the real package, run the real binary, execute the
shipped file — never a copy or an imitation written to make a test pass. That standard is what
kept D-083 honest, and it costs nothing that a demand gate was pretending to protect.

An item is therefore deferred only when it is genuinely unprovable or out of scope — not because
nobody has asked for it yet.

---

## Identity

| | |
|---|---|
| Package | `cruciblephp/crucible` (was `cruciblephp/cruciblephp` until 2026-09-22 — renamed to the `org/tool` shape phpcpd-next uses, before any release) |
| Namespace | `LucianoPereira\Crucible\` (compat aliases for `PHPUnit\Framework\*` arrive at the Phase 9 conformance milestone) |
| Entry point | `crucible` |
| Configuration | `crucible.php` (typed PHP), `crucible.dist.php` fallback |
| Compatibility target | PHPUnit 13 observable behavior (`Version::COMPATIBILITY_TARGET`) |
| License | MIT (adopted 2026-08-01) |

---

## Phase 0 — Scaffold

### D-001: PHP 8.3 floor, minimal runtime dependencies

`composer.json` requires `php: >=8.3` with the platform pinned to 8.3.0; runtime deps are
`ext-mbstring` and `ext-simplexml`. Dependencies are added per-phase when a capability
genuinely needs one, and each addition is a decision recorded here. Starting from zero and
adding deliberately beats starting from twenty and forgetting why.

**`ext-simplexml`, declared 2026-07-27:** `Configuration/XmlMigrator.php` (the `crucible
migrate-config` command, which parses an existing `phpunit.xml` to convert it) has always used
`simplexml_load_string()` — a real, correct use of a real parser for arbitrary pre-existing
XML, not something a hand-rolled or regex parser should attempt. The dependency was simply
never *declared* in `composer.json`, so on a PHP install without it (surfaced only when
verifying the 8.3/8.4 floor on real, minimal CLI-only installs rather than this box's
already-XML-bundled PHP 8.5), the failure was a confusing raw `Call to undefined function`
instead of a clean Composer-level platform-requirement error. Declaring it fixes that. DOM and
SimpleXML ship from the same underlying extension package on every mainstream distribution
(confirmed: Debian/Sury's `php-xml` package description is literally "DOM, SimpleXML, XML,
and XSL module for PHP") — switching to `DOMDocument` wouldn't have avoided this gap, only
renamed it. A userspace alternative (e.g. `sabre/xml`, itself typically built on
`ext-xmlreader`) was considered and rejected: it doesn't remove the underlying C-extension
need either, and would add a real Composer runtime dependency this design principle
deliberately avoids.

**Why 8.3:** the design assumes readonly classes (8.2), backed enums (8.1), promoted
constructors (8.0), and typed class constants + `#[Override]` (8.3) as *defaults*, not
upgrades — 8.3 is the newest of those. Matches Pest's own floor.

**The `array_any()`/`array_all()`/`array_find()`/`array_find_key()` functional array helpers
are PHP 8.4, not 8.3** — genuinely used, at 22 call sites across 16 files, for exactly the
readability those functions exist for (replacing hand-rolled loop-and-flag idioms). Rather
than lower the floor's *usefulness* to match the lowest common feature set, they're backfilled
by `src/Polyfill/Php84/ArrayFunctions.php`: `function_exists()`-guarded global function
definitions, autoloaded unconditionally via `composer.json`'s `autoload.files` (see
`src/Polyfill/README.md` for the convention). Call sites everywhere else call the bare
function name exactly as if it were native — on 8.4+ the guard skips and the real function
wins; on 8.3 the backfill runs. **Removal, the day the floor rises to 8.4 again, is exactly
three things: delete the `src/Polyfill/Php84/` folder, its one `composer.json`
`autoload.files` entry, and `tests/unit/Polyfill/Php84/` — zero call-site changes anywhere,
because every call site already just calls the bare function name.**

Two more PHP 8.4-only constructs were found and fixed outright (not backfilled, since neither
has a sensible polyfill and both are harmless to fix permanently regardless of floor):
- **One property hook** — `Expectation::$not` was a get-hook virtual property; refactored
  into the class's existing `__get()` magic method (which already handled other higher-order
  property reads) with the hook body extracted verbatim into a private `negate()` method.
  Zero call-site changes: `->not` is read identically by every caller either way.
- **`new Foo()->bar()` chaining without wrapping parens** — 8.4-only *parser grammar*; before
  8.4 this is a parse error, not a runtime error, so no polyfill can address it. Mechanically
  rewritten to `(new Foo())->bar()` (valid on every PHP version) at 170 sites across 54 files
  in `src/`, `tests/unit/`, `examples/`, and `conformance/`, via a `token_get_all()`-based
  script: it locates each unwrapped occurrence by the fact that already-safe code always has
  a `)` immediately after the constructor call's own closing paren (the wrapper), never `->`
  directly — so detection has no false positives on code that was already fine.

**Revision history (2026-07-27):** originally set to 8.5 on the belief that native lazy
objects (8.4) were load-bearing to the design. An audit of `src/`, `tests/unit`, and
`examples/` for lazy-object APIs (`newLazyGhost`, `newLazyProxy`, `resetAsLazy*`,
`initializeLazyObject`) and other 8.4/8.5-only constructs found none, so the floor was lowered
to 8.3 on the strength of that audit. Rebuilding `phpstan.neon`'s platform target from 8.3 then
surfaced what the first audit missed: `array_any`/`array_all`/`array_find` are real 8.4
dependencies, used throughout — so the floor was set to 8.4 instead, pending a genuine 8.3
push. That push happened the same day: the array-function dependency was backfilled via the
new `src/Polyfill/` convention above, a second missed 8.4 dependency (the `$not` property
hook) was found and refactored, and a third — 8.4's "new without parentheses" grammar, present
at 170 call sites — was mechanically rewritten to its universally-valid parenthesized form.
Floor lowered to 8.3 once all three were closed and `composer check` passed clean with
`phpstan.neon`'s platform pinned there.

**PHP 8.5 features, adopted the same day the floor stayed at 8.3:** `array_first()` and
`array_last()` (PHP 8.5) are backfilled the same way as the 8.4 array functions —
`src/Polyfill/Php85/ArrayFunctions.php`, same `function_exists()`-guard convention, same
`composer.json` `autoload.files` wiring, same three-part removal (`Php85/` folder, its
autoload entry, `tests/unit/Polyfill/Php85/`) the day 8.5 becomes the floor. Applied at 6
production call sites that were hand-rolling `$array[0] ?? null` or `$array[count($array) - 1]`
(`Metadata/MetadataCollection.php`, `Dialect/PhpUnit/ClassLocator.php`, `Assert/Assert.php`,
`Dialect/Pest/Expectation.php`, `Double/MethodConfigurator.php`, `PHPStan/UsesResolver.php`)
plus 2 test call sites in `tests/unit/Runner/TestRunnerTest.php`. `array_first()`/`array_last()`
return nullable (an honest empty-array case the raw indexing idiom didn't surface to PHPStan),
which needed `assertNotNull()` added at the two test call sites where the array's real
non-emptiness is a runtime invariant PHPStan can't see on its own — the extension's own
assert-narrowing (D-049) covers this cleanly, so it's a real assertion, not a workaround.

Also fetched PHP's official 8.5 migration guide and checked every other new-in-8.5 candidate
against this codebase before deciding what else to adopt. One documentation discrepancy
surfaced and is worth recording: the migration guide's own summary claims 8.5 allows "closures
and first-class callables" in attribute arguments; empirically testing both forms against a
real PHP 8.5.8 interpreter showed only the first-class-callable half is true (`strlen(...)`
works; a genuine closure literal `fn() => ...` still fatals at compile time) — confirming
the "no closures in attributes" finding above, rather than contradicting it. Lesson: verify
candidate language features against real code and a real interpreter, not migration-guide
prose alone.

Evaluated and explicitly **not** adopted, with reasons, so future readers don't wonder if
these were overlooked:
- **`#[\NoDiscard]`** — confirmed safe (every `getAttributes()` call site in `src/` filters
  by an explicit class; none reflect-and-instantiate unfiltered, so an unresolvable 8.5-only
  attribute class can't fatal on 8.3/8.4). No compelling call site found: `test()`/`it()`
  commonly and correctly have their return discarded; `expect()`/`check()`'s own return is
  essentially never "discarded" in the sense NoDiscard checks (almost always immediately
  chained), and `check()`'s bare, nothing-chained form is an intentional doctest-style usage
  per the D-032 note above — annotating it would fight the framework's own design.
- **`clone($obj, ['prop' => 'value'])`** — real candidates exist (`Expectation::negate()`/
  `each()`, `RetryPolicy::withCount()` all hand-roll "new instance, then set a field or two"),
  but `clone` is a language keyword: `function_exists()` polyfilling is categorically
  impossible (a userland function cannot be named `clone`). Any 8.3/8.4 substitute would need
  a differently-named Reflection-based helper forcing already-initialized readonly properties
  to a new value, and whether that's even possible pre-8.5 is unverified — deferred pending
  its own investigation.
- **`#[Override]` on properties** — zero current use case (all 109 existing uses precede a
  method, none a property).
- **`final` on a promoted property** (`AssertionFailedError::$comparison` is the one candidate)
  — whether `final` on any property was rejected outright pre-8.5 or already legal is
  unverified — deferred, low priority.
- **Pipe operator (`|>`), static asymmetric visibility, casts in constant expressions** —
  grammar-level or unused in this codebase; the pipe operator specifically is the same class
  of problem as the "new without parentheses" issue above — no polyfill helps a parser
  grammar change, so using it anywhere would break the 8.3 floor outright.
- **`get_error_handler()`/`get_exception_handler()`/`Closure::getCurrent()`** — these query
  *currently active* runtime state PHP had no introspection API for before 8.5. A "polyfill"
  would mean globally intercepting every `set_error_handler()`/`set_exception_handler()` call
  site (including ones outside this codebase's control) to shadow-track state ourselves —
  unreliable by construction, not a real polyfill.

### D-002: Configuration is a typed PHP file

```php
// crucible.php
use LucianoPereira\Crucible\Configuration\Crucible;

return Crucible::configure()
    ->bootstrap('vendor/autoload.php')
    ->testSuite('unit', 'tests/Unit')
    ->source(include: ['src'])
    ->strict();
```

A fluent `Builder` produces an immutable `Configuration` of readonly value objects with
public properties. There is no parser, no schema validator, no format migrator: loading is a
`require` plus one instanceof check. The PHP type system is the schema; the IDE autocompletes
it; invalid configuration fails before the run starts.

Each builder method's docblock names the `phpunit.xml` element/attribute whose *semantics*
it matches (a spec mapping, so migration is mechanical); `crucible migrate-config` (Phase 7)
automates the conversion.

**Design inputs:** the language-native config direction of modern PHP tooling
(PHP-CS-Fixer, Rector) and JS tooling (vite.config.ts, vitest.config.ts).

### D-003: Enums where the spec has string options

`executionOrder(ExecutionOrder::DefectsFirst)` instead of `executionOrder="defects"`.
Every closed option set in the spec becomes a backed enum whose backing values are the
spec's strings (so `migrate-config` maps 1:1): invalid values are unrepresentable rather
than runtime-validated.

### D-004: Defaults match the spec exactly

`colors: false`, all `failOn*`/`stopOn*` off, declared execution order. Parity applies to
defaults: a migrated suite must behave identically without touching an option. `->strict()`
is builder sugar that flips the six `failOn*` flags — no capability the spec lacks.

### D-005: Config discovery and failure behavior

Search order: `--configuration <file>` (must exist, hard error otherwise) → `crucible.php` →
`crucible.dist.php` in the working directory. A config file returning anything other than a
`Builder`/`Configuration` is a typed `ConfigurationException` naming the file and the actual
returned type. `--init` writes a starter file and refuses to overwrite.

### D-006: Quality gate before the first feature (upgraded during Phase 5)

`composer check` = four stages, all green at every commit:

1. **Pint** (`--test`) — PER preset + project rules (strict types, imported globals,
   `native_function_invocation`, aligned operators). Replaced php-cs-fixer: same engine
   underneath, Laravel-native configuration.
2. **Rector** (`--dry-run`) — PHP-version sets + deadCode + codeQuality; the tree stays
   fully modernized (8.4 `new X()->m()` chaining, `#[\Override]`, `array_any`, …).
   `RemoveEmptyClassMethodRector` skipped: empty methods here are load-bearing (attribute
   carriers, overridable lifecycle hooks).
3. **PHPStan level max + strict-rules + deprecation-rules** — with documented, spec-driven
   opt-outs: `dynamicCallOnStaticMethod` (the `$this->assertSame()` xUnit idiom),
   `noVariableVariables` (`$this->{$hook}()` lifecycle/doubles dispatch),
   `disallowedEmpty`/`disallowedLooseComparison` (IsEmpty/IsEqual implement the spec's
   `empty()`/`==` semantics deliberately).
4. **The self-hosted suite** (`php crucible`).

Established at Phase 0 so the standard exists before any substantial code does. From
Phase 9, the conformance suite (fixture suites run against both `phpunit` and `crucible`,
asserting identical observable results) joins the gate.

Scope note: this toolchain is `require-dev` — it exists to develop Crucible, and is never a
requirement for projects using Crucible. The runtime footprint stays PHP 8.4 + ext-mbstring
with zero Composer dependencies (D-001).

### D-007: Temporary dev-dependency on PHPUnit

Crucible's own tests run on PHPUnit until Phase 4 self-hosting. Bootstrapping a test framework
requires *a* test framework; using the incumbent keeps the compatibility target honest and
costs nothing legally (using a tool creates no copyright relationship with our code). It is
`require-dev` only and is deleted at Phase 4.

---

## Cross-cutting

### D-008: Dialect-agnostic engine, pluggable syntax frontends

Crucible's engine (test model, runner, assertion/constraint engine, event stream) is
**syntax-neutral**. Test files are translated into neutral `TestDefinition` values by
per-dialect discovery frontends; everything downstream consumes only definitions and events.
Compiler shape: multiple frontends, one IR, one backend.

Dialects, each implementing an external behavioral spec:

| Dialect | Declaration style | Spec | Arrives |
|---|---|---|---|
| `phpunit` | `TestCase` classes + attributes | PHPUnit 13 observable behavior | core phases |
| `pest` | `test()`/`it()`/`describe()`/`expect()`, `uses()`, datasets | Pest's documented public API (MIT; docs-only observation, clean-room rule unchanged) | growth tier (G2) |
| `crucible` | native surface | our own — exists only if it earns existence beyond the Pest surface (e.g. `property()`, arch presets) | growth tier, after G2 |

Dialect resolution is deterministic and never content-based:
1. explicit per-suite config — `->testSuite('feature', 'tests/Feature', dialect: Dialect::Pest)`
2. file-suffix convention — the canonical, symmetric suffixes are
   `*.phpunit.php` → phpunit, `*.pest.php` → pest, `*.crucible.php` → crucible.
   Legacy `*Test.php` also resolves to the phpunit dialect: drop-in parity means an
   existing suite is never forced to rename files.
3. configured fallback — `->defaultDialect(Dialect::Pest)`: any file matching no suite
   dialect and no canonical suffix is treated as this dialect.
4. built-in default — when the project configures no fallback, the fallback is
   `Dialect::PhpUnit` (parity-first: a migrated suite runs with zero configuration).

The resolution chain is total: every discovered file has exactly one dialect, decided by
configuration and naming alone.

Consequences accepted now, cheap now / costly to retrofit:
- Phase 1 event schema identifies tests by dialect-neutral stable ids (no class/method
  naming assumptions).
- The Phase 4 test model must not leak `TestCase` semantics into the runner.
- `expect()` matchers and `assert*()` compile to the same constraint engine, so failure
  events are dialect-independent.

**Why:** mixed suites (legacy PHPUnit classes + newer Pest files, the typical Laravel
reality) run in one invocation — one report, one cache, one schedule — and per-file gradual
migration between dialects replaces big-bang rewrites. Pest achieves mixing by wrapping
PHPUnit; Crucible achieves it as one engine with thin frontends.

---

## Phase 1 — Kernel

### D-009: The event stream — versioned NDJSON, one envelope per line

The engine's one output substrate (`src/Event/`). Every consumer — reporters, caches,
CI tools, mutation testers — reads the same stream; nothing parses human-facing output
(Node's documented mistake).

- **Envelope**: `{"ver":1,"event":"<name>","ts":"<RFC3339.µs UTC>","seq":n, ...payload}`.
  Versioned from day one (`Envelope::SCHEMA_VERSION`) — the lesson from Rust's libtest-json
  stabilization pain. `seq` is gapless and 1-based; the Emitter is the single writer that
  stamps it.
- **Names** (`EventName` enum, `scope:action` per test2json/Node): `run:start`,
  `run:interrupt`, `run:finish`, `suite:start`, `suite:finish`, `test:start`,
  `test:finish`, `test:output`. Stream discipline: first event `run:start`, last
  `run:finish`.
- **One `test:finish` with an `outcome` field** (`pass|fail|error|skip|incomplete|risky`,
  the PHPUnit 13 outcome set) instead of an event name per outcome — Go's Action-field
  model; six names would be noise and consumers switch on one field either way.
  *(Deliberate deviation from the name-per-outcome sketch in RESEARCH.md §1.)*
- **Failures are structured** (`Failure`: message, class, typed trace frames,
  `diff{expected,actual}`) — TAP 14 YAML-diagnostics lesson; reporters own presentation.
- **Output guarantee** (test2json): concatenation of a test's `test:output` chunks in
  sequence order is the exact output; invalid UTF-8 is substituted, never fatal
  (`JSON_INVALID_UTF8_SUBSTITUTE`).
- **Writer** (`NdjsonWriter`): unbuffered `fwrite` per event so live consumers see events
  as they happen; `JSON_PRESERVE_ZERO_FRACTION` so durations stay floats; slashes and
  unicode unescaped for grep-ability.
- **Optional fields are omitted, never null** (`attempt` only on retries, `reason` only on
  skips, `error` only on failures) — lean lines.
- A byte-exact golden test (`NdjsonStreamTest`) pins the schema; any breaking change fails
  there and must bump `SCHEMA_VERSION`.
- Reserved for later phases: `test:slow`, retry semantics on `attempt` (G4), discovery
  events and suite-level halt (Phases 4/6) — additive, non-breaking.

### D-010: Dialect-neutral test identity

`Test\TestId` = declaring file (project-relative) + declared name within the file +
optional dataset key: `tests/Unit/SumTest.php::testAddsIntegers#row 1`. No class/method
semantics in the engine (D-008); the frontend decides what "name" means. `hash()` (xxh3)
is the stable basis for `--partition hash:m/n` — adding/removing tests never moves others
between shards (nextest's property).

### D-011: Injectable wall clock, monotonic durations

`Clock\Clock` (SystemClock = UTC now; FrozenClock for tests) supplies envelope timestamps —
streams are byte-reproducible in tests. Durations are never derived from wall time; they
are measured with monotonic time (`hrtime`) at the call site and carried as float seconds.
Timing statistics (mode/rstdev, PHPBench lesson) are aggregation concerns, not event fields.

---

## Phase 2 — Assertion engine

### D-012: One constraint core, one exporter, structured diffs

`src/Assert/`: every `assert*()` method compiles to a `Constraint` evaluated by
`Assert::assertThat()` — the same core the growth tier's `expect()` matchers will compile
onto (D-008), so failures behave identically across dialects. Negations are one
`LogicalNot` wrapper; the `assertIs*()` and `assertContainsOnly*()` families are one
constraint each parameterized by a `ValueType` enum.

- **Exporter** (`Exporter::export/describe`): the canonical serialization of PHP values —
  one serializer drives comparison diffs and, later, snapshots (pretty-format lesson).
  Failure *presentation* is Crucible-designed; only pass/fail semantics, exception behavior,
  and the API are bound to the PHPUnit spec. Enum cases export as `Class::Case`; object
  and depth recursion guarded.
- **Equality spec** (`IsEqual`): numeric juggling between numbers and numeric strings but
  never between two strings; delta recursing through arrays/objects; canonicalize as
  multiset comparison; DateTimeInterface by instant; same-class property-wise object
  recursion with cycle guard; NAN never equal. Fine-grained comparator parity is pinned by
  the Phase 9 conformance suite.
- **Structured failures**: comparisons raise `AssertionFailedError` carrying a
  `ComparisonFailure{expected, actual, diff}` — the runner maps it straight onto the
  kernel's `Failure` diff payload; nothing ever parses message text. `Differ` is LCS
  line diff with Expected/Actual annotations (histogram/intra-line highlighting can
  replace the internals without changing the contract).
- **Assertion counting** lives in `assertThat()` (`Assert::assertionCount()`), ready for
  the Phase 4 test model and doubles-expectation tallies.

Surface: ~140 of the ~176 spec methods implemented across both tranches — identity/equality
(delta, canonicalizing, ignoring-case), booleans/null, emptiness/counts/sizes, comparisons,
iterable + containsOnly families, string families (regex, JSON validity, format strings,
line-ending-insensitive), instance/type families, object properties + `assertObjectEquals`
protocol, float specials (NaN/infinite/finite), file/directory existence + readability/
writability, file-content equality (+canonicalizing/ignoring-case), JSON string/file
comparisons, fail, assertThat, Callback.

Notes: `StringMatchesFormat` implements the EXPECTF placeholder grammar (shared with the
.phpt format — needed here regardless of the skipped phpt dialect). Missing-file inputs to
file assertions raise an assertion failure, not a PHP error. The XML assertion family
(`assertXmlStringEqualsXmlString` etc.) is deliberately deferred to the Phase 9 conformance
check: implemented only if still present in the PHPUnit 13 spec, and then behind an opt-in
ext-dom suggestion (the no-XML rule applies to Crucible's inputs, not to what user suites
assert about). Also still pending: `assertContainsOnly*` not-variants if the spec has them.

---

## Phase 3 — Metadata

### D-013: Attributes ARE the metadata model

`src/Attributes/` (49 classes) + `src/Metadata/`. The spec's attribute vocabulary is
implemented as tiny `final readonly` value objects carrying a `CrucibleAttribute` marker —
there is no parallel metadata class hierarchy to keep in sync with the attribute classes
(upstream maintains both; that duplication is the legacy shape not adopted).

- **Parser** (`MetadataParser`): reflection-based, attributes only (no doc-comment path,
  per the out-of-scope table). Filters on the `CrucibleAttribute` marker, so foreign
  attributes (`#[Deprecated]`, framework attributes) on test code are ignored.
  `forClass()` includes inherited class-level attributes (most-derived first);
  `forClassAndMethod()` merges method-first — the precedence order consumers resolve
  overrides in.
- **Collection** (`MetadataCollection`): ordered, immutable, generically typed queries
  (`ofType`, `first`, `has`), and constructible programmatically via
  `MetadataCollection::from(...)` — the D-008 requirement that dialect frontends (pest's
  chained `->group()`, `->depends()`) produce the same metadata without attributes.
- **Vocabulary** (49 attributes): Test/TestDox; DataProvider(+External), TestWith(+Json);
  Depends (+deep/shallow clone, +External, +OnClass); Group/Ticket/Small/Medium/Large;
  Covers*/Uses* (Class/Trait/Method/Function/Nothing); hook attributes Before/After/
  BeforeClass/AfterClass/PreCondition/PostCondition with `priority`; backup + exclusion
  attributes; process-isolation attributes; Requires* family (Php, PhpExtension, Function,
  Method, OperatingSystem(+Family), Setting); DoesNotPerformAssertions;
  IgnoreDeprecations; WithoutErrorHandler.
- Semantics are *represented* here and *consumed* later: requirements evaluation in
  Phase 4, backup/isolation in Phase 6, covers/uses in coverage phases. Not carried:
  `RequiresPhpunit*` (framework-version pinning is meaningless for a compatible
  reimplementation — revisit at the Phase 9 conformance check).

---

## Growth tier — designed ahead

### D-014: Inline dialect — tests inside source files (growth step)

A fourth D-008 frontend discovering tests *in production source*, not test files. Two
tiers; the third is declined:

1. **`#[Check]` attribute tests** — constant-expression I/O tables on the method itself:
   `#[Check([2, 3], returns: 5)]`, `#[Check([...], throws: X::class)]`. One test per
   attribute (`src/Math.php::sum#check 1`). Fully typed, IDE-safe, statically analyzable,
   no eval. (PHP attributes accept only constant expressions — that constraint defines
   this tier's shape.)
2. **`@crucible` doctests** — one expression per docblock tag, compiled to a closure in the
   file's namespace, executed on the shared constraint engine (`expect()` surface).
   The doctest property: documentation examples that cannot rot. Known tradeoff: strings
   are invisible to static analysis — keep to single expressions; later, extraction into
   shadow files for PHPStan (`crucible lint-inline`).
3. **Inline test methods in prod classes: declined.** PHP has no dead-code elimination
   (Vitest's in-source testing relies on bundler stripping); tiers 1–2 give the value
   without shipping test bytes in production classes.

Rules preserved: discovery scans the configured `source(include:)` directories (config-
driven, not dialect resolution by content sniffing), with marker pre-filter + mtime-keyed
caching; the feature is growth-tier (post-parity), but costs nothing to accommodate now
because TestId/TestDefinition are already dialect-neutral.

---

## Phase 4 — Test model (core)

### D-015: TestDefinition/TestGroup, the phpunit frontend, and the walking-skeleton runner

The dialect-neutral execution model (D-008 realized):

- **`TestDefinition`** = TestId + fully bound zero-argument closure + metadata. How the
  closure came to exist (TestCase method, pest() closure, inline check) ends at discovery.
- **`TestGroup`** = name + tests + optional beforeAll/afterAll. One shape maps phpunit
  classes, pest describe() blocks, and inline-check files. A failing beforeAll errors
  every test in the group; afterAll failures never change outcomes.
- **phpunit frontend** (`Dialect\PhpUnit`): `ClassLocator` finds the declared class by
  token scan (no execution; anonymous classes and `::class` not mistaken);
  `TestBuilder` turns a TestCase class into a group — test = public non-static method
  named `test*` or `#[Test]`; datasets (DataProvider(+External), TestWith(+Json)) expand
  to one definition per row at discovery time; numeric dataset keys are bare indices in
  ids (`sums#0`, `sums#negative`).
- **`Framework\TestCase`**: fresh instance per test; setUp/tearDown (tearDown in finally);
  class hooks; expectException/Message/MessageMatches/Code (a verified expectation counts
  as an assertion); markTestSkipped/Incomplete; extends `Assert`.
- **Runner** (`Runner\TestRunner`): sequential in-process walking skeleton — Phase 6
  replaces the internals; the outcome classification and event protocol are the stable
  part. Classification: AssertionFailedError→fail (structured diff onto the event),
  Skipped/Incomplete errors→their outcomes, other Throwable→error, zero assertions
  without `#[DoesNotPerformAssertions]`→risky. `#[Requires*]` evaluated pre-run
  (`Test\Requirements`) → skip with reasons. Durations from `hrtime`.
- **CLI**: `./crucible` loads config, applies bootstrap/ini/env/constants, discovers, runs;
  `--log-events-json <file>` subscribes the NdjsonWriter alongside the console reporter
  (walking-skeleton `Reporting\ConsoleReporter`). Exit codes: 2 errors, 1 failures or
  failOn-policy hits, 0 otherwise.

### D-016: Dependencies, hooks, and the self-hosting flip

- **`#[Depends]`** (+deep/shallow clone variants, ordering only for now): definitions carry
  dependency names; the frontend orders groups topologically (stable; cycles keep
  declaration order); the runner injects passed dependencies' return values as trailing
  arguments (spec order: dataset args first) and **skips** dependents of anything that
  didn't pass. Every dataset row must pass for a method to count as a passed dependency;
  dependents receive the last row's value.
- **Hook methods**: `#[Before]/#[PreCondition]/#[PostCondition]/#[After]` discovered on
  public+protected methods into a `HookPlan` ordered by priority (before/pre: high first;
  post/after: mirrored). Per-test order: setUp → before → preConditions → test →
  postConditions → after → tearDown (after/tearDown always run).
- **Per-test assertion window**: the runner resets the assertion counter per test and
  checks `> 0` for risky detection — user code may reset the counter mid-test (a framework
  testing itself does) without corrupting classification.
- **expectException meets AssertionFailedError**: an expected exception may itself be an
  assertion failure; expectation verification runs before the outcome rethrow.
- **Self-hosting (2026-07-14)**: Crucible's suite (69 tests) migrated to
  `LucianoPereira\Crucible\Framework\TestCase` + Crucible attributes; `composer test` = `php
  crucible`; **phpunit/phpunit removed from require-dev — zero PHPUnit code in the tree or
  vendor**. From here every phase is tested by Crucible itself.
- Noted for later: a Crucible PHPStan extension so `assertNotNull`/`assertNotFalse` narrow
  types (PHPUnit had this; tests currently use `?? self::fail()` guards instead).

---

## Phase 5 — Test doubles (core)

### D-017: Reflection-generated doubles, one state brain, spec API

`src/Double/`. Runtime type doubling in PHP requires code generation — accepted and done
honestly: typed rendering from reflection (named/union/intersection/nullable types,
self/parent/static, defaults via var_export, variadics), evaluated once per target,
**cached process-globally** (generated classes are stateless; per-instance `DoubleState`
is injected after `newInstanceWithoutConstructor()`).

- **API parity**: `createMock`/`createStub`/`createConfiguredMock|Stub` on TestCase;
  `$mock->method('x')->with(...)->willReturn*()`; `$mock->expects($this->once())` with
  `once/never/exactly/atLeast(Once)/atMost/any` as one `InvocationCount{min,max}` shape;
  `willReturn/Self/Argument/Callback/Map/OnConsecutiveCalls/ThrowException`.
  Doubles type as `T&Mocked` so static analysis knows both surfaces.
- **Semantics**: latest configuration wins; `with()` matches via the equality spec or
  Constraint instances directly; exceeded counts fail **at call time**; unmet expectations
  fail at end-of-test verification (wired into invokeTest, before postConditions); each
  met expectation counts as one assertion. Unconfigured methods auto-generate returns:
  scalar zero-values, [] for array/iterable, null when nullable, the double for
  self/static, **recursive stubs for object return types**; intersection returns and
  unknown types demand explicit configuration. All public methods are stubbed (spec) —
  `onlyMethods()` partials are a later parity item.
- **Documented limits** (Kahlan's honest-boundary rule): final classes, enums cannot be
  doubled; final methods keep real code; by-ref parameters forwarded by value; targets
  declaring `method()`/`expects()` collide with the configuration API and are rejected.
- Discovery fix en route: `ClassLocator::classesIn()` returns every declared class and the
  discoverer picks the TestCase subclass — files may declare fixture types above the test.
- Later parity tranche: `onlyMethods()`/partial mocks, `getMockBuilder`, callback
  constraints on `expects()->method()` (string only for now), Mockery-compatible surface
  (third spec, RESEARCH.md §6).

---

## Conformance (pulled forward from Phase 9)

### D-018: The executable spec — Crucible vs the PHPUnit oracle

`conformance/` + `src/Compat/phpunit-aliases.php`. Compatibility claims are now executable:
fixture suites written once against the PHPUnit namespace run on both runners and must
behave identically.

- **Fixtures** are the spec in code: outcomes, exit codes, datasets (naming included),
  dependency injection/skipping, exception expectations, equality semantics probes
  (assertions that pass iff the semantics match the oracle), doubles, lifecycle hooks.
- **Oracle side**: the `phpunit-main` binary run black-box through a wrapper that pins its
  own autoloader (its probe would otherwise find Crucible's vendor). Outcomes parsed from
  JUnit XML; aggregate counts from the console summary — empirically, **JUnit reports risky
  as pass and incomplete as skipped**, so the summary channel preserves the fidelity JUnit
  lacks.
- **Crucible side**: run through the compat aliases (the Phase 9 layer, seeded here:
  `class_alias` of TestCase/Assert/AssertionFailedError + all 49 attributes — opt-in,
  nothing aliases implicitly) with outcomes and counts read from the NDJSON stream.
- **Comparison**: per-test outcomes (JUnit granularity), aggregate counts (full fidelity),
  exit codes. `composer conformance`; requires the gitignored `phpunit-main/` reference,
  so it complements rather than joins `composer check`.

First run: 9 fixtures, 43 tests — found one real Crucible bug (a missed-`expectException`
failure was swallowed when the expected type was `RuntimeException`, because
`AssertionFailedError` extends it; fixed by disarming expectations before reporting) and
one harness-granularity artifact (JUnit's risky-as-pass). Everything else conformed on
first contact, including dataset naming, dependency semantics, equality juggling, and
doubles behavior. **All fixtures conform.**

### D-019: Coexistence policy — projects that still have PHPUnit (or Pest) installed

The compatibility aliases must never fight a real installed package silently.

- **Drop-in mode (auto, the default)**: `phpunit/phpunit` absent (Composer runtime API
  check) → aliases load automatically, before the bootstrap, so
  `composer remove phpunit/phpunit; composer require cruciblephp/crucible` runs an existing suite
  with zero configuration.
- **Migration mode (explicit)**: `phpunit/phpunit` installed → aliases stay **off**;
  Crucible prints a one-line note and tests must extend Crucible's TestCase — unless the config
  opts in with `->phpunitCompatibility()`. Enabling **fails fast, before aliasing
  anything**, if any real PHPUnit class is already loaded in the process (a half-aliased
  process must never exist): the error names the loaded classes and the fix.
- `->phpunitCompatibility(false)` disables aliasing outright. The option is tri-state and
  deterministic (installed packages, never content sniffing).
- Caveat: detection reads the autoloader that loaded Crucible — correct in the
  installed-as-dependency scenario; a repo-checkout Crucible run against a foreign tree sees
  its own vendor.
- **Pest coexistence (decided now, lands with G2)**: the pest dialect's global functions
  (`test()`, `it()`, `expect()`) are defined behind `function_exists` guards only when the
  dialect is active, and if `pestphp/pest` is installed the dialect refuses with a clear
  message — same never-mask-a-real-package principle.

---

## Phase 6 — Runner (slice 1: state isolation)

### D-020: VMVM-style global-state snapshot/restore + pollution detection

`src/Isolation/`. PHP's mutable global surface is enumerable, which is what makes the
VMVM result (in-process reset instead of process-per-test, RESEARCH.md §4) unusually
tractable here.

- **`GlobalStateSnapshot`** captures $GLOBALS (minus exclusions), eligible static
  properties, environment variables, ini settings, error_reporting, and the working
  directory. `restore()` reinstates values and drops additions; `changes()` names what a
  test polluted (capped, human-readable). Objects are held by reference (spec behavior):
  mutations inside retained objects are neither restored nor detected — documented limit.
- **`StaticRegistry`** enumerates backup-eligible statics incrementally (declared classes
  only ever append): user-defined, non-enum, non-readonly, initialized — excluding the
  engine's own namespace, generated doubles, and Composer. **Alias lesson (found by the
  conformance suite):** `class_alias` names appear in `get_declared_classes()`, so the
  registry skips any name that differs from its resolved class — otherwise the compat
  aliases smuggle engine statics (the assertion counter!) past the exclusions and the
  backup restore corrupts risky detection.
- **Spec wiring**: `#[BackupGlobals]`/`#[BackupStaticProperties]` (method-over-class over
  config, defaults false per spec), `#[ExcludeGlobalVariableFromBackup]`/
  `#[ExcludeStaticPropertyFromBackup]`, config `backupGlobals()`/
  `backupStaticProperties()`/`beStrictAboutChangesToGlobalState()` (all phpunit.xml
  attributes). Strict mode diffs after every test and classifies pollution as risky with
  the change list as the reason; restore happens on every outcome path.
- `RunnerOptions` carries the runner-facing configuration slice. Conformance fixture
  10-global-state pins backup semantics against the oracle.

Remaining Phase 6 slices: worker pool with supervision (IPC = the NDJSON protocol),
scheduling/ordering (result cache, LPT, seeded random, history-aware defects-first),
`#[RunInSeparateProcess]`.

---

## Phase 6 — Runner (slice 2: scheduling and ordering)

### D-021: Deterministic scheduling on a JSON result cache

`src/Runner/{ResultCache,ResultCacheWriter,Scheduler}.php`. The Rothermel/Elbaum
prioritization line, kept strictly deterministic per the roadmap principle: same order,
seed, and history in — same schedule out.

- **`ResultCache`** is the spec's result cache done as a versioned JSON file
  (`{cacheDirectory}/results.json`): per-test id, a *bounded outcome history* (most recent
  first, 8 runs) plus the last duration — not just a last-status, because recency weighting
  needs memory. Incompatible versions and corrupt files load as an empty cache; the cache
  can improve scheduling, never fail a run. `cacheResult` config attribute (spec default
  true) controls it; disabling also empties what defects/duration ordering read.
- **`ResultCacheWriter`** is just another Listener on the event stream (D-009): records
  `test:finish`, persists at `run:finish`. No hook inside the runner.
- **`Scheduler`** is pure (`list<TestGroup>` in/out), ordering at two levels (groups, then
  tests within groups), always ending in a **dependency repair pass with deferral
  semantics** — a test whose dependencies haven't run yet moves later, matching the
  oracle's observable behavior; reordering can never turn a passing suite into skips.
  Dataset rows share their name and travel as one block; unsatisfiable cycles fall back to
  scheduled order for the runner's skip semantics.
- **Orders**: `default`, `reverse`, `size` (small<medium<large<unsized), `duration`
  (fastest-first per spec; **LPT** partitioning of the same cached durations arrives with
  the worker pool), seeded `random`, and `defects` — history-aware beyond the spec:
  outcomes scored by severity (error 6 > fail 5 > incomplete 3 > risky 2 > skip 1) with
  each run further back counting half, ties breaking toward shorter tests for fastest
  time-to-first-failure.
- **Replayable randomness is owned code**: Fisher–Yates over `Randomizer::getInt()`
  (Mt19937-seeded), so the permutation a seed produces is defined by Crucible, not by an
  engine's shuffle internals. `--order-by` / `--random-order-seed` land on the CLI now
  (CLI-overrides-config, the Phase 7 rule, applied early); the seed is printed on every
  random run.
- **Conformance grew order-sensitivity**: fixtures can carry an `options.json` (extra args
  for both runners, `compareSequence`), and fixture 11-ordering pins the reverse execution
  sequence testcase-by-testcase against the oracle — JUnit document order vs NDJSON stream
  order.
- **Toolchain lesson**: Rector rewrote a pre-loop closure with by-reference captures into
  an `array_all()` arrow function — arrow functions capture *by value at creation* — and
  silently froze the loop state it read; the suite caught it. Readiness checks now live
  inline in the loop body, where per-iteration capture is correct under any such rewrite.

Remaining Phase 6 slices: supervisor/worker pool speaking the NDJSON protocol as IPC
(brings `#[RunInSeparateProcess]`, LPT partitioning, and G1's parallelism substrate).

---

## Phase 6 — Runner (slice 3: supervisor/worker pool)

### D-022: The NDJSON protocol is the IPC

`src/Runner/Process/`. The nextest architecture, paid for by D-009: workers speak the
same event stream every reporter already consumes, so process isolation and parallelism
needed no second protocol.

- **Input protocol = `WorkerManifest`** (JSON on stdin): configuration path, work units
  (file + optional declared names), ordering + seed. Identities only — the worker
  rediscovers and rebuilds tests on its side; **nothing is ever `serialize()`d across the
  process boundary**. Output protocol = the NDJSON event stream on stdout; there is no
  third channel (stderr is drained purely for crash diagnostics).
- **`crucible --worker`** prints nothing else: no banner, no reporter. The exit code carries
  no verdict — the worker's `run:finish` event is the completion handshake. User `echo`s
  are absorbed by output buffering (the NdjsonWriter writes to the STDOUT *resource*,
  which bypasses PHP's output buffer), so test output cannot corrupt the protocol.
- **`Supervisor`** plans in-process vs worker execution, spawns `php crucible --worker` per
  unit (proc_open, non-blocking pipes, stream_select multiplexing), parses worker lines
  back into event objects (`EventParser`, tolerant: unparseable lines are skipped), and
  re-emits them through its own Emitter — the one true stream, gapless and single-writer;
  listeners (console, NDJSON log, result cache) cannot tell where a test ran. The result
  cache thus accumulates worker durations too, with persistence owned by the supervisor
  alone (workers read the cache for ordering, never write it).
- **Crash accountability** (the nextest lesson): each unit carries the test ids the
  supervisor expects back; a worker reaching EOF without its handshake has crashed, and
  every expected-but-unfinished test is reported errored with the stderr tail as reason.
  A lost worker can never mean silently lost tests — probed by a fixture whose isolated
  test calls `exit()`: the crash is contained, the run continues, exit code 2.
- **Queue scheduling is LPT** (Graham 1969): units sorted by cached-duration cost,
  costliest first, next unit to the first free slot; stable sort keeps unknown-cost units
  in scheduled order. `--parallel N` routes *all* groups through the pool (one unit per
  class — ParaTest's granularity, nextest's supervision); the default stays the
  spec-parity in-process path unless isolation attributes are present.
- **Spec wiring**: `#[RunInSeparateProcess]` and `#[RunTestsInSeparateProcesses]` (each
  marked test → its own worker; dataset rows travel together), `#[RunClassInSeparateProcess]`
  (whole group → one worker, static state persists *within* it). Conformance fixture
  12-separate-process pins all three against the oracle: fresh state in the child, parent
  state surviving the isolated test, and per-class process sharing.
- **`#[Depends]` crosses the process boundary.** A separate-process test receives its
  prerequisite's return value, matching the spec, which serializes the same value into its
  own isolation template. The supervisor plans units from static metadata, before any
  prerequisite has run, so the edge is carried rather than assumed: each unit declares the
  names it needs and the names some other unit needs from it, a unit whose prerequisites are
  not yet produced waits while anything that could still produce them is running, and the
  values travel as base64 of `serialize()` through a temp-file artifact — the same channel
  shape as the coverage artifact, deliberately not the public event stream. When nothing
  active could satisfy a wait, the head unit dispatches anyway, so an unproducible
  dependency reports untested instead of stalling the run. A value no serializer can carry
  is dropped rather than fatal, which degrades to exactly the old behaviour for that one
  test. **`#[PreserveGlobalState(true)]` is honoured**: the parent's user-defined constants
  and its runtime globals are exported at dispatch and restored in the worker *before* its
  bootstrap, so a bootstrap setting the same name still wins. Only what a worker cannot
  reconstruct for itself travels — it re-runs the bootstrap and re-applies the
  configuration's ini settings anyway, and superglobals describe the process they run in,
  so carrying either would only let a stale copy win. Each value is serialized on its own,
  so a global holding a closure or a resource is skipped *by name* on stderr rather than
  taking the export down with it, which is how the spec degrades too. The default is
  unchanged: absent the attribute, or with `(false)`, a child boots clean. One limit is
  shared with the spec rather than particular to Crucible — when the test that *sets* the
  state is itself isolated, its writes never reach the parent, so there is nothing to
  preserve; measured against the oracle under full isolation, both stop at the same tests.

Phase 6 is complete. The pool doubles as G1's parallelism substrate (`--parallel`,
`TEST_TOKEN` arrives with G1).

---

## Phase 7 — CLI & config (slice 1: option surface, selection, stop-on)

### D-023: A closed, typed command line

`src/CLI/CliOptions.php`, `src/Runner/{NameFilter,TestSelection}.php`.

- **`CliOptions`** is the parsed argv as a readonly value object: one nullable field per
  option, null meaning "not given", so CLI-overrides-configuration resolution is a
  null-coalesce (`$options->x ?? $configuration->x`) everywhere — no sentinels. Parsing is
  registry-driven (flags / valued / repeatable-with-commas, both `--name value` and
  `--name=value`) and **closed**: an unknown option or stray argument is an error, never a
  silent no-op. Errors are values (`self|string` return), not exceptions — the CLI layer
  prints and exits, nothing else throws.
- **`--filter` implements the spec's semantics**: a pattern that already parses as a regex
  is used verbatim; anything else becomes an unanchored case-insensitive substring with
  `*` as wildcard, matched against `Fully\Qualified\Class::method` plus the spec's dataset
  spellings (`with data set "name"` / `#index`) so dataset-targeted patterns behave
  identically on both runners. `--group`/`--exclude-group` select by `#[Group]`
  membership, exclusion winning; `--testsuite` narrows discovery (unknown suite names are
  errors).
- **Selection happens after discovery, before scheduling** — ordering, the pool, and the
  workers only ever see selected tests. The supervisor's work units now always carry exact
  id strings (never "the whole file"), so selection survives the process boundary:
  `--parallel N --filter X` runs exactly the same set as `--filter X`.
- **Stop-on semantics live in the runner** (`RunnerOptions`): failure and error each have
  their own switch; `defect` covers both plus risky. The halt happens after the offending
  test, the group's after-all cleanup still runs, and counts reflect only what executed.
- Conformance fixtures 13–15 pin it black-box, each with deselected/unreached tests that
  *fail if executed* — wrong selection or stop semantics cannot conform: filter substring +
  dataset rows, group include/exclude with exclusion-wins, stop-on-failure subset + exit 1.

Remaining at 7: `crucible migrate-config` (the one sanctioned phpunit.xml read), the CLI
long tail as later phases give options meaning (`--testdox`/`--colors` render at Phase 8,
coverage options with the coverage work).

---

## Phase 7 — CLI & config (slice 2: migrate-config)

### D-024: The one sanctioned phpunit.xml read

`src/Configuration/{XmlMigrator,MigrationResult}.php`, `crucible migrate-config`.

- The out-of-scope table declines XML *support*; a **one-time conversion** is how that
  support debt is avoided: read phpunit.xml once, write the typed crucible.php, delete the
  XML. SimpleXML (bundled) at dev time only — no DOM machinery in the runtime path, no
  schema validation, nothing to carry forever.
- **`XmlMigrator` is pure** (XML string in, generated code + notes out; file IO stays in
  the CLI) and **refuses to be silently lossy**: every attribute or element without a
  Crucible equivalent is named both on the console and in the generated file's header
  comment — the migration's gaps are part of its output. The exception: XML plumbing
  (`xmlns:*`) and defaults are dropped without comment, and generated code never restates
  a default, so a near-default phpunit.xml migrates to a near-empty crucible.php.
- Mapped: root booleans (colors, cacheResult, fail-on/stop-on family, backup family,
  testdox), bootstrap, cacheDirectory, executionOrder (enum case; combined `depends,*`
  values become a note — dependency resolution is always on in Crucible), testsuites
  (directories, files, non-default suffix), source include/exclude, php ini/env/const
  with the spec's value coercion ("true"/"false" → bool, numerics → numbers).
- The proof is a round trip: the unit suite migrates a representative document, loads the
  generated file through the real `Loader`, and asserts the typed `Configuration` values
  — the generated code is executed, not string-matched.
- CLI: `crucible migrate-config` (a bare command word, first of its kind in the registry)
  finds phpunit.xml then phpunit.xml.dist, refuses to overwrite an existing crucible.php.

Phase 7 is complete but for the CLI long tail that lands with the phases that give those
options meaning.

---

## Phase 8 — Reporting (slice 1: the reporter set)

### D-025: Four views of one stream

`src/Reporting/{TestDoxReporter,TeamCityReporter,JUnitXmlWriter,MarkdownWriter,PrettyName}.php`.
Every reporter is a Listener; none touches the runner. This is D-009's dividend paid a
third time (conformance was the first consumer, the worker IPC the second): adding four
output formats changed zero lines of execution code.

- **TestDox** (`--testdox`): prettified sentences under their class section
  (`testRunsFastestFirst` → "Runs fastest first"), one mark per outcome (✔ ✘ ↩ ∅ ☢).
  **Buffered and rendered at run:finish**, so sections stay whole no matter how execution
  interleaves — identical output with `--parallel 8` as sequentially. The same buffering
  choice serves JUnit and Markdown.
- **TeamCity** (`--teamcity`): the IDE protocol (PhpStorm's runner tab), stateless
  pass-through so the IDE renders live; full service-message escaping;
  `type='comparisonFailure'` with expected/actual when the failure carries a diff —
  the IDE's diff viewer works. testdox and teamcity *replace* the progress printer,
  they never stack with it.
- **JUnit XML** (`--log-junit <file>`): the one place XML survives (CI interop, per the
  roadmap's out-of-scope table). Plain string building — no DOM dependency for a
  write-only format. Granularity matches what the spec's own JUnit report shows (D-018):
  risky as plain pass, incomplete as skipped; full fidelity lives on the NDJSON stream.
- **Markdown** (`--log-markdown <file>`, Crucible-native): summary table, per-file result
  tables, failure details in code fences — pasteable into a PR description or wiki page.
  Table cells escape pipes; failure text lives in fences where nothing needs escaping.
- `PrettyName` centralizes TestDox prettifying and the spec's dataset spelling
  (`with data set "name"` / `#index`), shared with the JUnit case names.
- **Postponed, recorded**: a PDF 1.4 emitter — dependency-free single-file writer, one
  standard base-14 font, tables and basic formatting only. Deliberately simple; nothing
  about the Listener seam changes when it lands.

Remaining at 8: deprecation management (the Symfony-bridge-grammar superset — needs
PHP-error capture plumbed into outcomes first), `--colors`.

---

## Phase 8 — Reporting (slice 2: issues and deprecation management)

### D-026: Issues are not outcomes

`src/Event/{Issue,IssueKind,DeprecationScope}.php`,
`src/Runner/{IssueCollector,IssueLog,DeprecationBaseline}.php`.

- **The model**: a deprecation, notice, or warning leaves the test's outcome untouched —
  outcomes partition the tests, issues do not (a passing test can carry three
  deprecations). So the run:finish `counts` map stays outcome-only (consumers sum it) and
  issue tallies ride in their own additive `issues` block; per-test issues ride
  test:finish. The spec's "OK, but there were issues!" summary falls out of this shape.
- **Capture**: `IssueCollector` installs an error handler around each test and drains
  after it, on every outcome path. It respects `error_reporting` and `@`-suppression —
  what PHP would not report, Crucible does not count. Occurrences are counted, not distinct
  messages, which fixture 16 pinned against the oracle (a twice-triggered deprecation
  counts twice).
- **Attribution on capture** (the Symfony bridge's scopes, kept): trigger inside the
  project's own directories (test suites + source include list) → `self`; trigger in a
  dependency with the nearest calling frame in project code → `direct`; otherwise
  `indirect`. The scope rides the event, so it survives the worker boundary like
  everything else.
- **Budgets**: `->deprecationThresholds(self:, direct:, indirect:)` — the typed form of
  the bridge's `max[self|direct|indirect]` grammar; null = no limit; a breached budget is
  named and fails the run. `--fail-on-deprecation/-notice/-warning` complete the spec's
  policy switches.
- **Baseline**: a versioned JSON file (`{cacheDirectory}/deprecations-baseline.json`),
  entries keyed file|message (lines shift too easily to be identity). Suppression happens
  *at capture*, so acknowledged legacy exists nowhere on the stream and every consumer
  agrees. `--update-deprecations-baseline` **merges** — workers suppress already-baselined
  deprecations, so a rewrite from visible ones would silently drop acknowledged entries;
  removal is a deliberate edit of the file.
- `--colors[=auto|always|never]` (bare = auto = TTY detection, resolved once into a
  `Style` the console reporter holds; the option never consumes the next argument).

Phase 8 is complete.

---

## Phase 9 — Conformance milestone (the real-world gate)

### D-027: A real Laravel app runs green, unmodified

The milestone run: a freshly created Laravel 13 application (composer create-project,
July 2026), its phpunit.xml converted by `crucible migrate-config` (clean, zero unmigrated
constructs — every skeleton attribute and env var mapped), its test suite extended to a
realistic shape — factories + RefreshDatabase on sqlite :memory:, Laravel's own database
constraints (assertDatabaseHas/assertDatabaseCount), getJson/assertJson/assertJsonStructure,
createMock/createStub with expects()->once(), data providers, exception expectations, and
the framework-booting HTTP kernel tests — **all green on Crucible, sequentially and through
`--parallel 3`, with `composer remove phpunit/phpunit` as the only change to the app.**
That removal is the D-019 drop-in migration story working as designed: Laravel's own
bootstrap (`HandleExceptions::flushHandlersState`) probes `class_exists(PHPUnit\Runner\
ErrorHandler)` and calls into the real PHPUnit runtime if the package is present — with
two runners installed there is no clean seam, which is why coexistence is a policy, not a
patch.

The gate earned its keep by finding the long tail it was designed to find:

- **`addToAssertionCount()`** — the spec method frameworks use to report their own
  assertion layers (Laravel's TestResponse); without it every framework assertion looks
  risky. Added to Assert.
- **The Constraint extension contract**: the spec's `evaluate($other, $description,
  $returnResult): ?bool` is overridable and `matches()` is a default-false hook — a
  subclass overrides one *or* the other. Crucible had evaluate() final and matches()
  abstract; both rejected valid spec-written constraints (Laravel's ArraySubset replaces
  evaluate() wholesale). The base now matches the spec contract; all 126 own tests and 16
  fixtures unchanged.
- **Two alias additions**: `PHPUnit\Framework\Constraint\Constraint` (frameworks subclass
  it for custom assertions) and `PHPUnit\Framework\ExpectationFailedException` (aliased
  into the same failure hierarchy so catch-blocks keep working).

Phase 9 standing: harness + 16 fixtures pin per-feature behavior; the Laravel gate pins
feature *interaction* in production-shaped code. The `vendor/bin/phpunit` shim and artisan
test integration remain G1 items.

---

## Growth G1 — Laravel layer (slice 1: the parallel contract)

### D-028: ParaTest's tokens, nextest's shards

- **`TEST_TOKEN` / `UNIQUE_TEST_TOKEN`** (byte-compatible with ParaTest, the contract
  Laravel's `ParallelTesting` builds on): the supervisor assigns worker *slots* 1..N —
  reused as workers finish, so token-keyed databases and caches are reused, never
  multiplied — and the manifest carries the slot token plus a run-unique per-slot token.
  The worker exports both to `putenv`/`$_ENV`/`$_SERVER` **before the bootstrap loads**,
  because frameworks read them while booting. Only parallel runs set them (ParaTest
  absent ⇒ no tokens ⇒ `ParallelTesting::token()` returns false — verified both ways
  against the real facade in the Phase 9 Laravel app).
- **`--shard M/N`** (nextest partitioning): the Mth of N hash-disjoint suite slices,
  bucketed on the TestId's stable xxh3 hash — which existed for exactly this (D-008):
  adding or removing tests never moves *other* tests between shards, so shard caches and
  timings stay meaningful across commits. Applied after selection, before scheduling;
  unit-tested for the partition properties (disjoint, complete, membership-stable under
  suite growth).
- **Laravel-aware `--init`**: a project with a phpunit.xml gets its configuration
  converted (delegates to migrate-config) instead of a blank template.
- **Assessed and deferred with a concrete finding**: `php artisan test` is Collision's
  TestCommand, and it hard-depends on the PHPUnit dependency tree (it fatals on
  `SebastianBergmann\Environment` the moment phpunit/phpunit leaves the vendor).
  Integration is therefore a bridge package (a service provider registering a
  Crucible-backed `test` command), not a binary shim — the next G1 slice, alongside
  engine-side coverage (which needs the coverage subsystem first).

---

## Growth G1 — Laravel layer (slice 2: the bridge)

### D-029: `php artisan test`, backed by Crucible

`src/Bridge/Laravel/{CrucibleServiceProvider,TestCommand}.php`, discovered via composer
package discovery (`extra.laravel.providers`). Proven by installing cruciblephp/cruciblephp into the
Phase 9 Laravel app as a real composer path dependency — which also exercised D-019's
installed-as-dependency mode for the first time.

- **`TestCommand`** replaces the PHPUnit-coupled command a fresh app ships with: a thin
  translation onto `vendor/bin/crucible` (filter/suite/group/parallel/shard/order/stop-on/
  testdox/baseline options pass through; colors follow the console's decoration), engine
  output streamed untouched, engine exit code returned. Collision leaves with PHPUnit —
  both are runner-coupled dev tooling the migration replaces.
- **The `ParallelTesting` lifecycle contract, completed**: Laravel gates every hook on
  `LARAVEL_PARALLEL_TESTING` *in addition to* `TEST_TOKEN` (its paratest wrapper exports
  it) — a Laravel-specific variable, so the *bridge* exports it inside workers, never the
  engine. Firing `callSetUpProcessCallbacks()` waits for `$this->app->booted()` because
  package providers boot before the app's own, where setUpProcess callbacks are
  typically registered. Verified live: callbacks fire exactly once per worker process
  with the slot token. Setting the gate variable also arms Laravel's own per-token
  test-database machinery, free of charge.
- **Engine fix the bridge surfaced**: config `<env>` values now export to
  `putenv`/`$_ENV`/`$_SERVER` — like the spec's env handler — because frameworks resolve
  `$_SERVER` first and a parent process (artisan) exports its own `.env` values there
  (found as SESSION_DRIVER=database leaking through and 500ing HTTP tests only when run
  via artisan).
- The bridge compiles against illuminate/* deliberately absent from the engine's
  dev-dependencies; it is excluded from repo-side PHPStan and exercised inside the real
  app instead.

Remaining G1: engine-side coverage merge — blocked on a coverage subsystem, which is its
own future phase.

---

## Growth G2 — Pest dialect (slice 1: the core surface)

### D-030: A second frontend, zero engine changes

`src/Dialect/Pest/` — the D-008 bet cashed: `*.pest.php` files compile to the same
dialect-neutral TestGroups every other frontend produces. The engine, the scheduler, the
worker pool, selection, sharding, reporters, and issue capture all work on pest tests
without knowing they exist; `--parallel` re-requires the file in the worker because
manifests carry identities, not closures.

- **Loading**: a file is collected by `PestRegistry` while being required — test()/it()
  return a chainable `TestCall` (with/skip/group/throws/throwsIf/throwsNoExceptions/
  depends), describe() nests by pushing onto a path stack, hooks and uses() accumulate
  per file. Global functions live in a guarded functions.php, loaded only when a
  *.pest.php file is about to be built, and the dialect **refuses outright when
  pestphp/pest is installed** (D-019: never mask a real package).
- **Lifecycle is composed inside the definition closure**: each test binds a fresh
  instance of the uses() class (default `PestTestCase`, `#[AllowDynamicProperties]` for
  the `$this->foo` idiom), runs beforeEach chains, the body with dataset + dependency
  arguments, afterEach in a finally — the engine needed no pest-specific hooks. Names
  join the describe path (`a > b > it works`); `it` carries the spec's literal prefix,
  so `->depends('it works')` matches naturally. Groups become `#[Group]` metadata via
  D-013's programmatic construction, so --group selection just works.
- **expect() compiles to constraints**: every matcher resolves to a Phase 2 Constraint
  (negation wraps LogicalNot), so pest failures render identically to phpunit-dialect
  failures and assertion counting — hence risky detection — is free. `->not` negates
  exactly the next matcher; `->and()` starts a fresh chain; toContain does the spec's
  string/traversable dual dispatch; toThrow invokes the closure and verifies
  class-or-message.
- **Verified by execution**: `tests/unit/Dialect/PestDialect.pest.php` runs inside
  Crucible's own suite, mixed with the phpunit dialect — describe nesting, datasets (named
  and positional, single-value rows), hooks, depends with value injection, skip, throws,
  groups, and the matcher core, sequentially and through the pool. Static analysis skips
  the file ($this binds at runtime; a dialect PHPStan plugin is a G2+ deliverable).

Remaining at G2: the matcher long tail (string-case/file-system/json families), todo/wip,
higher-order tests, `pest()`-level file/dir configuration, `uses()->in()`.

### D-031: expect() completed — the whole documented surface, still zero engine changes

G2 slice 2 finishes the expectation API: every matcher on the spec's documented list
(`spec/pest-api.md` §2) now compiles to a Phase 2 constraint, and the modifier/higher-order
layer is in. `Expectation` remains the only file that grew; the engine, reporters, and
runner are untouched.

- **The long tail maps onto existing constraints wherever one exists** — toBeJson→IsJson,
  toBeNan/toBeInfinite→IsNan/IsInfinite, toBeList→IsList, toBeResource→IsType,
  toBeDirectory/toBeFile→DirectoryExists/FileExists, toContainEqual→TraversableContains
  (loose), toContainOnlyInstancesOf, toEqualCanonicalizing/WithDelta→IsEqual's flags,
  toMatchConstraint passes any Phase 2 constraint straight through. What has no
  constraint compiles to `Callback` with a readable description (string-case family on a
  shared case-pattern vocabulary, toBeBetween on PHP's native ordering so dates work,
  toBeUrl/toBeUuid, readable/writable file-system variants, toMatchArray/toMatchObject
  subset checks reusing IsEqual per pair). Where the docs list both spellings
  (`toEqual*`/`toBeEqual*`), both exist and are one matcher.
- **Dot notation traverses**: `toHaveKey('user.name', $v)` and `toHaveKeys` walk nested
  arrays/ArrayAccess; a composite check compiles to a single constraint so `->not`
  negates the whole claim, not its first clause.
- **Modifiers**: `->each` (property form sets a spread flag consumed by the one private
  assert() funnel; callback form maps expectations over items), `->sequence()` (closure
  gets (expectation, key); bare values mean toEqual; count mismatch is a failure, never
  a silent partial match), `->when()/->unless()`, `->json()` (asserts IsJson, then
  re-roots the chain on the decoded document), `->scoped()`.
- **Higher-order expectations are magic-method fallbacks**: `__get` descends into object
  properties/array keys (`expect($user)->name->toBe(...)`), `__call` prefers extend()ed
  custom matchers, then forwards to the underlying value and chains on the return value;
  unknown calls on non-objects fail as test failures, not PHP errors. `expect()->extend()`
  binds the closure to the expectation, so `$this->value` and built-ins compose.
- **Verified on both paths**: the self-hosted PestDialect.pest.php grew describe blocks
  for the long tail, modifiers, and higher-order access (sequential + `--parallel`), and
  every new failure path was exercised against a throwaway suite — each matcher fails
  with a constraint-rendered message, not a silent pass.

Deferred with reasons: `toMatchSnapshot` (snapshots are an engine capability, planned
once for all dialects), `->match()`/`->dd()`/`->ray()` (debug sugar, no assertion value),
`expect()->intercept()/pipe()` (rare extension points; extend() covers the documented
use). Still remaining at G2: todo/wip, higher-order tests, `pest()`-level configuration,
shared datasets, the dialect PHPStan plugin.

### D-032: The test-definition long tail — body-less tests, and everything stays a build-time decision

G2 slice 3 finishes the spec's §1 vocabulary. The design through-line: everything that can
be decided while the file loads is decided there (chain collection, Cartesian expansion,
platform skips), and everything that needs runtime state composes inside the definition
closure — the engine still has no idea pest exists.

- **A body-less test() is data, not sugar.** The closure parameter went nullable; what a
  body-less call means is decided at build time: no chained methods → a todo; chained
  methods → a higher-order test. `TestCall::__call` collects unknown methods as the
  chain — but only on body-less tests; on a test with a body an unknown chainable is a
  typo and throws at load time, where the mistake is visible.
- **todo/wip/done as a Todo value object.** todo and wip block execution and surface as
  *incomplete* with a structured label (`TODO — assigned to X — issue #N: note`) — the
  spec's todo is a placeholder, and incomplete is the taxonomy's word for exactly that;
  done() cancels the block, runs the body, and keeps the label as documentation. The
  spec leaves wip/done semantics undocumented; this reading is recorded here and cheap
  to adjust when observation says otherwise. `--todos` selection waits for the pest()-
  config slice.
- **Higher-order chains replay against the instance.** The chain starts on the test-case
  instance and each step continues on the previous return value, so
  `->visit('/')->assertVisited('/')` reads the way the spec writes it. An `->expect()`
  step re-roots the chain on an Expectation; its closure is bound to the instance and
  receives the dataset/dependency arguments (the spec's lazy-expectation composition).
- **Skips grew conditions, not mechanisms.** All the platform/CI/PHP variants
  (`skipOnWindows/Mac/Linux`, `onlyOn*`, `skipOnCi`, `skipLocally`, `skipOnPhp` with
  operator-prefixed version_compare) evaluate at chain time and fold into the one
  existing skip state. Closure-form skip is the exception the spec carves out: it is
  stored and evaluated *after* beforeEach, bound to the instance, so it can read
  hook-provided state.
- **fails() inverts inside the same closure**: any throwable except the skip/incomplete
  signals satisfies it (optional message fragment checked), and completing normally is
  itself the failure. throws() gained the spec's dual reading — a non-class string is a
  message fragment — plus throwsUnless().
- **Datasets multiply; repeat is one more factor.** Each ->with() call is a Cartesian
  factor (spec §4); keys join with ' / '; ->repeat(n) appends `repetition i of n` rows.
  describe() now returns a DescribeCall whose group()/skip()/with() apply to every call
  the block collected (nesting included — the registry is flat and ordered, so the
  block's calls are a slice).
- **Verified**: a second self-hosted file, `PestDialectLongTail.pest.php`, runs with a
  real uses() class (`HigherOrderCase`) — todos render their labels as incomplete,
  higher-order chains and lazy expectations pass sequentially and through the pool,
  describe-level group survives `--group` selection, and the failure paths (fails() on
  a passing test, wrong throws fragment, typo'd chainable) were exercised against a
  throwaway suite.

Found in passing: discovery-time ConfigurationExceptions print as raw fatals (pre-dates
this slice; applies to bad uses() too) — flagged for a separate fix. Still remaining at
G2: pest()-level configuration and uses()->in(), shared datasets (dataset()/Datasets
dir, bound/lazy rows), `--todos`, the dialect PHPStan plugin.

### D-033: Suite-level configuration — Pest.php scopes and shared datasets

G2 slice 4 gives the dialect its configuration surface (spec §3) and finishes datasets
(§4). The unit is the **ScopeRegistration**: one pest()/uses() chain = one mutable
registration carrying class, traits, groups, hooks, and ->in() globs. Everything else is
lookup.

- **Configuration is discovered by walking up.** Before a `*.pest.php` file is required,
  `PestScopes::loadConfiguration` loads `Pest.php` and `Datasets/*.php` from every
  directory between the project root and the file, top-down, each once per process —
  outer configuration registers first and therefore applies first. No configured
  "tests directory" needed, and workers repeat the walk naturally when they re-require
  their files.
- **Scoping rules, spec-literal.** ->in() globs are relative to the declaring file's
  directory: a plain name selects a subtree, wildcards go through fnmatch with
  FNM_PATHNAME (so * stays inside a path segment). Without ->in(): a registration in a
  test file is file-local (the classic uses()), and a bare registration in Pest.php
  applies its *hooks* suite-wide (the spec's global hooks — global before* run before
  file-level, global after* after) while bare class/trait/group assignments stay inert,
  per the spec's "bare = file-local" reading. ->in() inside a test file is a load-time
  error. The file's own uses() beats a scoped extend(); among scopes, the innermost
  (registered last) wins.
- **Traits compose by generated subclass.** PHP has no runtime trait application, so
  pest()->use(T::class) produces an eval'd `class … extends Binding { use T; }` — once
  per (class, traits) combination, cached, the doubles generator's eval-once pattern
  (D-017). PestTestCase dropped `final` for exactly this; a final binding class is a
  named ConfigurationException. The legacy `uses(Class::class, Trait::class)->in(...)`
  spelling — the Laravel idiom — splits names by what they are (trait_exists first).
- **Datasets are name → (directory, rows) declarations.** dataset() works in Pest.php,
  Datasets files, and test files; resolution at build time picks the declaration whose
  directory is the nearest ancestor of the test file (the spec's per-folder scoping,
  proven by a shadowing check). Closure-valued datasets re-invoke per use so generators
  replay. ->with() now takes rows, a shared name, or a lazy closure — every form is one
  Cartesian factor.
- **Rows finish the §4 semantics**: associative rows spread as *named* arguments
  (positional dataset arguments and dependency injections come first, named last, so
  the orders compose); a closure-valued dataset argument is a *bound* row, resolved
  after beforeEach and bound to the instance — `fn() => new ArrayObject([$this->x])`
  sees hook state. describe()->with() materializes plain iterables before fanning out
  so a generator isn't drained by the first test.
- **Verified**: `tests/unit/Pest.php` + `tests/unit/Datasets/Brews.php` configure the
  suite's own dialect tests — a global hook every pest file sees, a scoped
  extend+use+group chain that only `PestDialectConfig.pest.php` receives (15 tests:
  binding class, mixed-in trait, hook ordering, named/lazy/bound/associative datasets,
  named×inline Cartesian), `--group pest-configured` selects exactly that file's tests,
  sequential and `--parallel`. Scratchpad checks: nearest-folder dataset shadowing, the
  legacy `uses(Class, Trait)->in()` pattern, and the error paths (unknown dataset names
  the fix, pest() outside Pest.php, ->in() in a test file).

Deferred: `pest()->printer()` (reporting is engine-owned; --testdox/--colors already
exist), `--todos` (needs todo state as engine-visible metadata), the dialect PHPStan
plugin. With this slice the G2 core dialect surface (spec §§1–4) is complete.

### D-034: The crucible dialect v1 — Pest minus the ceremony

G2b decided and built. The audience already knows Pest, so the simplest possible native
dialect is not a new vocabulary — it is the pest vocabulary with the remaining
boilerplate subtracted. **This surface is versioned: what ships here is crucible dialect
v1.** As the pest dialect grows (or v1 usage teaches better shapes), the crucible surface
may be revised in a later version; the version tag in this entry is the anchor for that
evolution.

- **`*.crucible.php` = the pest dialect + two functions.** The same builder serves both
  suffixes; test()/it()/describe()/expect(), hooks, Pest.php scopes, and datasets all
  work unchanged. Nothing to relearn, and the D-019 coexistence rule carries over
  (the shared global vocabulary refuses when pestphp/pest is installed).
- **check() — a test with no name and no nesting.** `check(fn() => add(1, 2))->toBe(3)`
  is one call where Pest needs two; the whole ~60-matcher expectation surface (and
  `->not`) chains directly on the handle, and test chainables (group/skip/with/depends/
  repeat/todo…) still work on the same handle because check() compiles to the D-032
  higher-order machinery: a body-less TestCall with an ->expect() chain step. The name,
  in order of preference: the line's trailing comment
  (`check(...)->toBe(3); // adds small numbers` — tokenizer-parsed, so a '//' inside a
  string can never false-match; //, # and inline /* */ all work), else the source line
  itself (closure reflection → file+line → trimmed snippet, truncated at 100 chars,
  occurrence-suffixed on collision, line-number fallback) — the code is the
  description, and a comment upgrades it to prose. Chains needed one addition:
  property reads (`->not`) now record as chain steps alongside method calls.
- **table() — I/O rows with zero closures.** `table(add(...), [[1, 2, 3]])` uses
  first-class callable syntax as the basic-PHP answer to dataset plumbing: one test per
  row, last element the expected value (toEqual semantics — arrays and objects behave),
  everything before it the arguments. Names auto-generate as the I/O reading
  (`strtoupper('crucible') = 'CRUCIBLE'` via the Exporter); a string row key names the case
  instead. table() returns the describe-style handle, so `->group()/->skip()` apply to
  all rows. Malformed rows are load-time ConfigurationExceptions. This is deliberately
  the D-014 `#[Check]` I/O-table shape — G2c will share the concept.
- **One semantic wrinkle, solved by look-ahead**: `check(fn() => throw ...)` followed by
  `->toThrow(...)` must hand the closure to the expectation *unevaluated* (toThrow
  invokes and observes it itself); every other matcher receives the produced value. The
  chain runner checks the first non-property step after expect.
- **Verified**: `CrucibleDialect.crucible.php` runs self-hosted — checks (incl. duplicate
  source lines staying uniquely named, `$this` binding to the suite Pest.php hook,
  chained group selection), three tables (positional, named-row, array-argument), and
  the pest vocabulary in the same file, sequential and `--parallel`. Failure paths show
  the source line / I/O reading as the test name with full constraint diffs.

Not in v1 (candidates for v2, decided by usage): a `property()` PBT surface (G5 will be
engine-owned and exposed through every dialect), arch presets, `check()` sugar for
async/pipelines. The bar from the decision: simplest possible, basic PHP, least
boilerplate — additions must clear it.

### D-035: G2c — the inline dialect, D-014 realized

Tests inside application source, the fourth D-008 frontend (`Dialect\Inline\InlineBuilder`).
Both D-014 tiers ship; the shape survived contact with reality with a few decisions worth
recording:

- **`#[Check]` attributes** (`Attributes\Check`, method + function targets, repeatable):
  `#[Check([0.0], returns: 32.0)]` calls the annotated target with `args` and compares
  the result on the equality spec (toEqual semantics — deliberately the same claim
  table() makes); `throws:` expects the exception type instead; **a bare `#[Check]` is a
  smoke check** — the call completing without throwing is the claim, counted as one
  assertion (not in D-014; falls out of making both expectations optional, and it's
  honest: `returns:`+`throws:` together is a load-time ConfigurationException, neither
  is "it runs"). String `args` keys bind by parameter name. `name:` names the case;
  otherwise cases are `check N` by attribute position — duplicate names on one target
  are a load error.
  - **Probed and closed: no closures in attributes.** PHP 8.5 still rejects closures in
    constant expressions used as attribute arguments (verified against 8.5.7 — fatal at
    compile), so there is no `verify:` tier; computed claims belong to doctests. If a
    later PHP lifts this, revisit.
  - **Invocation** is `ReflectionMethod::getClosure()`, which binds into the declaring
    scope — so **non-public targets are checkable** (a check on a private helper is
    documentation exactly where the helper lives). Instance targets get a fresh instance
    per check via `newInstance()`; a constructor with required parameters is a load-time
    error naming the escape hatches (static factory or doctest). Free functions come
    from `get_defined_functions()` filtered by declaring file — precise, no token games
    to tell functions from methods and closures.
- **`@crucible` doctests**: one expression per docblock tag (`@crucible expect(...)->toBe(...)`)
  on classes, methods, or functions, compiled at discovery — `eval('namespace <ns>;
  return static function (): mixed { return <expr>; };')` — so a typo is a load-time
  ConfigurationException naming member, file, and expression, never a silent skip. Runs
  on the shared expect() surface (loaded via PestBuilder::ensureUsable, now public static
  — the D-019 refusal with real Pest installed covers doctests too). Known and accepted:
  `use` imports are invisible to eval — expressions spell names namespace-relative or
  fully qualified; the compiled namespace is the declaring class/function's, so sibling
  types read naturally. One line = one expression (D-014's "keep to single expressions"
  enforced by the grammar, not a lint).
- **Discovery**: TestDiscoverer scans the configured `source(include:)` dirs/files
  (excludes honored) for `.php` files passing a marker pre-filter
  (`#[Check` / `Crucible\Attributes\Check` / `@crucible` substrings) — only those get
  require_once'd. Config-driven, no content sniffing beyond the pre-filter; presence of
  markers is the opt-in, no new config. `--testsuite` selection skips inline tests
  (they belong to no suite). Workers re-discover, so `--parallel` needed zero changes —
  ids like `src/Math.php::Math::sum#check 1` survive the manifest boundary because
  TestId::fromString splits on the *first* `::`. **Deviation from D-014's sketch**: ids
  use `Class::method` (not bare `method`) — a file can declare several classes; and the
  mtime-keyed scan cache is deferred until profiling demands it (the pre-filter is one
  read per source file; workers would race a shared cache file — same single-writer
  problem D-021 solved for results, not worth it yet).
- **Metadata**: `#[Group]` on the target or its class travels into the definitions, so
  `--group`/`--exclude-group` work; testdox prettifies inline ids like any other
  (`PrettyName` itself now carries two live checks — Crucible's own `src/` is scanned by
  its own suite config, so the engine dogfoods the dialect: 4 inline tests run in
  `composer test` from `PrettyName::ofFile` checks and `TestId::fromString` doctests).
- Skipped, with reasons: interfaces/traits/enums as check hosts (ClassLocator finds
  classes; interface methods have no bodies — doctests on them are a v2 item if asked
  for), `#[Check]` on TestCase classes (inline is for application source; nothing
  prevents it, nothing tests it), extraction of doctests into shadow files for PHPStan
  (`crucible lint-inline`, D-014's known tradeoff — still the plan when the PHPStan plugin
  work happens).

---

## Growth G3 — inner-loop speed

### D-036: Impact selection — `--changed[=ref]` and `--related`, the Ekstazi half

`src/Impact/`: test selection at file granularity (Ekstazi, ISSTA 2015 — 32% average
end-to-end reduction at low overhead; coarse granularity wins *because* the bookkeeping
is cheap). A test group runs iff the transitive closure of the file that declares it
intersects the change set. Watch mode is slice 2 and builds on this.

- **The graph** (`DependencyGraph`): file → referenced project files, derived **lazily**
  from static analysis (the anti-haste-map lesson: nothing is scanned until a closure
  walks into it; no upfront crawl, no disk cache until profiling demands one) and
  resolved through the **Composer autoloaders** (`ClassLoader::findFile` — the authority
  on name→file; every registered loader is consulted). Vendor files are never nodes: a
  dependency bump surfaces as composer.lock changing, which is an environment change
  (below). Groups map to files via their tests' TestIds, not group names (phpunit-dialect
  group names are class names — ids always carry the declaring file).
- **Reference extraction** (`ReferenceScanner`): token scan collecting use-imports (incl.
  group form and aliases — the group prefix separator tokenizes alone, T_NS_SEPARATOR),
  qualified names, and UpperCamel bare identifiers resolved namespace-relative; skips
  declarations, member accesses, and `use function/const`. **Over-approximation by
  design**: a candidate that isn't a real class resolves to no file, and a surplus edge
  only ever selects more tests — the Ekstazi safety direction. One deliberate double
  reading: `use X;` is absolute as an import but namespace-relative as a trait use —
  same syntax, so unqualified ones register both candidates. PHP's global fallback for
  unqualified names is *not* modeled (that rule is for functions; functions aren't
  tracked — the documented blind spot, shared with Jest's "no dynamic requires").
  Rector note: the scanner keeps per-scan state in instance fields, not by-ref closure
  captures — dead-code rules cannot see through `use (&$x)` and would strip the
  parameters (third occurrence of this gotcha; structure it out instead of skipping
  rules).
- **Pest-family edge**: `*.pest.php`/`*.crucible.php` files depend on every ancestor
  `Pest.php` and `Datasets/*.php` (D-033 loads them per directory) — a shared-dataset
  edit re-runs the files beneath it.
- **The change set** (`ChangedFiles::fromGit`): `git diff --name-only --diff-filter=d
  <ref>` (working tree vs ref — committed, staged, and unstaged in one question) plus
  untracked files from `ls-files --others --exclude-standard` (a brand-new test must be
  selected, not invisible); deletions tracked separately because they defeat the graph
  (the dependents of a deleted file can't be found by scanning it). Bare `--changed`
  means HEAD; `--changed=main` diffs against main. `--related <files>` feeds the same
  machinery without git — the pre-commit-hook shape (Jest's `--findRelatedTests`).
- **Widening rules** (when in doubt, run everything, and say why): any deletion; any
  change to composer.json/composer.lock, the loaded configuration file, or the
  bootstrap. Non-PHP changes are **named, not silently dropped** ("N non-PHP change(s)
  are outside the dependency graph") and select nothing — the Vitest/Jest reading;
  data-file dependencies are what `--related` is for.
- **Wiring**: selection runs right after discovery, before the name/group filters and
  sharding, which narrow further. Zero affected tests → "No tests are affected by the
  given changes." and **exit 0** (distinct from "No tests found", exit 1 — an empty
  impact set is a success, not a misconfiguration). CLI: `--changed` follows the
  `--colors` optional-value pattern (bare or `=`, never consumes the next argument);
  `--related` is repeatable.
- **Deferred with reasons**: per-test covered-files maps (pcov) as the second edge
  source — RESEARCH.md scoped the merge design, but coverage is its own phase; the
  graph's `dependenciesOf` is the seam it plugs into. Disk-caching the scan
  (Ekstazi's checksums) — the lazy scan is milliseconds at this suite size and workers
  would race a shared cache file (the D-021 single-writer problem, not worth solving
  before it hurts). Watch mode, `--standalone`, sticky failed-only, failed-first
  re-runs — slice 2, on this foundation.

### D-037: Watch mode — child-process re-runs, sticky failed-only, the green confirmation

`crucible --watch` (`src/Watch/`), G3 complete. Three layers, IO quarantined to one class:

- **Every iteration is a fresh child process.** PHP cannot re-require changed files in
  one process (redeclare fatals), so a new `php crucible …` per run is the only honest
  reload — nextest's supervisor shape again, one level up. The parent replays the
  user's own invocation (argv minus `--watch`) and appends per-run selection as
  `--related` — watch is a *composition* of D-036 impact selection, not a second
  selection engine. `--order-by defects` is added unless the user pinned an order
  (failed-first, severity-ranked re-runs from the D-021 result cache — "failed-first
  ordering every re-run" came free). The child's **NDJSON stream is the result API**:
  `--log-events-json` to a temp file, `test:finish` outcomes decide the failed set —
  no coupling to the result-cache config, and the same protocol workers speak.
- **`WatchSession`** (pure, no IO, unit-pinned): green → change runs the affected
  files; red → every change-triggered run carries the failed files along — **failed-only
  is sticky across edits by construction** (Vitest's issue #10247, fixed by design,
  not by patch); the first partial run that turns the failed set green earns exactly
  one full-suite **confirmation run** (atoum's loop mode) before the session says
  green. Deletions and non-PHP changes widen to a full run (same widening philosophy
  as D-036). Verified live end-to-end: fail → red status → fix → sticky-set run →
  auto-confirmation → all green, in order.
- **`FileWatcher`**: polling mtime+size snapshots (250ms), no inotify/FSEvents
  extension — a suite-directory scan is milliseconds and polling behaves identically
  everywhere. Hidden and vendor directories are never descended. Debounce: after a
  change, wait for a 150ms quiet interval (editors write in bursts). Watched roots:
  suite directories/files + source includes + the configuration file.
- **Interactive keys** when stdin is a TTY (`stty -icanon -echo`, state saved and
  restored): Enter repeats, `a` runs all, `f` runs the failed set, `q` quits.
  Non-TTY (CI, pipes) degrades to pure polling. Key-triggered runs leave the
  snapshot untouched so an edit racing a keypress still gets its own run.
- **FINDING (`proc_open` fd semantics)**: passing the parent's STDOUT resource as a
  child descriptor does **not** share the file offset — under `crucible --watch > log`
  each child wrote from offset 0, overwriting prior output. Child stdout/stderr are
  therefore piped and copied through the parent (one writer, one offset), and since
  the child then sees a pipe, the parent forwards `--colors=always` when its own
  stdout is a TTY and the user didn't pin `--colors`.
- Not in this slice: `--standalone` (a warm preloaded process is real work once the
  bootstrap cost is measured), snapshot-update keys (`u` — no snapshot feature yet),
  pattern keys (`p`/`t` — `--filter` passthrough already composes).

---

## Growth G4 — flakiness

### D-038: Retry-with-classification, quarantine, and the flakes hunt

The three G4 pillars (Luo FSE 2014, iDFlakies ICST 2019, nextest retry semantics),
all deterministic, all riding existing machinery.

- **Retries** (`--retries N`, `->retries(N)`, per-test `#[Retry(N)]` overriding the
  run-wide budget): the runner's `runTest` became an attempt loop around one extracted
  `attempt()` (full lifecycle per attempt — the definition closure *is* the lifecycle,
  so hooks re-run naturally); only fail/error consume budget, skips/incomplete/risky
  never retry. **A pass on a retry is FLAKY, never silently green**: the event's
  `attempt` field — reserved by D-010 back in Phase 1, used for the first time,
  zero schema change — carries the count, `reason` carries the story ("Flaky: passed
  on attempt 2 of 3 after: <first failure>"), and the console counts flaky tests
  separately. Exit policy: flaky = success by default (nextest's `flaky-result=pass`);
  `--fail-on-flaky` flips it. `--retries` reaches workers via a new nullable manifest
  field — config-based retries need nothing, workers load the config.
- **Quarantine** (`#[Quarantined(reason)]` in code, `->quarantine('file::name', ...)`
  in config for tests you'd rather not edit — full id pins a dataset row, `file::name`
  covers all rows): the test **runs and reports honestly** (outcome stays fail on the
  stream; a new default-omitted `quarantined` payload flag marks it — golden schema
  untouched), but a `FlakinessLog` listener tallies quarantined failures off the
  stream (worker-transparent, the IssueLog pattern) and the exit-code policy subtracts
  them before judging. Quarantined tests that pass are named as release candidates.
  RunSummary stays outcome-only — outcomes partition tests; quarantine is policy.
- **`crucible flakes [--rounds N]`** — the iDFlakies protocol made deterministic by owned
  seeds (D-021's Randomizer orders are replayable): baseline in declaration order,
  N random rounds with derived seeds, then every *new* failure is classified by two
  more child runs — **replay the exact exposing seed** (reproduces → ORDER-DEPENDENT,
  with `crucible --order-by random --random-order-seed S` printed as the repro recipe)
  and **run alone** (fails → broken, not flaky; passes + no reproduction →
  non-deterministic). Every run is a child process (rounds cannot pollute each other),
  outcomes read from the NDJSON stream. The protocol lives in
  `Flakiness\OrderDependencyHunter` with the run injected as a closure — the CLI hands
  in proc_open, the tests hand in a script. Exit 1 when anything is found. Verified
  live on a polluter/victim pair: exposed in round 3, replay confirmed, isolation
  exonerated, printed recipe reproduces byte-for-byte.
- Deferred with reasons: backoff/delay/jitter on retries (network-test ergonomics —
  count covers the classification story; nextest's config shape is the template when
  asked for); JUnit `<flakyFailure>` markup (phase 8 CI-interop item, the reason
  string is ready for it); DeFlaker's "failure outside the diff" hint (shines with
  coverage edges — plugs into the graph when the coverage phase lands); testdox
  flaky marks.
- GOTCHA (4th of its kind): `foreach ($map as $id)` over an `id => true` set iterates
  *values* — booleans became array keys and int(1) reached a string parameter. Sets
  built as key-maps must be consumed with `array_keys()`.

---

## Growth G5 — property-based testing

### D-039: Hypothesis-style PBT — the choice stream, integrated shrinking, every dialect

`src/Property/` — the last planned growth tier. The architecture is Hypothesis's, not
QuickCheck's, and the reason is shrinking: generators draw every random decision as one
recorded non-negative integer from a **choice stream** (`ChoiceSource`), and the
shrinker edits that recorded sequence and replays it — so shrinking survives `map()`
and `suchThat()` untouched, no per-type shrinkers to write, ever.

- **The stream invariants** that make it work: a smaller choice always means a simpler
  value; an exhausted stream yields 0, the simplest choice; replayed choices clamp into
  their bound, so any edited sequence remains valid — it just means something simpler.
- **Generators** (`Gen`): `int(min, max)` maps choices through a bijective "distance
  from zero" ordering (0, 1, −1, 2, −2, … clipped to the range) so integers shrink
  toward the in-range value closest to **zero**, not toward `min`; `bool`, `constant`,
  `elementOf` / `oneOf` (earlier = simpler), `string`, `listOf`, and the `map` /
  `suchThat` combinators (a starved filter is a *ConfigurationException* after a
  discard budget — never a test failure). **Lists use continuation choices, not a
  length prefix** (the Hypothesis encoding): each element is preceded by a "one more?"
  draw, so deleting an element's choices deletes the element — a stale length prefix
  would draw phantom zero-elements. FINDING: an unbiased continuation coin collapses
  average length to ~1 (geometric); the draw is biased 7-in-8 continue, and 0 still
  means stop so shrinking is unaffected.
- **The runner** (`Property::forAll(Gen…)->cases(N)->seed(S)->check(fn)`): 100 cases
  by default from a seeded Mt19937 (the D-021 randomness discipline — replayable,
  printed on failure); the property asserts (preferred) or returns false; skip/
  incomplete signals pass through as test verdicts. On falsification the shrinker
  runs delete-span, zero-span, and halve/decrement passes to a fixpoint under a
  budget, ordered by **shortlex** on the consumed sequence; the report names the case
  number, seed, shrink steps, the compact counterexample, and `->seed(S)` as the
  replay recipe, then rethrows with the minimal failure. Verified shapes: `n < 100`
  falsified shrinks to exactly `100`; "no string contains 'a'" shrinks to `'a'`;
  a list-sum boundary shrinks to the exact boundary (see limits).
- **Dialect exposure** (the D-008 dividend): the runner is engine-owned and runs
  inside any test body — phpunit dialect calls `Property::forAll` directly, `@crucible`
  doctests can too, and the pest/crucible dialects get `property('name', Gen…, fn)`
  sugar (one guarded global + `PestRegistry::property`, generators between the
  description and the closure). This closes D-034's v2 item "property() once G5 lands
  engine-side" — the crucible dialect surface is now v2 in that one respect. Each
  property is one test; each run counts one assertion so a pure-generator property
  never reads as risky.
- **Known limits, stated**: shortlex ordering cannot merge two list elements into one
  (a choice would have to grow), so a sum-boundary counterexample settles as e.g.
  `[10, 90]` rather than `[100]` — boundary-exact in the failing dimension, minimal-ish
  in shape; Hypothesis-grade redistribution passes are a v2 shrink refinement.
  No float generator yet (lexicographic float encoding is real work, deferred until
  asked for). No failure database (Hypothesis replays known-failing examples before
  random ones — needs cacheDir plumbing into test bodies; deferred with the seam
  noted). Rector note: its type inference does not model int overflow-to-float, so
  the `Gen::int` width guard tests `PHP_INT_MAX + $min` *before* subtracting — an
  `is_int()` check on the difference gets "simplified" away.

### D-040: The D-039 limits closed — merge pass, floats, the failure database

The three items G5 shipped without, each built on the seam D-039 left for it.

- **Redistribute-and-delete shrink pass.** The `[10, 90]`-not-`[100]` local minimum:
  raising a choice is never shortlex-simpler, but the compound edit — add choice *i*
  onto a nearby choice **and** delete *i*'s span in the same candidate — yields a
  strictly *shorter* sequence, which shortlex accepts outright. Structure-blind (both
  span guesses are tried: continuation-bit+value and bare value; replay clamping
  absorbs the misses), window of ±8 positions, still budget-bounded. The list-sum
  boundary now shrinks to exactly `[100]`.
- **`Gen::float(min, max)`.** Finite bounded floats, structured for shrinking: a
  biased leading choice picks the in-range *specials* pool (0.0, 1.0, −1.0, the
  bounds — earlier is simpler) or the continuum (integer part on the int ordering
  toward zero + a dyadic n/65536 fraction that shrinks toward whole numbers, clamped
  into range). Falsifications land on named values — `f < 0.5` over [0, 10] shrinks
  to exactly `1.0`. Non-finite edges (NAN, ±INF) are never produced by a bounded
  generator; compose them explicitly via `oneOf(elementOf([NAN, INF, -INF]), …)`.
  Full lexicographic IEEE-754 encoding (reversed mantissa, re-biased exponent)
  remains future refinement, not a current need.
- **The failure database** (Hypothesis's example database), on existing patterns
  end to end. *Reading*: `TestRunner` opens an ambient `PropertyContext` per attempt
  (test id + the database loaded through `RunnerOptions`, like the deprecations
  baseline; per-attempt so retries claim identical keys); each `check()` claims
  `testId#property N` and **replays stored sequences before any random case** — a
  bug that once falsified is caught on case 1 forever, whatever today's seed is,
  already shrunk ("falsified by the failure database, 0 shrink steps"). *Writing*:
  the shrunk sequence rides a `PropertyFailedError` (AssertionFailedError unfinal'd
  for it — a failed assertion that also carries its replay data) onto the
  `test:finish` payload as a default-omitted `property` field (golden schema
  untouched), and a supervisor-side `PropertyFailureWriter` persists at run:finish —
  the ResultCacheWriter pattern, so workers never write (D-021 single-writer rule);
  verified live under `--parallel`. Storage: versioned
  `{cacheDir}/property-failures.json`, newest-first, deduplicated, five sequences
  per key, corrupt file = empty database. Entries whose property changed shape
  replay as inert (`CannotGenerate` → skipped). Known non-feature: entries are not
  pruned when fixed — they cost one replayed case each and vanish only by editing
  the file; honest pruning needs resolution signaling on the stream, deferred until
  it hurts. `check()` without a runner context (direct engine use) runs exactly as
  before — no key, no database, plain AssertionFailedError.

---

## Coverage subsystem

### D-041: Line coverage — drivers, per-test collection, worker merge, observed impact edges

The backlog's highest-leverage item: coverage as its own subsystem (`src/Coverage/`),
slice 1. **Strictly opt-in by design**, and the reasons are stated to users (README):
a driver extension is an install decision with real friction — upstream pcov is
effectively unmaintained and does not compile on PHP 8.4/8.5, so working builds come
from distribution packagers (Debian/Ubuntu: Sury; macOS: the shivammathur tap) or
xdebug — and a per-test collection window costs real time no default run should pay.
No flag, no collection, zero overhead.

- **Drivers**: one interface (`start()` / `stop(): file => line => value` in the
  ecosystem's shared convention — >0 executed, -1 executable-missed, -2 dead code,
  verified against xdebug 3.5 live), `PcovDriver` preferred, `XdebugDriver` fallback
  (UNUSED+DEAD_CODE analysis on, so percentages have honest denominators). Runtime
  detection (`DriverFactory`), and the no-driver failure message carries the exact
  per-platform install commands — an opt-in feature owes the user the way in. Raw
  extension output is normalized through one typed pass (`Lines`) — "the extension
  said so" is not a type, and the cost matches what recording iterates anyway.
- **Collection**: one driver window per test attempt in the runner (the definition
  closure is the whole lifecycle, so hooks attribute to their test), filtered to the
  configured `source(include:)` — the spec's `<source>` element *is* the coverage
  scope. FINDING: drivers report every **loaded** file's unexecuted lines in every
  window, so a file counts as a test's dependency only when a line actually ran —
  without that rule every test appeared to depend on every loaded source file
  (41/41 selection), with it the map is exact (7/41).
- **`CoverageData`**, two granularities in one mergeable value: aggregate line values
  per file (reports; merge by max — any execution upgrades a miss) and per-test
  executed-file lists (the impact map; later DeFlaker and mutation targeting).
  **Coverage crosses the process boundary as artifact files, not events**: it is bulk
  data like the result cache, not protocol — workers write one JSON each where the
  manifest points ({cacheDir}/coverage.tmp), the supervisor side merges, the NDJSON
  schema is untouched. FINDING: workers do not inherit the parent's `-d` flags, so
  the supervisor re-creates the active driver's loading on each worker command line
  (`-d zend_extension=xdebug.so -d xdebug.mode=coverage` / the pcov pair) — without
  this a parallel coverage run collects exactly nothing.
- **Reports**: `--coverage` console summary (per-file % + total, project-relative) and
  `--coverage-clover <file>` (string-built, output-only XML — the sanctioned CI-emitter
  exception, like JUnit). Crucible's own suite: 64% line coverage on first sight.
- **Observed impact edges — the G3 unlock**: every coverage run refreshes
  `{cacheDir}/coverage-map.json` (test file → project files its tests executed,
  relative, versioned, corrupt = empty). The impact graph seeds these into the closure
  **one hop from the root only**. Two reasons, both learned live: (1) coverage is
  already transitively complete — it recorded everything the test executed, through
  every dynamic layer; (2) a file can be both production code and a test carrier
  (inline dialect), and following observed edges from inner nodes conflates "this test
  runs file X" with "file X's own inline tests call Y", over-selecting wildly.
  Measured proof of the unlock: `--related src/Dialect/Pest/functions.php` (loaded via
  a string `require` — statically invisible) selects **0** test files on static edges
  alone, a real false negative; with observed edges, exactly the 7 files whose tests
  execute it. Precision elsewhere unchanged.
- Deferred, next slices: per-test **line**-level maps (mutation's query API), branch
  coverage (xdebug can; pcov cannot), HTML/cobertura reports, `#[CoversClass]`
  enforcement (`beStrictAboutCoverageMetadata` parity), `artisan test --coverage`
  bridge pass-through, and the DeFlaker "failure outside the diff" hint now that its
  data source exists.

---

## Snapshot testing

### D-042: Snapshots — one engine assertion, every dialect, explicit updates only

`src/Snapshot/` — the D-031 deferral built as designed: an engine capability, once,
with the Exporter as the serializer (D-012 wrote "also for future snapshots" into its
charter; that bill came due and was already paid).

- **The assertion**: `Snapshots::match($value, ?$name)` compares `Exporter::export()`
  output against the stored entry. Missing → **fail**, naming `--update-snapshots`;
  mismatch → fail with the full line diff (assertSame on the serialized strings — the
  LCS differ was already there). **Values are never recorded as a side effect of a
  normal run** — Jest's write-on-first-sight is implicit state mutation during a test
  run, a known flakiness source and a CI surprise; Crucible's principle is deterministic
  and explicit, so `--update-snapshots` / `-u` is the one recording path, sequential
  or `--parallel` (a manifest flag carries it to workers).
- **Dialect exposure** (the D-008 dividend, third time): `assertMatchesSnapshot()` on
  Assert/TestCase (phpunit dialect + doctests), `->toMatchSnapshot()` on Expectation
  (pest + crucible dialects; `->not` refuses — a snapshot records, it does not enumerate
  what a value is not). Both are one-line shims over the engine call. Multiple
  snapshots per test number themselves; `$name` pins a stable key. Context follows
  the PropertyContext pattern — ambient per attempt, so retries count identically.
- **Storage**: `__snapshots__/<test-file>.snap` beside the test file (the Jest
  convention — snapshots travel with the tests through review). An owned plain-text
  format: `>>> key` header, content indented two spaces, bare `<<<` terminator.
  The indent makes the format collision-proof (content can never read as a
  terminator) and the parser accepts fully-blank lines inside entries, so editors
  that trim trailing whitespace cannot corrupt a snapshot — both properties pinned
  by a hostile-content round-trip test. Saving merges over disk state: a filtered
  `-u` run updates only what it ran, unvisited keys survive.
- **Watch integration**: the `u` key (the gap D-037 explicitly left open) — one more
  full child run with `--update-snapshots` appended.
- Dogfooded in-tree: committed `.snap` files for the phpunit-dialect and
  crucible-dialect surfaces; tampering with one produces the precise stored-vs-actual
  diff naming the key and the flag.
- Deferred with reasons: inline snapshots (source-rewriting the expected value into
  the call site — invasive machinery, wants the same code-rewrite infrastructure as
  future auto-fixes); obsolete-snapshot pruning (needs "this run visited everything"
  knowledge — only true unfiltered, and stats plumbing across workers with it);
  custom serializers per type (the Exporter is canonical on purpose; escape hatch =
  serialize before asserting). Known limit: update mode combined with
  per-test process isolation on one file can race the merge-on-save; last writer
  wins per key (the combination is rare; documented, not defended).

---

## G4 leftovers

### D-043: Retry backoff and the Surefire flaky markup

The two items D-038 deferred, closed together because they share a data need.

- **`RetryPolicy`** replaces the bare retry count everywhere (Configuration,
  RunnerOptions, `#[Retry]`): count + backoff shape (`Backoff::None|Fixed|Exponential`
  — nextest's vocabulary) + base delay + optional cap + optional jitter (a uniform
  factor in [0.5, 1], the thundering-herd spreader for network-flavored suites).
  `->retries(3, Backoff::Exponential, delay: 0.5, maxDelay: 10.0, jitter: true)` in
  config; the same named arguments on `#[Retry]` per test (enum cases are constant
  expressions — attributes take them natively). CLI `--retries N` overrides the
  *count* while the configured shape survives (`withCount()`), sequential and via the
  worker manifest. Jitter randomizes timing only — no outcome ever depends on it,
  which is why it needs no seed plumbing and does not violate the determinism
  principle. The runner pauses between attempts with `usleep`; the pauses are pinned
  by wall-clock tests.
- **Per-attempt failures on the event**: `test:finish` gains a default-omitted
  `retried` list carrying each failed attempt's structured Failure — the data D-038's
  reason-string deliberately skipped, now needed because the JUnit story wants real
  messages per attempt. EventParser round-trips it; the golden schema is untouched
  (empty = omitted).
- **Surefire markup in the JUnit writer**: a pass that needed retries stays a plain
  pass for consumers that only read outcomes, and carries one `<flakyFailure
  type message>` per earlier attempt for the CI dashboards that track flakiness;
  a final failure carries `<rerunFailure>` (errors: `<rerunError>`) per earlier
  attempt beside its `<failure>`. Verified live, sequential and `--parallel`.
- Nothing new crosses into RunSummary or the exit policy — flaky classification is
  unchanged from D-038; this slice is shape (when to retry) and reporting (what CI
  sees), not semantics.

---

## G3 leftovers

### D-044: `--standalone` and the scan cache — declined on measurement

The two items D-036/D-037 deferred behind an explicit profiling gate. The profiling
ran (2026-07-15, opcache CLI off — the worst case); both decline. This entry is the
evidence, so the decision is re-openable against numbers rather than moods.

- **The question `--standalone` asks**: how much of a watch-cycle child run is fixed
  overhead a warm preloaded process could skip? Measured as a zero-selection run
  (`--related README.md` — interpreter start + autoload + engine + configuration +
  discovery, including the inline-marker scan, + impact scan, then exit):
  **~70ms** on Crucible's own suite (359 tests, 295 project files), of which a bare
  `php -r 'require vendor/autoload.php;'` is already ~40ms. On a **real Laravel 13
  app** (recreated per the D-027 recipe, migrate-config'd): **~60ms**, ~50ms of it
  bare interpreter + autoload. The number everyone expects to be huge is not there,
  because the expensive part of a framework test run — booting the application —
  happens inside each test's `setUp()`, which no warm process can skip.
- **Against the latency floor the watcher already has by design** (D-037: 250ms
  mtime poll + 150ms quiet debounce ≈ 400ms save-to-child-start), the ceiling on
  what warmth can recover is ~70ms of a ≥400ms path — imperceptible at the keyboard.
  And the warmth could never cover more than vendor + engine code: PHP cannot
  re-require changed files in one process (redeclare fatals — the exact finding that
  made D-037 child-per-run in the first place), so test files and application code
  reload every run regardless. Even the zero-engineering variant — the user turning
  on `opcache.enable_cli` + `file_cache` — was measured: 70ms → 60ms. There is
  nothing left for a fork-server to win.
- **The question the mtime scan cache asks**: does the lazy dependency-graph scan
  (D-036) cost enough per run to justify cross-run persistence (Ekstazi's checksum
  design)? Worst case measured — cold closures of **every** test file, the shape of
  a `--changed` touching a core file: **28ms** cold, 0.5ms memoized, ≈0.1ms per file
  for token scan + autoloader resolution. A cache could recover at most those 28ms,
  and would have to solve real problems to do it: a shared cache file races workers
  (the D-021 single-writer lesson — persistence would be supervisor-only), and
  invalidation-by-mtime is weaker than the content it stands for. The scan's actual
  weakness was never speed but **precision** — statically invisible `require`s —
  and D-041's observed coverage edges already closed that.
- **Re-open condition, stated**: at ~0.1ms/file the cold scan reaches ~1s around
  10k project files. A real suite at that scale demonstrating the scan dominating
  its `--changed` runs re-opens the cache (design sketch already in D-036);
  a measured bootstrap ≥ the watcher's own latency floor re-opens `--standalone`.
  Neither exists today, and speculative infrastructure is the legacy this project
  sheds.

---

## G2 extended tier — the todo listing

### D-045: `--todos` — todo becomes engine-visible metadata

D-032 built todo/wip/done as a dialect-local affair: a `Todo` value object in
`src/Dialect/Pest`, consumed inside the definition closure, which threw the incomplete
signal at execution time. Correct outcomes, but the engine could not *see* a todo
without running it — and the `--todos` listing (spec §7) wants exactly that knowledge.
This entry moves the marker one layer down and builds the listing on top.

- **The attribute IS the metadata (D-013, again)**: `Attributes\Todo` — status
  (`TodoStatus` enum, todo/wip/done; enum cases are constant expressions, so
  `#[Todo(TodoStatus::Wip)]` works natively, the D-043 lesson) + assignee + issue +
  note, with `blocksExecution()` and `label()` moved over from the dialect VO, which
  is deleted. PestBuilder resolves the marker at build time — the explicit
  `->todo()/wip()/done()` chain, or the body-less-no-chain auto-todo — and attaches
  it to the definition's MetadataCollection; the definition closure no longer knows
  todos exist.
- **The semantics live in the runner, once**: a todo/wip marker returns incomplete
  with its label before anything else is considered — before requirements and
  dependency checks, because a placeholder's requirements are irrelevant (pinned by
  test: `#[Todo]` + `#[RequiresPhp('>= 99.0')]` reports incomplete, not skipped).
  A done todo runs normally, the marker as documentation. Observable outcomes are
  unchanged from D-032 — the suite's todo incompletes count identically — only the
  mechanism moved. One ordering note, spec silent, our reading: the todo check now
  precedes the bool-form skip (previously skip won inside the closure); a todo is
  the stronger statement — there is no body to skip.
- **The dialect dividend (D-008, fourth payout)**: the phpunit dialect gets
  `#[Todo(assignee: 'x', issue: 31, note: '…')]` on test methods for free — the
  metadata parser collects any CrucibleAttribute via IS_INSTANCEOF, zero parser
  changes. Dogfooded: a method in `TodoTest` carries the attribute and is the
  suite's own proof that the parser reads it, the runner blocks it, and the
  listing shows it.
- **The listing is a selection, not a report**: `--todos` (flag) and
  `--assignee <name>` / `--issue <id>` (valued) land in TestSelection beside the
  group filters — after discovery, before scheduling, supervisor-side, so workers
  need nothing. Semantics pinned by black-box observation of the real pest 4
  binary (the sanctioned spec method): `--todos` selects every todo-marked test
  *including* done ones; `--assignee`/`--issue` each stand alone and imply todo
  membership (a non-todo test never matches). Since blocked todos never execute,
  **running the selection is the listing** — the console reporter's incomplete
  labels are the output, zero new machinery. Verified under `--parallel 2`.
- **An empty listing is a healthy answer**: `No todos match.`, exit 0 — the D-036
  distinction (`No tests are affected` ≠ `No tests found`) applied again.
- Observed deviation, recorded: pest 4 *runs* `->wip()` bodies; Crucible keeps the
  D-032 reading (wip blocks — work in progress is not done). Not carried:
  `--notes` (pest's `->note()` is a general per-test annotation, independent of
  todos — its own growth decision if ever wanted).
- 367 suite tests, 16 fixtures conform.

---

## Doubles — the parity tranche

### D-046: getMockBuilder, partial mocks, intersection doubles — the last parity gap

D-017 shipped the doubles core and named its later tranche; this entry closes the
*parity* half — the builder API any real-world PHPUnit suite may call — and leaves the
growth half (Mockery-compatible surface, Prophecy hooks) as its own future decision.
Spec pinned black-box: reflection over the oracle's public API (what IDE autocomplete
shows every user) plus behavior probes run through the oracle binary, recorded here.
PHPUnit 13's builder is pleasantly slim — `addMethods()` and mocking-unknown-types are
gone — so full parity is a bounded surface, not an archaeology dig.

- **`DoubleSpecification`** (readonly VO): the doubled type(s), the doubled-method
  subset, constructor/clone/auto-return switches, optional class name. Its
  `classIdentity()` is the generator cache key — constructor and auto-return settings
  are instantiation state, not code, and stay out of it. The Generator takes a
  specification; `doubleClassFor(type)` remains as the all-methods shorthand.
- **Partial doubles are absent overrides**: under `onlyMethods()` the generated class
  simply does not override unlisted methods — real code runs, no dispatch, no
  bookkeeping. Abstract methods are always generated (they cannot be left
  unimplemented). Probed defaults, all matched: the builder **runs the original
  constructor** (with `setConstructorArgs()` args) unless disabled;
  `createPartialMock()` never runs it; `onlyMethods([])` doubles nothing; the original
  `__clone` runs unless `disableOriginalClone()` (suppression is generated code — an
  empty `__clone` override). `createMockForIntersectionOfInterfaces()` doubles several
  interfaces into one class. FINDING: built-in interfaces declare **tentative** return
  types (`Countable::count(): int`) that `getReflection()->getReturnType()` does not
  report — without `getTentativeReturnType()` every such override is a deprecation.
- **Configuring what was not doubled is a named error** (the oracle errors too, in its
  own words): `MethodConfigurator` knows the configurable set and refuses at
  `method()` time — a silent no-op configurator is worse than an error. Same for
  unknown names in `onlyMethods()` at build time; `onlyMethods()` on an interface —
  where the oracle **fatals** on the unimplemented remainder — is a named
  `DoubleCreationException` (better-than-incumbent, recorded). Disabled auto-return
  generation refuses unconfigured calls via `DoubleConfigurationException`.
- **The expectation-less-mock advisory**: PHPUnit 13 emits a runner notice when a
  mock-flavored double ends its test without a single `expects()` — observable
  surface, so Crucible has it: creation paths carry a mock/stub flavor, settlement emits
  one `E_USER_NOTICE` per offender through the existing issue pipeline (D-026 — it
  lands in the notice counts, respects `--fail-on-notice`, crosses workers), and
  `#[AllowMockObjectsWithoutExpectations]` (method or class) opts out. The attribute
  aliases into the PHPUnit namespace automatically via the D-019 glob.
  `createConfiguredStub()` had to stop delegating to `createConfiguredMock()` —
  a stub must not advise.
- **BUG FOUND AND FIXED (the tranche's dividend)**: the pest dialect **never verified
  double expectations** — an unmet `->expects($this->once())` passed silently, since
  D-030 composed its own lifecycle and the settlement lived only in `invokeTest()`.
  No conformance fixture could see it (the oracle runs no pest dialect). Settlement
  is now a public seam — `TestCase::verifyTestDoubles()` — called by `invokeTest()`
  and by the pest definition closure on the same paths the spec uses (skipped when an
  expected exception was consumed; inside the `fails()` window, so inverted tests
  treat verification failures as their expected failure).
- **Stubs are mocks, still** (D-017): `getStubBuilder()` returns the one builder —
  `getStub()`/`setStubClassName()` are spellings, not a second class. Compat aliases
  added: `MockObject`/`Stub` → the `Mocked` marker, `MockBuilder`/`TestStubBuilder` →
  the builder. GOTCHA (4th rector-vs-fixture): constructor-promotion rectors would
  rewrite the builder-test fixture so "was the constructor bypassed?" becomes
  unobservable — skipped for that file.
- **Conformance fixture 17-builder-doubles** pins it all against the oracle: nine
  tests including an unmet-expectation failure, a configure-undoubled error, and the
  six advisory notices — outcome counts, notice counts, and exit code all conform.
  384 suite tests, 17 fixtures.

---

## Coverage slice 2

### D-047: Per-test line maps — the mutation query's data source

The first of D-041's deferred slices, chosen because another backlog row (mutation
testing interop) names it as its dependency: a mutation tool's first question is
"which tests execute the mutated line?", and file-level granularity cannot answer it.

- **`CoverageData::tests` gains a dimension**: test id → executed file → **sorted
  executed lines** (a file still counts only when a line actually ran — the D-041
  loaded-is-not-executed rule, now with the evidence attached). `record()` was
  already receiving per-line values and throwing the detail away; keeping it costs
  one sort per file. Worker artifacts round-trip the richer shape unchanged —
  the merge story (bulk artifact files, not events) needed zero changes, and the
  golden NDJSON schema is untouched.
- **`TestLineMap`** is the persisted, query-facing form: project-relative,
  versioned, corrupt = empty, written to `{cacheDir}/coverage-lines.json` beside
  the impact map on every coverage run. `testsCovering(file, line)` is the
  mutation-facing query. Timing-aware ordering (run the cheapest covering test
  first) is deliberately NOT here — durations already live in the result cache,
  and joining them is the mutation tier's one-liner, not this data structure's
  concern.
- **The impact map is now a projection**: `CoverageMap` derives its file-level
  edges from the line-level truth (`array_keys` of the per-file map) — one
  collection, two granularities, no second bookkeeping path.
- Verified live on Crucible's own suite (xdebug on-demand): 381 tests in the map,
  424KB JSON — and the query answers exactly: `Todo::blocksExecution()`'s line is
  covered by precisely the four TodoTest methods that exercise it, sequential and
  `--parallel 3` identical through the worker merge.
- Remaining coverage slices unchanged in ROADMAP: branch coverage, HTML/cobertura,
  `#[CoversClass]` enforcement, the artisan bridge pass-through, the DeFlaker hint
  (whose data source this slice completes). 385 suite tests, 17 fixtures conform.

---

### D-048: The DeFlaker hint — failure outside the diff, plus the artisan coverage pass-through

The G4 deferral D-038 parked ("shines with coverage edges") and D-041/D-047 unlocked:
DeFlaker's classification (Bell et al., ICSE 2018 — a new failure that executed no
changed code is flaky with 95.5% recall, 1.5% false alarms, **no reruns**), riding
entirely on data that already existed. Nothing is collected for this feature; it is
one intersection over the per-test line map and a `git diff`.

- **The check** (`Flakiness\FailureOutsideDiff`, pure logic, fully unit-tested):
  a failing test is a suspect when the line map knows it and none of its executed
  files appear in the changed set. **Soundness rules over recall**, decided case by
  case: (1) only fresh failures qualify — the result cache's previous outcome is
  consulted, and a test that already failed last run was broken before the diff
  existed, so the diff can be neither blamed nor cleared; (2) a failing test whose
  own declaring file changed is never a suspect; (3) a changed test file attributes
  only to the tests it declares — another test's edit cannot break this one except
  through order pollution, which is flakiness anyway; (4) any other changed PHP —
  bootstrap, out-of-scope helpers, deleted files, PHP outside the project root —
  makes "executed no changed code" unanswerable, and the answer is **silence, not a
  guess**. Non-PHP changes are outside the model (coverage cannot observe them);
  DeFlaker shares the limit. Scope entries match exactly for files, by prefix only
  for directories — `src/A.php` does not claim `src/A.php.bak`.
- **The wiring**: `FlakinessLog` (already on the stream, worker-transparent) now
  keeps the non-quarantined failure ids; after the flakiness notes the CLI loads
  `{cacheDir}/coverage-lines.json`, diffs against `--changed`'s reference or HEAD,
  and prints the suspects with the `crucible flakes` pointer. **Advisory only** —
  outcomes, RunSummary, and the exit policy are untouched; every missing ingredient
  (no failures, no map, no git) is an early return, costing a normal run nothing.
- Verified live in a git fixture project: the environmental failure (a data file
  flipped, an unrelated source file edited) is named as outside the diff, sequential
  and `--parallel 2` identical; repeat failures, own-file edits, bootstrap edits, and
  failures that did execute a changed file all stay silent.
- **Artisan pass-through**, the other bite-size coverage leftover: `artisan test
  --coverage` and `--coverage-clover=` now forward through the Laravel bridge's
  option translation — two list entries, no new machinery.
- Known limit, stated: the map is as fresh as the last coverage run. A stale map can
  misattribute; the gate conditions bound the damage (an unknown test is skipped,
  not guessed about), and the wording says "likely", because that is what it is.

---

## Static analysis

### D-049: The PHPStan extension — assert narrowing, analysis-time aliases, `crucible phpstan-init`

The backlog's "one package, four wins" row, slice 1 — and the packaging question it
forced was settled first, by the user: **one package, no shim, no second Packagist
entry**. The extension ships inside cruciblephp/crucible (`phpstan/extension.neon` + classes
under `src/PHPStan/`), consumers wire it with a single `includes:` line pointing into
their own vendor directory — exactly how this repository already consumes
strict-rules — and `crucible phpstan-init` writes that line for them. Occupying foreign
names stays reserved for the D-019 compat layer, where it is the only way; the
extension never plays that game. phpstan/phpstan is not a dependency: the classes
only load inside a running PHPStan, which guarantees their interfaces present.

- **Narrowing as translation, not type logic**: every supported assert method maps
  to the ordinary PHP condition it guarantees (`assertNotNull($x)` → `$x !== null`,
  `assertIsInt($x)` → `is_int($x)`, `assertInstanceOf(C::class, $x)` →
  `$x instanceof C`, the is-family, same/count/empty/list) and the condition is
  handed to PHPStan's own TypeSpecifier. One table (`AssertConditions`), zero
  bespoke logic — everything PHPStan learns about conditions applies to assertions
  for free. Two thin wiring twins (static call + the spec's dynamic-call idiom);
  `getClass()` = Assert, so every TestCase subclass narrows, and the D-019 aliases
  make the PHPUnit-shaped spellings narrow through the same classes with no second
  code path. Unsound shapes (unpacked arguments) narrow nothing — silence, not a
  guess. FINDING: named arguments need no bespoke handling either — PHPStan
  normalizes them before type-specifying extensions run (pinned by the fixture).
- **Analysis-time aliases**: the extension registers a guarded bootstrap as a
  `bootstrapFiles` entry, so the same class_alias calls that make a PHPUnit-shaped
  suite run make it analyzable — the Laravel story's missing half (with
  phpunit/phpunit removed from vendor, `Tests\TestCase → Illuminate → PHPUnit`
  dangles for PHPStan and Larastan until these aliases resolve it). FINDING: the
  runtime coexistence policy check is *meaningless* at analysis time — inside
  phpstan.phar, Composer's InstalledVersions answers for the phar's own bundled
  vendor tree and reports phpunit/phpunit installed. The only trustworthy signal
  there is loadability: alias exactly when the real `PHPUnit\Framework\TestCase`
  is not autoloadable from the analyzed project.
- **API discipline, found by the gate**: `new SpecifiedTypes()` and
  `instanceof TypeSpecifier` are outside PHPStan's backward-compatibility promise
  (phpstanApi.* reports on our own analysis) — the no-condition path instead
  narrows from a literal `true`, which specifies nothing using only promised API.
- **Self-hosting circularity, named and scoped**: with narrowing on, PHPStan's
  redundancy rules correctly flag the suite's constant-input spec pins
  (`assertTrue(true)` IS the assertTrue spec; asserting instanceof on a generated
  double verifies codegen the analyzer takes on faith). Those reports are the
  feature in user projects and circular in this one — the prover trusts the very
  code under test — so the repo config opts out of the three redundancy
  identifiers for `tests/unit` only, reason documented in place. The engine's own
  five `assertTrue(true)` count-an-assertion sites were the same tautology with
  less excuse: replaced by an explicit engine-internal
  `Assert::countSatisfiedAssertion()`.
- **`crucible phpstan-init`** (third bare-word command): no config → generates
  phpstan.neon with the include plus `paths:` derived from what crucible.php already
  declares (suites + source); existing config → conservative line-based insert
  under `includes:` matching neighbour indentation, idempotent, comments survive;
  inline-list shape or any surprise → prints the exact line to add and refuses to
  guess (the migrate-config principle — a full neon rewrite would need a
  dependency and would destroy formatting). PHPStan absent → names the
  `composer require`, the D-041 way-in rule.
- **Dogfooded twice**: the repo's own phpstan.neon includes the extension, and 17
  narrowing guards (`if (!$x instanceof Y) fail()` / `?? self::fail()`) became
  plain assertions — PHPStan max staying green through our own extension is the
  standing regression net for narrowing. The alias half is pinned black-box, the
  conformance pattern applied to types: one fixture extending the PHPUnit-shaped
  name, analyzed by the real phpstan binary in a child process, expected to yield
  exactly one deliberate sentinel error.
- Remaining plugin slices unchanged in ROADMAP: dialect closure-this stubs for
  `.pest.php`/`.crucible.php`, `Gen` @template annotations + the forAll signature
  extension, doctest shadow extraction (`crucible lint-inline`).

---

### D-050: Dialect analysis — closure-this from uses(), the magic grammar as reflection

PHPStan extension slice 2: `.pest.php`/`.crucible.php` files were analysis-blind (five
excludePaths entries in this repo's own config existed solely for them). Not stubs in
the end — PHPStan 2.x grew the exact extension points the runtime semantics need, and
dynamic beats static templates:

- **`$this` from the file's own uses()**: `FunctionParameterClosureThisExtension` /
  `MethodParameterClosureThisExtension` receive the call and the scope, so one shared
  per-file resolver answers for both surfaces — the dialect globals (test/it/check/
  beforeEach/afterEach/property) and the method-level bindings (TestCall higher-order
  steps, closure skips, `pest()->beforeEach` hooks). `UsesResolver` reads the file's
  `uses(...)` statically (token scan, PHP's own name-resolution rules: backslash,
  alias map, namespace prefix; both `uses(` and `\uses(` spellings — the tokenizer
  names them apart, found live), first class argument wins per the PestScopes rule,
  default PestTestCase. Existence checks are real: the analyzed project's autoloader
  is loaded in the PHPStan process.
- **FINDING — magic members resolve to errors, not to __get/__call return types**:
  `expect($user)->name` and `check(...)->toBe(3)` were *ERROR*-typed despite both
  magics being declared `: self`. The fix is the classic pair of class-reflection
  extensions: on Expectation and TestCall, any member exists and continues the chain
  in kind (`MagicChainProperty`/`MagicChainMethod`, all-@api surface — the first
  draft used `new SpecifiedTypes()`-style non-promise API and this repo's own gate
  rejected it). Honest cost, stated: a typo'd matcher is a runtime failure, not an
  analysis find — as it is for every Pest suite in existence.
- **Dynamic $this state = universal object crate**: the runtime already says
  `#[AllowDynamicProperties]` on PestTestCase; `universalObjectCratesClasses` is the
  same statement to the analyzer. A typed uses() class is the alternative the
  analyzer sees through fully — the HigherOrderCase fixture now declares its state,
  which is also the documented guidance.
- **Callback surfaces typed for real**: Expectation's each/when/unless/scoped carry
  `Closure(self): mixed` parameter docs (closure params infer from context),
  extend() carries `@param-closure-this self` — and dropping the now-provably-
  useless `(bool)` casts fell out of the gate. The self-hosted suite caught the one
  wrong guess: sequence() hands the raw key, only the item is wrapped — the runner
  failed the fixture the analyzer had accepted, both nets working.
- **Dogfood**: excludePaths shrank five entries to one — only
  PestDialectConfig.pest.php stays out, because it exercises the two documented
  gaps on purpose: directory-scoped `uses()->in()` resolution (the resolver only
  reads file-local uses) and dataset row closures inside array literals (beyond any
  parameter-level extension point). Verified in the installed shape too: a pest
  file in a path-repo project analyses clean at level max through the one
  `includes:` line. 405 suite tests, 17 fixtures conform.

---

### D-051: Property typing — Gen generics, and forAll's signature recovered from the call site

PHPStan extension slice 3, paying the bill Gen::map()'s docblock explicitly deferred
("expressing 'takes what this Gen produces' needs generics — a job for the planned
PHPStan plugin, not a lie in a docblock").

- **`Gen<T>` natively**: `@template-covariant T` and full annotations across the
  factory and combinator surface — `int(): Gen<int>`, `elementOf(list<TValue>):
  Gen<TValue>`, `oneOf(Gen<TValue>...)` (the union falls out of template
  inference), `listOf(Gen<TElement>): Gen<list<TElement>>`, `map(Closure(T): TOut):
  Gen<TOut>`, `suchThat(Closure(T): bool): Gen<T>`. No extension code — this is
  plain PHPDoc, and the measured proof is the probe: a value drawn through
  `int()->map(fn => (string) $n)->suchThat(...)` types as `decimal-int-string`.
- **The one thing generics cannot say**: `Property::forAll(Gen<int>, Gen<string>)
  ->check(fn)` — variadic generics don't exist, so the closure's per-position
  parameter types can't ride a declared signature. `PropertyClosureTypeExtension`
  (MethodParameterClosureTypeExtension) recovers them from the call site: walk the
  fluent chain back through cases()/seed() to the forAll() call, read each
  generator argument's T, synthesize the ClosureType. A Property that traveled
  through a variable is left untyped, never guessed. Property/forAll themselves
  stay honestly `Gen<mixed>` — heterogeneous by nature.
- **Limit, measured not assumed**: the extension point feeds *inference* —
  undeclared closure parameters get the real types — but not acceptance checking;
  a wrongly *declared* parameter keeps its declaration silently. Stated in the
  class doc.
- **Pinned black-box with exact types**: the D-049 fixture now carries `dumpType`
  probes, and the one-run expectation is byte-exact — the sentinel error plus
  `Dumped type: int` and `Dumped type: list<string>` recovered through `cases()`
  and `listOf()`. 405 suite tests, 17 fixtures conform.

---

### D-052: `crucible lint-inline` — doctests analyzed through shadow files

PHPStan extension slice 4, the last of the four planned — `@crucible` expressions were
the one dialect surface no analyzer ever saw (they live in docblocks and compile at
discovery). The D-035 deferral closes with the design it named: shadow extraction.

- **`DoctestShadow`** (pure, unit-tested): a source file's doctests re-homed into one
  analyzable PHP file — the same namespace, so name resolution matches what the
  engine compiles; each expression an arrow-function element of a returned array
  (used values — nothing for a dead-code rule to dislike); a line table mapping every
  shadow line to its origin docblock line. The extraction grammar is InlineBuilder's
  regex, applied per docblock line via token scan — what runs is exactly what gets
  analyzed (and writing the tests re-confirmed a grammar edge: a pure one-liner
  `/** @crucible ... */` is not a doctest; the tag wants a starred line).
- **The command owns the invocation** (fourth bare-word command): shadows written
  under `{cacheDir}/lint-inline/` (flattened paths, stale ones cleared — the
  coverage.tmp pattern), a generated neon including the extension at level max, one
  child `phpstan analyse --error-format=json`, and every reported path:line
  translated back to the origin before printing. Exit 1 on findings; PHPStan absent
  names the `composer require` (the D-041 way-in rule); walking the exact same file
  set as the inline dialect (`TestDiscoverer::inlineFiles`, published for the
  purpose).
- **FINDING — scan the origins, don't trust autoload**: doctests reference their own
  file's symbols, and in a project whose classes aren't autoloader-covered the
  shadows analyzed to "unknown class". The generated neon lists the configured
  source directories/files as `scanDirectories`/`scanFiles` — symbols resolve
  wherever the code lives, no autoloader assumptions.
- Verified live both ways: Crucible's own two doctests analyse clean, and a fixture
  project's deliberately wrong doctest (`twice("nope")` against `int`) reports as
  exactly one finding at its origin line, exit 1 — fixed, clean, exit 0. 409 suite
  tests, 17 fixtures conform. The four-slice PHPStan extension plan (D-049..052) is
  complete; the leftovers (scoped uses()->in(), dataset-row closures, declared-
  parameter acceptance checking) live in ROADMAP.

---

## Browser tier slice B2

### D-053: The Playwright driver client — browser tests without a Node-side client

`src/Browser/`. First slice of the browser tier (the largest gap in the
pest+mockery-replacement goal; surface pinned in `spec/pest-api.md` §6, B1 oracle).
Backend decision recorded from current evidence: **Playwright over WebDriver
classic/BiDi/Cypress** — the pinned Pest surface (device presets, geolocation,
timezone/locale/dark-mode emulation, console events, `--browser safari` = WebKit)
maps 1:1 onto Playwright context options and simply doesn't exist cross-browser in
classic WebDriver; BiDi (~70% CDP surface, Safari absent in 2026) is the recorded
re-open trigger, held open by the transport seam. Cypress/Vitest-browser are
JS-authored-test architectures — wrong shape for PHP-driven tests entirely.

- **The wire contract, pinned black-box** (probe batteries, 2026-07-16): spawn
  `node_modules/.bin/playwright run-driver`, speak 4-byte little-endian
  length-prefixed JSON over stdio — the same channel every non-JS Playwright port
  uses. Requests `{id, guid, method, params, metadata}` — `metadata` is mandatory
  (the driver hard-crashes without it) and `goto` requires an explicit `timeout`;
  replies `{id, result|error}`; the remote object tree arrives as `__create__`
  events rooted at guid `""`, the `Playwright` root's initializer carries the
  `chromium/firefox/webkit` BrowserType guids (no ordering assumptions), a page's
  initializer names its main frame, and `LocalUtils` publishes the full device
  registry — B5's data arrives free on connect. PHP drove launch → context → page
  → navigate → title → close with zero Node-side client code.
- **Layering**: `FrameStream` (pure framing, stream-in/stream-out, testable on
  php://temp) → `Transport` interface (the BiDi/websocket seam) → `DriverTransport`
  (proc_open + stderr tail on failure — "selector wrong" must be tellable from
  "driver crashed") → `Connection` (ids, guid registry via `RemoteObject`,
  lifecycle-event absorption; all other events deliberately dropped until the
  assertions that need them land) → `Session`/`Browser`/`BrowserContext`/`Page`
  (typed facades; B2 keeps Page to the acceptance path).
- **Default OFF is architecture, not a check** (user decision): the three-state
  `BrowserConfiguration` (`enabled: null|true|false`) gates `Session::start` — the
  single point where the tier may touch Node. Default `null` → named error citing
  the enabling config line (never a silent skip); `true` without an install →
  named error carrying the exact npm commands, nothing ever auto-installed
  (~150MB of browsers is the user's explicit decision); `false` → the
  deterministic-skip state the test integration maps when `visit()` lands (B3).
  `Configuration`/`Builder` gain the `browser()` block — Crucible-native, no
  phpunit.xml counterpart.
- **Version discipline deferred, recorded**: the driver protocol is internal and
  versioned upstream; the client is deliberately minimal (only what the pinned
  surface needs) and developed against playwright 1.61.x. An explicit
  version-mismatch error (the incumbent's `PlaywrightOutdatedException` shape)
  joins when the npm-side contract is written down in B7.
- Tests: framing round-trips + three named short-read/bad-JSON errors without any
  process; the config gate's three states; end-to-end Chromium
  launch→navigate→title against the gitignored browser-oracle install
  (`CRUCIBLE_PLAYWRIGHT_ROOT` overrides), skipping deterministically where absent —
  the conformance suite's phpunit-main policy. 421 suite tests, 17 fixtures
  conform.

---

## Browser tier slice B3

### D-054: The page surface — Pest's selector grammar and interaction vocabulary

`src/Browser/Selector.php`, `Playwright/JsValue.php`, `Playwright/Page.php` grown.
Second browser slice: the D-053 client becomes something a test can drive. Two more
probe batteries pinned the interaction wire shapes before any code: every element
verb is a main-frame method taking `{selector, timeout}` (uniform — click/fill/
press/check/uncheck/selectOption/hover/innerText/getAttribute/inputValue/
waitForSelector), `type` adds `{text, delay}`, `selectOption` wraps options as
`[{valueOrLabel}]` (the JS client's own spelling for "value or label"),
`setViewportSize` addresses the **page** guid, `content`/`title` take no params, and
`evaluateExpression` speaks a value envelope — `{s} {n} {b} {v:"undefined"|"null"}
{a:[...]} {o:[{k,v}]}` — decoded by `JsValue` (unknown envelope = named protocol
error; handles are not this tier).

- **The selector grammar is three rules** (spec §6): `@x` → `[data-test="x"]`,
  CSS-looking strings pass through, anything else means visible text
  (Playwright's `text=` engine). Form fields get the friendlier resolution the
  incumbent's examples rely on — a bare word tries `[name=]`, `#id`,
  `[data-test=]` as one CSS list, first match wins. Pure functions, unit-tested;
  fine-grained parity (e.g. the incumbent's element-role preferences on text
  matches) is a conformance question for when the dialect surface lands.
- **The Pest vocabulary maps thinly**: `press($button)` is click-by-text (Dusk
  heritage — pressing means buttons, `keys()` is the keyboard), `type`/`fill` both
  fill, `typeSlowly`/`append` ride the keystroke-level `type` method, `clear` is
  fill-empty, `radio` checks `[name=][value=]`, `url()` is
  `script('location.href')` (the JS client tracks url locally; one evaluate keeps
  the tier stateless), `wait()` sleeps locally — never a protocol call.
- Interaction tail deliberately not in this slice (each needs its own probe):
  `drag`, `attach` (file upload), `withinFrame`, `withKeyDown`,
  `pressAndWaitFor`, `waitForKey`, `submit`, `screenshot` (B7 shares it with
  `assertScreenshotMatches`).
- Tests: selector grammar + envelope decoding pure; one e2e exercising the whole
  surface (press-by-text mutates the title, fill/append/clear/value round-trips,
  check/uncheck observed through `script()`, select-by-label read back by value,
  text/attribute/null-attribute, resize observed via innerWidth, waitFor on
  `@data-test`, content/url) against real Chromium — 2.1s wall for the pair of
  driver tests. 430 suite tests, 17 fixtures conform.

---

## Browser tier slice B4

### D-055: The assertion surface — ~50 checks, one thin layer, fluent chains

`Playwright/PageAssertions.php` (trait on Page), `JsValue::argument()`, element-state
queries on Page. Third browser slice: the page can now *assert*. One more probe
battery pinned the state-query wire shapes first: `isChecked`/`isEnabled`/`isDisabled`
take `{selector, timeout}`, `isVisible` takes **no timeout** (instant truth, not a
wait), `evalOnSelectorAll` runs `fn(elements, arg)` with `isFunction: true` and zero
matches allowed, and `:has-text("...")` works inside CSS — the link assertions ride it.

- **Every check lands in the ordinary assertion machinery** (`Assert::*`): counts,
  failure formatting, fail-fast, and the expectation window all behave like any
  other Crucible assertion — proven by a test expecting `AssertionFailedError` with
  the browser-side message. No parallel verdict path.
- **Fluent chains are the spec's shape** (`click()->assertUrlIs()->fill()` in the
  Pest docs example), so interactions AND assertions now return `$this` — the B3
  API converted before anything shipped on top of it.
- Families: title, text (`assertSee/DontSee/SeeIn/…/assertCount`), script+source
  (`assertScript` with expected-value compare, `assertSourceHas/Missing`,
  `assertSeeLink/DontSeeLink`), form (`assertValue/Checked/Indeterminate/
  RadioSelected/Selected/…` — option selection via `evalOnSelectorAll` with the
  value as protocol argument), attributes (incl. `assertAriaAttribute`/
  `assertDataAttribute` as prefix sugar), presence/interactability
  (`assertVisible` = instant visibility, `assertPresent` = in-DOM via match count,
  `assertEnabled/ButtonEnabled` differ only in selector grammar), and the URL
  family (parse_url over the live `location.href`; `assertUrlIs` compares path
  for `/`-prefixed expectations, whole URL otherwise). Health checks stay B7 by
  design (console stream + screenshot pipeline).
- **Selector grammar refinement (found by the tests failing honestly)**: bare
  words that are HTML element names now select elements, not text — `assertSee`
  reads `body`, `assertCount('li', 3)` counts list items, while `click('Login')`
  still means text. Without it, `body` resolved to `text=body` and timed out.
- **URL assertions demanded a real origin** — `data:` URLs have no host/path
  shape and `history.pushState` cannot cross origins. The test boots PHP's
  built-in server on a free port with a one-line router fixture and drives the
  full URL family against it: the seed of B6's server story, already exercising
  browser→local-server round-trips.
- 5th rector-vs-code gotcha recorded: `NullToStrictStringFuncCallArgRector`
  cannot see through the trait-composed `urlPart(): string` and adds a cast
  PHPStan flags as useless — rule skipped for the one file, pattern documented.
- 433 suite tests (24 browser), 17 fixtures conform.

---

## Browser tier slice B5

### D-056: Environment simulation — devices, cities, dark mode as context truth

`src/Browser/Device.php`, `City.php`, `ContextOptions.php`; `Browser::newContext()`
grows options; `Connection::objectOfType()`. Fourth browser slice: every emulated
fact — device, geolocation, timezone, locale, color scheme — is a **context-creation
parameter on the wire** (probe-pinned: `viewport`/`screen`/`userAgent`/
`deviceScaleFactor`/`isMobile`/`hasTouch` spread from device descriptors, plus
`colorScheme`, `locale`, `timezoneId`, `geolocation{latitude,longitude}`,
`permissions:['geolocation']`), so the whole surface is one options VO handed to
`newContext` — no per-page mutation tier needed.

- **The device registry is the driver's, not Crucible's**: 207 descriptors arrive
  free in the LocalUtils initializer on connect (D-053 pin, now consumed). 11 of
  the 24 Pest presets map to registry names — real user agents included
  (`GalaxyS24Ultra` maps to the registry's 'Galaxy S24', the nearest honest
  entry). The 13 the registry lacks (MacBooks, Surfaces, Galaxy S23/S22/Note20/
  TabS8, OnePlus, Xiaomi, Huawei, generic Mobile) get **Crucible-defined descriptors
  carrying viewport truth only — no invented user-agent strings**; an honest
  viewport, not a fake identity.
- **City presets are one fact bundle**: 13 cities (B1 pin), each geolocation +
  timezone + locale together, `permissions: ['geolocation']` granted with it —
  the incumbent's `from()->losAngeles()` semantics.
- **Composition order is documented and tested**: device descriptor first, city
  preset over it, explicit fields (`darkMode`, `locale`, `timezone`, `userAgent`,
  `geolocation`) last — most specific wins (e2e: Paris preset with ja-JP/Tokyo
  overrides observes the overrides).
- Verified by what the page itself observes: `innerWidth` 393 for the registry
  iPhone (with `device-width` meta), 1512@2x for the Crucible-defined MacBook 14,
  `Intl.DateTimeFormat().resolvedOptions().timeZone` per city,
  `navigator.language`, `prefers-color-scheme`, custom UA round-trip — plus a
  sweep instantiating **all 24 devices and all 13 cities** against real Chromium
  (~3s). Geolocation reads need a secure origin (127.0.0.1 qualifies; `data:`
  does not) — noted for the B6 server tests. 435 suite tests, 17 fixtures
  conform.

---

## Browser tier slice B6

### D-057: The in-process server — one loop, one shared world

`src/Browser/Server/` (`HttpRequest`/`HttpResponse`/`RequestHandler`/
`InProcessServer`), `Browser/Pumpable.php`, `Playwright/FrameBuffer.php`;
`DriverTransport::receive()` rebuilt as a select loop; `Session::start()` accepts
the server. The hard slice: the spec's Laravel example composes `Event::fake()` +
`RefreshDatabase` around `visit()` — only possible when served requests execute
against the test process's state. Crucible's answer is architectural, not
framework-specific:

- **`Pumpable`** — work that must progress while the transport waits. The
  transport selects over the driver pipe AND every watched pump's streams; when
  a page load hits the server mid-`goto`, the request is served from inside the
  very wait for the navigation reply. One cooperative loop, no second process,
  no IPC — therefore one shared world.
- **`FrameBuffer`** — the incremental decoder the select loop needs (bytes in
  whatever chunks the pipe delivers, frames out whole); `FrameStream::read`'s
  blocking exactness stays for the write side and simple cases. Driver pipe goes
  non-blocking; EOF mid-wait still reports the driver's stderr.
- **`InProcessServer`** — dependency-free HTTP/1.1 on `127.0.0.1:0` (ephemeral):
  accept-all, per-connection buffers (Chromium's speculative preconnects that
  never send are held, closed connections reaped), headers + Content-Length
  bodies, one request per connection (`Connection: close` — correctness over
  keep-alive cleverness). `RequestHandler` is the framework seam: a Laravel
  bridge implements it with the kernel; tests implement it with a closure.
  Honest limit documented: a handler that blocks forever starves the loop —
  by design, since the handler IS in-process app code.
- **The contract is proven without any framework** (e2e): a handler object holds
  test-visible state; the page load is served during the goto wait; the test
  mutates `$handler->greeting` and the *next click's* response reflects it;
  request effects are visible back in the test; an in-page `fetch()` round-trips
  through the same loop (bonus pin: the driver awaits promises in
  `evaluateExpression`, so `script()` returns resolved values).
- 439 suite tests, 17 fixtures conform. B7 (CLI/screenshots/health checks) is
  the only browser slice left.

---

## Browser tier slice B7

### D-058: Health checks, screenshots, headed mode — the engine tier completes

`Connection` records console/pageError events; `Page` gains consoleLogs()/
javaScriptErrors()/screenshot()/screenshotTo(); `PageAssertions` gains the health
family; `BrowserConfiguration`/`Builder` gain `headed`. The semantics were pinned by
a **behavioral oracle probe** — pest-plugin-browser run black-box against crafted
pages — and two of them are genuinely surprising:

- **`assertNoConsoleLogs` fails only on `log`-type entries** — warn/info/debug and
  even `console.error` pass it (oracle-verified, matched exactly).
  **`assertNoJavaScriptErrors`** is pageError events only (`console.error` is not
  an error; an uncaught `throw` is). **`assertNoSmoke`** fails on JS errors and
  passes clean pages — implemented as the observed subset, documented as such.
- **Accessibility is axe-core** (the oracle's failures carry dequeuniversity axe
  rule links), and the incumbent reports **serious+critical only** (its failures
  never list the moderate landmark rules every minimal page trips — pinned when
  Crucible's own clean-page test failed on them). Crucible runs the same engine with
  the same filter but **bundles no third-party code**: axe.min.js loads from the
  user's own node_modules (`npm install axe-core` — the npm dependency already
  exists for Playwright), named install error when absent.
- **Wire pins**: console events flow only after `updateSubscription` on the page
  (sent at Page construction); pageError flows unconditionally; both arrive on
  the context guid; `screenshot` returns base64 binary; health reads do one cheap
  round-trip first so queued events drain through the select loop.
- **`assertScreenshotMatches` rides D-042 whole**: the PNG's content hash is the
  snapshot value — missing baseline fails and names `--update-snapshots`, update
  mode is the one way baselines change, per-test ambient context, zero new
  bookkeeping. Raw images via `screenshotTo()`; a visual diff view is a recorded
  future nicety.
- **`headed`** (config + builder): `launch {headless: false}` — the engine knob
  under the incumbent's `--debug` posture. The `--browser`/`--debug` CLI flags
  and auto-screenshot-on-failure belong to the dialect exposure (they need
  test-name context and the `visit()` frontend) — recorded there.
- 443 suite tests, 17 fixtures conform. **B1–B7 complete: the browser engine
  tier ships** — protocol client, page surface, ~55 assertions, environment
  simulation, the shared-state server, health checks — all default-off, all
  driven from pure PHP.

---

## Browser tier slice B8

### D-059: visit() — the dialect exposure

`src/Browser/Browsing.php`, `visit()` in the pest dialect's functions.php,
`Browsing::begin/end` in the runner attempt window, `--browser`/`--debug` CLI
flags, failure screenshots. The browser tier becomes user-facing, per the
recorded decision: the Pest-4 mapping is the only documented grammar.

- **`Browsing` is the Snapshots pattern applied to browsers** (D-042 precedent):
  one ambient context the runner opens and closes around every attempt, because
  a test body cannot be handed parameters. One driver session and one launched
  browser per process (launching is the expensive part); one fresh context per
  `visit()` (the isolation unit); every context closed when its test ends.
- **The three-state gate finishes its story at this tier**: default-off and
  missing-install stay the named errors from D-053 (`Session::start` is still
  the only Node touchpoint), and **explicitly-disabled now means SKIP** — a
  thrown `SkippedTestError`, so `->browser(enabled: false)` turns every
  visiting test into a deterministic skip, exactly as specified. Relative URLs
  are a named refusal until a framework bridge serves the project (the
  incumbent errors with a bare protocol message; Crucible's names the reason and
  the path forward).
- **Failure screenshots ride the attempt window**: captured before the test's
  contexts close, only for genuine failures/errors (skips and incompletes are
  not failures), saved under `tests/Browser/Screenshots/<test-slug>.png` (the
  incumbent's location, gitignored), the path appended to the failure message —
  "A screenshot of the page has been saved to [...]", the incumbent's exact
  courtesy. A dead page never masks the real failure.
- **CLI**: `--browser chrome|firefox|safari` overrides the configured engine
  (unknown values are a named error listing the choices), `--debug` flips the
  headed posture. Workers read the browser block from the configuration file —
  a manifest field joins if parallel browser runs demand CLI overrides there.
- Crucible's own crucible.php enables the tier pointed at the gitignored oracle;
  visiting tests skip themselves where it is absent (the phpunit-main policy).
  449 suite tests, 17 fixtures conform.

---

## Mockery slice M2

### D-060: The Mockery core grammar — one brain, second dispatch strategy

`src/Double/Mockery/` + `DispatchStrategy` + `Compat/MockeryCompatibility`. The
reuse map held: the Mockery verbs are spellings over the existing machinery, and
the deltas landed exactly where the map predicted.

- **The strategy switch, not a second engine** (D-017's invariant): `DoubleState`
  gains `DispatchStrategy::FirstDeclared` — declaration order wins, count
  exhaustion falls through to the next match, an exhausted expectation with no
  successor still handles the call, and `registerInvocation(failFast: false)`
  moves every count violation to close (oracle-pinned §3/§5, each opposite to the
  PHPUnit posture). `MethodConfigurator` gains `expectCount()` (fluent counts) and
  `exhausted()`; `InvocationCount` gains `between()` — the whole predicted delta.
- **The generated-class surface is per-grammar**: `DoubleSpecification` carries
  `mockerySurface` (part of the class identity), the Generator bakes
  shouldReceive/shouldNotReceive/allows/expects plus a catch-all `__call` instead
  of the PHPUnit pair, reserved-name collisions are named per surface (the
  incumbent FATALS on these), and the marker is `MockeryMock` — a sibling of
  `Mocked`, because the two grammars declare incompatible `expects()` signatures.
- **Mockery equality is an argument-wrapping concern, not a matcher engine**:
  loose scalars (`IsEqual`), identity objects (`IsIdentical`), Constraints pass
  through — `MethodConfigurator::appliesTo` untouched.
- **Settlement is the ambient-container pattern** (Snapshots/Browsing precedent):
  mocks register in `MockeryContainer`; `TestCase::verifyTestDoubles` settles it
  on the same paths as every other double (unmet counts =
  `InvalidCountException extends AssertionFailedError` = a FAILURE);
  `Mockery::close()` is the idempotent spelling; the runner resets the container
  per attempt so a failed test never leaks expectations into the next.
- **Aliases on the D-019 principle**: `Mockery`, `Mockery\Expectation`,
  `Mockery\MockInterface`/`LegacyMockInterface`, the exception taxonomy — auto
  when mockery/mockery is absent, never masking a real install; same loadability
  rule in the PHPStan bootstrap for analysis-time. The ditched tier
  (spy/namedMock/instanceMock/globalHelpers) and the M3 matcher factories answer
  with named errors citing the spec — never silent.
- **The analyzer learns the runtime contract** (D-050 pattern):
  `MockeryMockReflectionExtension` — any method on a `MockeryMock` is callable
  (mixed), the four verbs open typed expectation chains; the honest cost stated,
  same as every Mockery suite in existence.
- §15 toggles: `allowMockingNonExistentMethods` (named refusal in the oracle's
  words), quick-definitions at-least-once; unknown types declared like the
  oracle does (instanceof holds); constructor-argument lists run the original
  constructor. 22 semantics tests mirror the M1 probe battery one-for-one; a
  dedicated conformance fixture awaits the mockery-side oracle lane (recorded —
  the harness pins oracle at phpunit-main). 471 suite tests, 17 fixtures conform.

### D-061: Mockery M3 — the §4 matcher algebra as spellings over existing constraints

`src/Double/Mockery/` factories + one seam on `MethodConfigurator`. Probe battery 8
(2026-07-17, three rounds against 1.6.12) pinned the strictness edges the algebra
list left open, then the reuse map held again: most matchers are direct reuse, five
thin constraints are genuinely new, and the whole delta to the one state brain is a
single nullable field. **M2+M3 = the planned doubles scope complete** — the Pest
mocking chapter runs natively, `composer require mockery/mockery` unnecessary.

- **The strictness map is asymmetric matcher-by-matcher, and pinned verbatim**:
  `contains` loose / `hasValue` strict; `anyOf` strict / `notAnyOf` LOOSE (probed
  both directions — the negation does not compose from the positive); `not` strict
  (object identity falls out of `!==`); `withSomeOfArgs` strict; `subset` recursive
  and strict throughout with the documented loose flag flipping every depth;
  `type()` follows the `is_{$t}()` function-existence rule case-insensitively, any
  other name an `instanceof` at match time (`'boolean'` never matches — parity);
  `pattern` casts scalars and Stringables; predicates (`on`, `withArgs`) must
  return `=== true`, truthy refused; `capture` assigns by reference during the
  match attempt — even when a later matcher refuses the call.
- **Direct reuse, zero new mechanism**: `any`/`on`/`withArgs`→`Callback`,
  `type`→`IsType`/`ValueType` + `IsInstanceOf`, `hasKey`→`ArrayHasKey`,
  `hasValue`→`TraversableContains` as-is, `not`→`LogicalNot`+`IsIdentical`,
  `notAnyOf`→`LogicalNot` over the loose membership. Thin new constraints only
  where none existed: `MatchesArraySubset` (recursive, flagged), `ContainsValues`
  (shared by `contains` loose and `withSomeOfArgs` strict), `IsAnyOf` (membership
  IS `TraversableContains` against the set), `HasDuckType`, `CapturesArgument`
  (the reference lives inside an assigning closure — the matcher stays a value
  object), `MatchesPattern` (the cast wrapper over `MatchesRegularExpression`).
- **One brain delta**: `MethodConfigurator` gains `withArgumentList(Constraint)` —
  one constraint over the whole argument list (no per-index arity), latest
  declaration wins like every `with()` respelling. `appliesTo` consults it first;
  both dispatch strategies get it for free.
- **§12 posture on the two upstream crashes**: `contains` on a non-array
  (`TypeError`) and `pattern` on a non-stringable object (`Error`) are clean
  no-matches in Crucible, recorded in the spec.
- `mustBe()` is a real named-error method (§14 — deprecated upstream); the
  remaining long tail stays behind `__callStatic`'s named error. The factories are
  ordinary static methods on the aliased class, so the PHPStan story needs nothing
  new — the extension already types the verb chains as `MockeryExpectation`.
- 21 semantics tests mirror battery 8 one-for-one, asymmetries included. 492 suite
  tests, 17 fixtures conform.

### D-062: Coverage slices 4–5 — branch analysis and the report tail

`src/Coverage/` + one manifest bool. The D-041 posture holds: strictly opt-in,
zero overhead without a flag, drivers detected at runtime.

- **The window is the honest shape now**: `CoverageDriver::stop()` returns a
  `CoverageWindow` (lines + branches) instead of the bare line map — pcov windows
  carry an empty branch map, xdebug fills it only under `--coverage-branch`.
  A branch is one xdebug basic block (`Branches::normalize` over the
  `functions.*.branches` payload, id = `function@opIndex`), covered when entered —
  the ecosystem's branch metric. Path analysis stays uncollected: nothing consumes
  it and it multiplies the payload (recorded — re-opens on a consumer).
- **`--coverage-branch` is xdebug territory by fact, not policy**: pcov cannot
  collect branches, so `DriverFactory::detect(branchCoverage: true)` skips the
  pcov preference entirely and the no-driver message names the exact xdebug
  invocation. The supervisor's worker command-line driver recreation makes the
  same choice, and the manifest carries one new `coverageBranch` bool — verified
  live under `--parallel 2` (worker branches merge through the artifact files;
  `CoverageData` branch entries merge like line values: any hit wins).
- **`--coverage-cobertura <file>`** — the schema GitLab/Jenkins/Azure visualizers
  consume; output-only like Clover/JUnit (the no-XML rule bans inputs, not opt-in
  emitters). Classes packaged by directory, paths relative to the root declared in
  `<sources>`, branch data as line-level `condition-coverage`. Clover's
  `conditionals` metrics fill from the same data (honestly zero without it).
- **`--coverage-html <dir>`** — Crucible-native presentation, no parity constraint:
  a self-contained static directory (inline CSS, zero scripts, zero requests) —
  index with per-file bars, one annotated source page per file mirrored under
  `files/` so relative links survive nesting; line classes follow the driver value
  convention, branch badges (full/partial/none) on branching lines. A source file
  that moved since collection gets an index entry without a page, never a fatal.
- Console: `--coverage-branch` adds per-file `branches x% (n/m)` columns and the
  `Branches:` total to the text report. 498 suite tests, 17 fixtures conform.

### D-063: Covers-metadata enforcement — the coverage subsystem's parity tail

`src/Coverage/CoversTargets.php` + a runner settlement seam. Probed black-box
(2026-07-17, phpunit-main 13.3-dev + xdebug, six probes recorded here as the spec):

- **The probe pins**: `requireCoverageMetadata` reclassifies a passing test without
  any Covers/Uses metadata as RISKY ("This test does not define a code coverage
  target but is expected to do so") — **with or without a coverage run**;
  `beStrictAboutCoverageMetadata` reclassifies a passing test that executed source
  code outside its covered/used targets ("This test executed code that is not
  listed as code to be covered or used:" + the stray **class names**, not files) —
  needs collected coverage. `CoversClass` filters the test's aggregate
  CONTRIBUTION to its targets; `UsesClass` extends only what it may execute, never
  what it contributes; `CoversNothing` satisfies the requirement, contributes
  nothing, and is EXEMPT from the strict check. A risky test's coverage is
  discarded; a failing test's is kept.
- **Demotion, not deletion**: the oracle's discarded/filtered coverage keeps its
  denominators (probe: two risky tests → "0.00% (0/3)", not 0/0). So the whole
  mechanism is one primitive — out-of-claim hits demote to executable-missed
  (`CoversTargets::contribution`), a risky window demotes everything
  (`CoverageWindow::withoutHits`). Live parity verified byte-for-byte on the probe
  fixture: same risky messages, `0 of 3` under the strict knobs, `66.67% (2 of 3)`
  under plain filtering.
- **Two truths, on purpose**: the aggregate follows the covers claim (parity);
  the per-test map keeps OBSERVED execution — the impact graph and the mutation
  query must not inherit false negatives from coverage discipline
  (`CoverageCollector::end` now returns the scoped window, recording waits for the
  outcome; `record(observed, aggregate)` takes both).
- **Targets resolve by reflection at settlement** (class/trait/method/function →
  file + line range); an undeclared symbol resolves to no range — never an engine
  error, strict runs surface the resulting strays. Stray naming walks a per-file
  index of declared classes/traits/user functions, built only when a stray exists.
- Config: `->requireCoverageMetadata()` / `->beStrictAboutCoverageMetadata()`
  (Builder + migrate-config mapping — `requireCoverageMetadata` left the
  not-migrated notes), CLI `--strict-coverage` (the oracle's own flag), both
  riding the worker manifest so parallel outcomes match sequential ones.
- No conformance fixture: the harness passes identical CLI args to both binaries
  and the oracle exposes no CLI spelling for `requireCoverageMetadata` while
  `--strict-coverage` needs a driver the harness does not load — the probe-mirroring
  unit tests pin the semantics instead (recorded). 507 suite tests, 17 conform.

### D-064: The browser tail — interaction probes, fan-out visit, visual diffs, parallel overrides

Probed 2026-07-17 against the incumbent (pest-plugin-browser 4.3 in the gitignored
`browser-oracle/`), signatures by reflection (the public API contract), semantics
by running its tests with a node shim teeing the driver channel/CDP debug.

- **The seven tail interactions**, each pinned then landed as the usual one-call
  Page methods: `drag(from, to)` → one `dragAndDrop` frame call (CDP confirms real
  HTML5 drag events); `withKeyDown(key, cb)` → `keyboardDown`/`keyboardUp` page
  calls around the callback (inner keys carry modifiers=8, probed);
  `pressAndWaitFor(button, seconds = 1)` = press + wait, plain composition;
  `withinFrame(selector, cb)` → querySelector → `contentFrame` → the SAME Page
  surface scoped to the child frame guid (one nullable ctor param — assertions,
  reads, and interactions all resolve inside the frame); `waitForKey()` = the
  terminal pause, TTY-gated (never blocks a headless pipeline — the incumbent's is
  a headed-debug helper).
- **Two better-than-incumbent pins, recorded**: `attach(field, path)` — the
  incumbent sends `localPaths` and dies on a stdio driver ("localPaths are not
  allowed when the client is not local", probed); Crucible reads the file PHP-side
  and ships a `setInputFiles` payload, which works everywhere. `submit()` — the
  incumbent navigates to the form's bare action URL, DROPPING the form data
  (pinned three ways: a real click and `form.submit()` both carry `?q=…`, only
  `location = action` gives the incumbent's URL); Crucible performs a real
  `requestSubmit()` (validation, submit event, serialized fields).
- **`visit([...])`** (probed: `ArrayablePendingAwaitablePage`, fan-out, per-page
  failure attribution, not iterable): `PageCollection` — every call fans out to
  every page, the chain continues on the collection, conditional return types keep
  `visit()` fully typed in both arities.
- **Screenshot visual diff**: `Snapshots::matchScreenshot()` — the `.snap` entry
  stays the content hash (text-diffable, collision-proof), the `--update-snapshots`
  run also stores the reference PNG beside the snapshot (recording stays explicit,
  D-042 principle), and a mismatch writes `<key>.actual.png` plus a dependency-free
  `<key>.diff.html` (side-by-side + a mix-blend-mode difference overlay: black
  where the renderings agree) named in the failure message.
- **`--browser`/`--debug` cross the worker boundary**: two manifest fields, the
  worker applies the same engine/headed override the supervisor does — a parallel
  browser run now behaves like its sequential twin (closes the D-059 note).
- e2e against real Chromium: drag/attach/withKeyDown/withinFrame/submit/fan-out in
  the standard suite (skip-deterministic without a Playwright install). 510 suite
  tests, 17 fixtures conform.

### D-065: Relative visits served in-process — the framework bridge lands

`->browser(requestHandler: X::class)` + `src/Bridge/Laravel/BrowserKernelHandler`.
The G1 territory the browser tier deferred: relative `visit()` URLs, served by the
SAME process that runs the test.

- **Explicit, typed, framework-agnostic**: the config names a class implementing
  `Browser\Server\RequestHandler` (D-057's seam) — no framework detection magic,
  in keeping with the tier's default-off architecture. Misconfigurations are named
  errors at first use (unknown class, wrong interface, relative visit with no
  handler configured — the old error now names the knob). One `InProcessServer`
  per process, built before the session when possible so the driver's select loop
  watches it from the first frame; when a session already runs (an earlier
  absolute visit), the transport **late-watches** it (`Session::watch` →
  `Connection::watch` — the one new seam). Relative URLs resolve against the
  server's origin.
- **The Laravel bridge is ~90 lines because D-057 did the work**: per request the
  handler asks the booted container (the test's own `TestCase` bootstrapped it)
  for the HTTP kernel, converts the raw request, handles, terminates. The spec's
  shared-state contract — `config()` mutations, `RefreshDatabase` factories —
  demonstrably reaches the served pages: validated in a REAL Laravel 13 app
  (recreated D-027 bed), sequential and `--parallel 2`, real Chromium; the
  engine-side contract pinned framework-free in the suite (mutate → visit →
  mutate → visit sees both states).
- **The validation bed caught a real coexistence bug**: with Crucible installed as a
  symlinked path dependency, the binary's `__DIR__` resolves the symlink and loads
  the REPO's own dev vendor — whose `InstalledVersions` answered D-019/D-060
  coexistence questions for the wrong project, so the Mockery aliases armed while
  the app's real `mockery/mockery` loaded later: fatal. The Composer bin proxy's
  `$_composer_autoload_path` (a global, Composer ≥ 2.2) now wins the binary's
  autoloader candidates — the consuming project's world answers, the aliases stand
  down, real Mockery coexists. 513 suite tests, 17 fixtures conform.

### D-066: Bush-clearing — snapshot counts, the G2 extended tier, the mockery oracle lane

Three small rows closed in one pragmatic pass; the third immediately paid for
itself twice.

- **Snapshot counts on the console** (the D-042 leftover): `Snapshots::recorded()`
  — created/updated tallies per attempt, stashed at `end()` by the ENDING ambient
  context so a nested harness run (the self-hosted suite's own pattern) cannot
  leak its counts onto the outer attempt (caught live on the first full-suite
  run). The counts ride `test:finish` as a default-omitted `snapshots` payload
  (golden schema untouched, EventParser round-trips), a `SnapshotLog` listener
  sums worker-transparently, and the CLI prints `Snapshots: N created, M updated.`
  after `-u` runs.
- **`pest()->printer()->compact()`** (spec §5): the grammar registers a suite
  preference in `PestScopes`; the console reporter choice moved AFTER discovery
  (nothing emits in between) so Pest.php declarations are known in time. Crucible's
  default console IS the compact dot printer, so the observable effect is
  overriding a configured `->testdox()`; the CLI flag still wins (D-023
  precedence). Dogfooded in the suite's own Pest.php.
- **Arch presets** (the D-033/D-034 growth decision, decided): ten class-shape
  matchers on the existing expect() surface — `toBeFinal/Abstract/Readonly/
  Interface/Enum/Trait`, `toExtend`, `toImplement`, `toUseTrait`, `toHaveMethod`
  — reflection at match time, `->not` negation free through the one assert
  funnel. The full `arch()` namespace-sweeping subsystem (directory scanning,
  dependency rules, `toUse` source analysis) stays DECLINED: a static-analysis
  engine, not a test-runner concern.
- **The mockery conformance oracle lane**: `conformance/mockery-oracle.php` pins
  the reference install's own autoloader (real mockery 1.6.12 + its PHPUnit
  13.2.4); a per-fixture `options.json` `oracle: mockery` switch picks it; fixture
  18-mockery runs the M1–M3 pins through BOTH stacks. First run, two catches:
  1. an unmet expectation settling via a bare tearDown `Mockery::close()` is a
     raw ERROR on the oracle — the documented posture is the
     `MockeryPHPUnitIntegration` trait, whose NAME Crucible now aliases onto a no-op
     trait (settlement is native, D-060; existing under the name is its whole job);
  2. even WITH the trait, PHPUnit 13 classifies the unmet expectation as an
     ERRORED test — **D-060 guessed failure and the oracle overruled it**:
     `InvalidCountException` now extends the Mockery exception taxonomy instead
     of `AssertionFailedError`, and both stacks settle identically.
  516 suite tests, 18 fixtures conform.

### D-067: PHPStan extension leftovers — the D-050/D-051 gaps settled

Four leftovers; three closed with code, one closed as an upstream limit with the
evidence line-anchored.

- **Directory-scoped `uses()->in()` resolves** (the D-050 gap): `UsesResolver`
  gains `scopedRegistrations()` — a token scan of a Pest.php's `pest()/uses()`
  chains collecting extend/use/assign class refs and `in()` glob strings — and
  `DialectThisResolver` walks the analyzed file's ancestors (capped, stopping at
  the first crucible.php/composer.json), applying registrations outer-first with the
  runtime's exact rules: file-local `uses()` wins, last matching scoped class
  wins, plain globs are subtree prefixes, wildcards go through
  `fnmatch(FNM_PATHNAME)` relative to the declaring directory. Pinned black-box:
  a fixture tree whose test file declares nothing dumps `$this` as the scoped
  class and calls its method clean.
- **`property()` sugar typed** (the D-051 gap): `PropertyGenerators` is now the
  one place that recovers per-position value types from a call site — the
  `check()` chain walk and the sugar's same-call generator arguments — feeding
  both closure-type extensions (`PropertyFunctionClosureTypeExtension` is the
  function-level twin).
- **Declared-parameter acceptance** (D-051's measured limit): the extension point
  feeds inference only, so `PropertyParameterRule` closes the other half — a
  check/property closure whose DECLARED parameter cannot accept its generator's
  production is reported (`crucible.propertyParameter`), extra parameters beyond the
  generators too (`crucible.propertyArity`). Wrong at the keyboard, not at the first
  drawn case. Both pinned byte-exact in the black-box run.
- **Dataset-row closures: closed as blocked upstream.** Measured again with the
  scoped resolution live: a closure inside a `with([...])` array literal reaches
  no closure-this extension point — PHPStan's hooks are parameter-level. The
  whole-file excludePath is GONE regardless: the exerciser now analyses with
  three line-anchored, explained ignores (the trait-member limit and the dataset
  closure — both verified by execution), and `HigherOrderCase` declares its hook
  state per the D-050 guidance. Re-opens if upstream grows a nested-closure
  extension point. 517 suite tests, 18 fixtures conform.

### D-068: The PDF report — the D-025 postponement lands

`src/Reporting/PdfWriter.php`, `--log-pdf <file>`. The fifth view of the one
stream, and D-009's dividend paid again: zero lines of execution code changed.
The listener is the MarkdownWriter's shape verbatim — the same buffered
sections/problems state, the same render at run:finish (so `--parallel`
interleaving never splits a section), the same report outline: summary table,
OK/Not OK banner, problems with their failure text, per-file result tables.

The file format is written by hand against the PDF 1.4 specification, staying
inside the "deliberately simple" scope D-025 recorded:

- **Zero dependencies, including extensions**: no ext-zlib (content streams are
  uncompressed), no ext-iconv/mbstring (UTF-8 is decoded by hand). One class,
  one file — objects, cross-reference table, and trailer are plain string
  building, the JUnit writer's posture applied to a binary format.
- **Base-14 fonts only, nothing embedded**: Helvetica/Helvetica-Bold for
  structure, Courier for failure text — fixed pitch keeps diffs aligned, and it
  is the one deviation from D-025's "one font" note, taken for that reason.
  Metrics are the published AFM widths as constants, so column truncation
  (WinAnsi ellipsis) and right-alignment measure exactly.
- **WinAnsiEncoding, degraded honestly**: Latin-1 passes through, the common
  typographic points (dashes, curly quotes, ellipsis) map to their
  Windows-1252 slots, everything else becomes `?`. Caught on the first real
  render: encoding is applied exactly once per string — a second pass misreads
  WinAnsi bytes as UTF-8 lead bytes and eats characters; the reporters test
  now pins an em dash through both text paths.
- **Deterministic bytes**: no CreationDate, no file identifier — the same
  events produce the identical document, asserted byte-for-byte. The test also
  walks the xref table and checks every announced offset lands on its object.
- Pagination breaks against a footer reserve, per-file tables repeat their
  header row after a break, outcomes carry the console's color semantics.
  Verified end-to-end on the self-hosted suite: a 15-page document, then
  eyeballed rendered.
- **Polish pass (user feedback, same day)**: table headers are inverted —
  dark band, white text — and the document carries its archive identity:
  `->reportTitle('…')` in crucible.php titles the report (project name, release
  tag; default "Test report"), a meta line and the footer carry `Crucible
  <version>`, and the run date appears in both the meta line and the Info
  dictionary's `/CreationDate`. The date is the FIRST ENVELOPE'S timestamp,
  never the wall clock — determinism holds: same stream, same bytes, and the
  frozen-clock test still asserts the document byte-for-byte. Author credits
  ride both surfaces too: the meta line reads "Crucible <version> by
  <Version::AUTHOR>" and the Info dictionary carries `/Author` — the CLI
  banner's credit, now on the archival document.
- **Row redesign (user feedback, research-grounded — RESEARCH.md §7)**:
  result rows read # / Outcome / Test / Time — status scans FIRST (the
  Few/Allure reading order, pinned by a test), rows are numbered per file,
  zebra-striped (Tufte-light), and each file heading carries its own tally
  ("11 tests, 0.001s"). Problems are numbered like the console's failure
  list. The booktabs canon (no interior vertical rules, hierarchy from
  bands and spacing) replaced the earlier grid instinct.
- **Exception reporting over a complete record (user feedback, two
  rounds)**: the dark-cockpit / clinical-lab convention, settled as — every
  test name lists (the what-was-tested evidence a compliance reader needs),
  but a PASSING row shows no status at all; only flagged rows light up,
  with a Bézier-drawn disc (the font's bullet glyph was too small) plus the
  colored outcome word. File tallies flag exceptions ("2 flagged · 13
  tests"), colored by the worst outcome. Directories print once as gray
  subheadings and file headings drop the repeated path prefix — the global
  common-prefix idea died on first contact with the real suite (inline
  tests live under src/, so the common prefix is empty); grouping is
  per-directory instead.
- **Round nine — directory metadata inlines**: the right-aligned time
  column is gone; every directory line reads "Name · N tests · time" with
  the metadata in gray right after the bold name — the count covers the
  directory's OWN files only (children carry their own, so counts across
  the tree still sum to the Passed heading; a file-less wrapper shows
  just its time). Inlined single-file lines flow the same way ("Metadata
  · MetadataParser · 5 tests · 121µs"), singular handled, and #757575 is
  the only gray in the document.
- **Round eight — the final polish**: tiles are name + PASSED COUNT only
  (two tab stops per column; a wide tile spans columns and the next
  fitting tile backfills the freed column — no forced row breaks, no
  empty columns), with one exception: a file over 2% of the runtime whose
  tests never reached the slowest list appends its time, or that cost
  would be invisible — and the exception proved itself on the first
  render (Browsing 454ms and ChangedFiles 371ms both exceed 2% while
  their slowest tests fell to the top-10 cap). Every directory line —
  heading or inlined — puts its time on ONE shared right edge; inlined
  lines put the count at the first tile column's edge. No trailing
  slashes anywhere (internal separators kept: "src/Test"); the
  bold-directory/regular-file contrast plus the interpunct is the only
  boundary marker. Leaders start a fixed 8pt after their name. Still 2
  pages.
- **Round seven — the density addendum**: single-file directories inline
  to one line ("Process/ · WorkerProtocol  7 · 371µs"; a file-less parent
  whose every child inlines renders nothing itself, so "src/Test/ ·
  TestId" carries the combined label); the sub-threshold top-level tail
  (< 1ms AND < 0.1% of runtime) folds into "+ N more directories · M
  tests · time" — directories holding flagged files are exempt (nothing
  referenced in Problems may disappear), and a fold of one is skipped
  (the line costs the same, observed live: src/ renders). Tile counts
  show PASSED tests only — the amber/red dot is what says "full story in
  Problems", and the folded line's counts keep the Passed total honest
  (invariant-pinned). Keep-together pagination: a block that fits a page
  never splits across one. Self-hosted report: 2 pages.
- **Round six — the FINAL layout spec, adopted**: four sections and
  nothing else — header (title + verdict badge + one-line strip + thin
  bar), Problems, Slowest, Passed. The verdict is now the ENGINE'S truth:
  FAILED on failures/errors, FLAKY when any test passed on retry (the
  D-038 field, so the word finally means what Crucible means by it), OK
  otherwise — skips and incompletes no longer touch it. No page
  cross-references anywhere (the two-pass layout died with them) and
  dotted leaders only in Slowest — both pinned as invariants. Problems
  entries: gray directory + black basename · test name, short reasons
  inline on the same line, long/multi-line ones wrapped beneath — the only
  multi-line text in the document. Slowest's attribution line covers
  directories to ≥80% of runtime. The results section became "Passed ·
  N tests · time": a pure timing tree — real nesting (Runner/ → Process/
  indented, single-child chains collapsed to "tests/unit/"), siblings by
  descending time, uniform file tiles (name, count · time) with an
  amber/red dot as the only severity mark — every flagged DETAIL lives in
  Problems alone. That made `->reportFullRoster()` meaningless (no
  per-test rows exist to expand), so the knob was removed the same day it
  shipped. No zebra anywhere. Self-hosted report: 3 pages.
- **Round five — the full layout spec, adopted**: strict severity-first
  page order (nothing green above anything red or amber): title + verdict
  badge (right-aligned, the page's most prominent element; the amber
  verdict reads FLAGGED, not the spec's FLAKY — that word already means
  pass-on-retry in Crucible, D-038), a one-line summary strip + thin
  proportion bar replacing the nine-column table, then Problems GROUPED BY
  SEVERITY (Errors → Failures → Risky → Incomplete → Skipped; empty groups
  absent, counts on the headings sum to flagged, entries numbered within
  the group, basename · test name with dotted leader to the page ref, the
  reason beneath in gray, a 2pt severity bar in the left margin, and no
  per-entry outcome word), then Slowest (1% floor, dotted leaders, the
  concentration line "Browser/ and PHPStan/ account for 9.58s of 10.2s
  (94%)"), then Results sorted by DESCENDING TIME (flagged content already
  led the report; Results answers where time goes — severity still orders
  files inside a directory, the user's standing rule). Palette narrowed to
  #2e7d32/#b26a00/#c62828/#757575 — skips are gray now, quiet rather than
  alarming. Nothing truncates with an ellipsis anywhere (names wrap; the
  invariant is pinned: the only 0x85 byte in a document is the deliberate
  elision line); small flag-only tables drop their header band and carry
  the Problems-style margin bars; grids got real gutters and column spans;
  the footer got its hairline rule.
- **Reading-first pass, round four (external review + user)**: directory
  grouping made EXPLICIT — discovery can interleave a subdirectory between
  a parent's files, and streaming on encounter re-emitted the parent's
  header (caught on the Runner tree; a regression test now pins interleaved
  order, each directory printing exactly once with a rollup covering only
  its own files, so directory totals sum to the run). Expansion policy:
  failures/errors expand their whole file (a failure wants context),
  flag-only files list just their flagged rows over a one-line elision
  ("… 35 passing tests · 406µs"), and fully passing files gather into a
  three-column grid — name, count · time, no repeated "pass": as with rows,
  quiet IS the pass mark. Severity orders each directory (fail/error →
  incomplete/risky → skip → the grid). Problems put the outcome BEFORE the
  path so truncation can never eat the label; the summary table gains a
  Flagged column with its definition as a footnote; the verdict is a badge;
  durations hold three significant figures; the slowest list gets the same
  page references as the problems. Self-hosted report: 3 pages.
- **Reading-first pass (external review, round three)**: detail level
  inverted — passing files collapse to their tally line by default and
  `->reportFullRoster()` opts into the complete listing; durations use
  adaptive units (µs/ms/s, "—" for no runtime) with a slowest-tests top-10
  on the summary page and per-directory duration rollups (the Browser
  tree's 8s surfaces instantly); the summary gains a proportional outcome
  bar and defines "flagged" so the results vocabulary reconciles; each
  problem cross-references its results page — the layout runs TWICE
  (deterministic, so pass one learns the page map, pass two writes the
  references onto existing lines without shifting pagination); table
  continuations name their file ("· Coverage (cont.)"). Self-hosted
  report: 5 pages, page one is the executive summary.

518 suite tests, 18 fixtures conform.

### D-069: The dyadic continuum proved too coarse — narrow float ranges refined

The G5 float row's condition ("if the dyadic continuum proves too coarse") was
evidence-tested per the D-044 method: probe first, decide on the numbers,
record either way. The probe (20k seeded draws per range, plus a
falsification probe against a failure region occupying the middle 60% of the
range) condemned the old encoding twice:

| range | distinct values | hits on the 60% region |
|---|---|---|
| [0, 1e-6] | **2** | **0.00%** |
| [1.0, 1.0001] | **4** | **0.00%** |
| [0, 1e-3] | 21 | 0.04% |
| [-1e-7, 1e-7] | 3 | 4.26% |
| [0, 0.5] | 7,681 | 26.2% |
| [0.001, 0.002] (even-spacing branch) | 15,366 | 52.2% |
| [0, 10], [0, 1000] controls | ~15.7k/17.5k | ~48–52% |

- **Diagnosis one (the probed defect)**: when the range contains an integer,
  the whole + dyadic-fraction branch ran, and its fraction quantum 1/65536 ≈
  1.5e-5 exceeds any narrower range — the generator collapsed to the
  endpoints and could NEVER falsify a property whose failure region occupies
  60% of the range. The asymmetry the probe exposed: [0, 1e-6] was broken
  while [0.001, 0.002] was healthy purely because 0 is an integer.
- **Diagnosis two (found by the same probe)**: the fraction only ADDS, and
  the whole part bottomed at ceil(min) — a range straddling zero with no
  whole number below its midpoint ([-0.5, 0.5], [-1e-7, 1e-7]) could not
  produce an interior negative at all.
- **The fix is the targeted one, not the recorded IEEE-754 plan**: the
  whole+fraction encoding now runs only when the range is wider than one
  unit (where its quantum resolves and whole-number landing pays for
  shrinking), with BOTH whole bounds floored so the sub-integer interior
  stays reachable; everything narrower uses the already-proven even-spacing
  branch — 65,536 evenly spaced points, resolution that scales with the
  range. That scaling is exactly why the full lexicographic IEEE-754
  encoding (reversed mantissa, re-biased exponent) stays unneeded: it buys
  bit-level granularity the 65k grid already exceeds per-range, at the price
  of reordering every existing shrink pin. Re-opens only if a property needs
  denser-than-65k resolution inside one range.
- **After (same probe)**: every range ≥ 15,366 distinct, 48–52% region hits,
  interior negatives reachable; the wide controls are byte-identical to
  before (their branch didn't change). The D-040 shrink pins stay green —
  specials-first still wins (f < 0.5 over [0, 10] shrinks to exactly 1.0),
  and narrow-range counterexamples shrink to just above the failure region's
  edge (pinned loosely: the 2.x band above 2e-7, not the step-budget-exact
  value).
- **The row's other half — failure-database pruning — stays deferred, reason
  re-verified**: pruning a fixed entry safely needs a per-key "replayed
  clean" signal riding test:finish across the worker boundary plus
  run-completeness knowledge; nothing in this slice adds either. It keeps
  its own ROADMAP row.

521 suite tests, 18 fixtures conform.

### D-070: The assertion long tail — audited, and nothing demands it

The D-012 row's condition ("only if a conformance scenario ever surfaces it")
was never probed; this entry retires the ambiguity with the audit the row
always implied. Method: reflect both public Assert surfaces — the oracle's
`PHPUnit\Framework\Assert` under its own pinned autoloader (the
`phpunit-oracle.php` technique) against Crucible's — diff, classify, then grep
the missing names across every real consumer available.

**The diff**: oracle 248 public statics, Crucible 137 public methods. Missing:
45 `assert*` methods in five families — the XML family (13, the known
ext-dom deferral), string/file `IgnoringWhitespace` variants (6), the
PHPUnit-13 `assertArrays*` additions (12, new spellings beside the
`assertEquals`/`assertEqualsCanonicalizing` forms Crucible covers),
`assertContainsNotOnly*` negations (14), `assertObjectNotEquals` — plus 71
non-assert statics, almost all standalone constraint factories (`equalTo`,
`isTrue`, `logicalAnd`, …) whose constraints Crucible builds internally.

**The demand test** (zero hits means zero occurrences of ANY missing name):

- A real production Laravel 13 + PHPUnit 13 application's test suite: 0
  hits (sanity check: `assertSame` hits 8 — the grep works).
- The entire `laravel/framework` 13 source, `Illuminate\Testing` included:
  0 hits. The framework's own `Assert::` calls total three distinct methods
  (`assertTrue`, `assertEmpty`, `assertStringContainsString`) — all covered.
- All 61 packages of that application's vendor tree (phpunit itself
  excluded): 0 hits.
- Crucible's conformance fixtures and the spec inventory: 0 hits.
- Constraint-factory names that did match (`->equalTo(`, `->isJson(`,
  `->lessThan(`…) were all Carbon/Request/Filesystem APIs on inspection —
  not one PHPUnit constraint usage.

**Decision**: the whole tail — XML family included — moves to the declined
table, D-044 style: a verified "nothing demands it", not a guess. Cheap as
the non-XML ones would be, implementing undemanded surface contradicts the
audit's own finding; the spec is observable behavior, and no observed suite
behaves through these names. Re-opens the moment a conformance scenario or
a real suite names a specific method — implement THAT method, not the tail.

Suite and fixtures unchanged (an audit, not a code change).

**Reversed, 2026-08-22 — the tail is built.** The audit's finding stands and
was never the whole question: "nothing observed demands it" answers whether
the gap is *urgent*, not whether a drop-in replacement should have it. A
migrating suite meets the surface, not the audit, and a method that is
absent fails differently from one that disagrees — it fails at parse time,
in someone else's codebase, with no diagnostic that names Crucible's
position. All 45 are implemented and, more to the point, *proved*: fixture
`19-assertion-tail` runs 98 cases through both engines and compares
outcomes, which is the same standard every other conformance claim meets.

Four families needed their semantics observed rather than assumed, and
three of the four held a surprise:

- `IgnoringWhitespace` **collapses** runs of whitespace and trims; it does
  not strip. `'a b'` and `'ab'` stay unequal, so the assertion still tells
  two words from one.
- `assertArraysAreEqual([1,2],[2,1])` **fails**. "Ignoring order" relaxes
  key *order*, never the pairing — only the `Have*Values` spellings drop
  keys, and only their `IgnoringOrder` forms are multisets (duplicate
  counts included).
- The XML family compares canonical form (C14N), where whitespace *between
  elements* is insignificant but whitespace *inside a text node* is not.
  Unparseable input **errors** rather than fails — the assertion could not
  ask its question, which is not the same as the answer being no.

`ext-dom` is now a declared requirement; it is what canonicalization needs,
and D-041's no-XML rule was always about configuration *input*, not about
assertions over a user's own data.

---

## Run completeness — the pruning gate

### D-071: `complete` on run:finish, and both prunings it unblocks

Two ROADMAP rows — property failure-database pruning (D-040/D-069) and
obsolete-snapshot pruning (D-042) — were blocked on the same missing
primitive: safe deletion needs proof, and nothing on the stream said "this
run visited everything" or "this stored entry was proven fixed." This entry
builds the shared primitive once and lands both prunings on it. Everything
rides existing machinery: default-omitted envelope fields (the D-040
pattern — golden schemas untouched, worker-transparent because the D-022
NDJSON IPC carries payloads whole), stash-at-end ambient contexts (the
`Snapshots::recorded()` rule), and supervisor-side listeners (the
ResultCacheWriter single-writer discipline).

- **The primitive**: `run:finish` gains a default-omitted `complete: true`,
  true only when BOTH halves hold. The CLI half — no option narrowed the
  plan below the configured suite (`--filter`, `--group`/`--exclude-group`,
  `--testsuite`, `--todos`/`--assignee`/`--issue`, `--changed`/`--related`,
  `--shard`, `--flakes`) — travels as `RunnerOptions::fullSuite` (a
  Supervisor constructor bool in parallel mode). The runner half — every
  planned test finished — is `summary->total() === planned`, which catches
  stop-on halts and crashes without new bookkeeping: lost workers already
  report their tests errored, so only a genuine halt leaves the tally
  short. Workers never set it; their run:finish is a handshake the
  supervisor swallows. Verified live on all three paths: full sequential
  run carries `complete:true`, `--filter` omits it, `--parallel 2` carries
  it from the supervisor.
- **Property pruning** (the D-069 re-verified deferral, closed): when
  `check()` replays every stored sequence for its key without a
  falsification — and none was inert (`CannotGenerate` means "could not
  replay", not "fixed"; the D-040 inert-entry promise holds) — it confirms
  the key to `PropertyContext`, and the runner rides the keys on
  `test:finish` as default-omitted `propertyClean`, only on a **passing
  first attempt**: a flaky pass must not prune a sequence that still
  catches the bug some of the time. `PropertyFailureWriter` drops clean
  keys before its merge — a per-key proof, so it needs no completeness —
  and, only on a complete run, sweeps orphaned keys whose test id (the
  split is on the LAST `#property `, dataset ids may contain `#`) matches
  no finished test. A key whose test lives but whose property count shrank
  survives; it costs nothing to keep and completeness cannot prove it dead.
  The file is written only when its content actually changed.
- **Snapshot pruning** (the D-042 deferral, closed): every test rides its
  visited snapshot keys on `test:finish` (default-omitted `snapshotKeys`,
  recorded at claim time in the ambient context); `SnapshotPruner` — a
  supervisor-side listener subscribed **only under `--update-snapshots`**,
  recording and pruning sharing D-042's one explicit posture — unions them
  and, only when run:finish says complete, deletes stored entries no test
  visited: `.snap` entries rewritten or whole files unlinked (the glob over
  every discovered test directory's `__snapshots__` catches files whose
  owning test was deleted — exactly what makes them obsolete), screenshot
  reference images, stale actuals, and diff pages taken along, emptied
  directories removed. **The abort-safety rule**: a snap file is untouched
  unless every finished test from its test file ran to completion (passed
  or risky) — a failed body may have died before a later snapshot call, a
  skipped or version-gated test never reached its own; their entries are
  unvisited today, not obsolete. A filtered `-u` is complete=false and
  still updates only what it ran.
- Inline snapshots (the row's other half) remain deferred — source
  rewriting shares no infrastructure with any of this.

536 suite tests, 18 fixtures conform.

### D-072: The extension surface opens — command gates as run-scoped checks

The ROADMAP's extension-surface row asked for a plugin seam. The design that
survived — worked out against phpcpd, PHPUnit's `Runner\Extension`, and a
cross-language survey — inverts the usual shape: **a plugin never judges.** It
presents a typed *artifact* (facts), and Crucible tests it against a requirement,
owning the outcome and the message. "The verdict is always Crucible's," end to
end — which dissolves the semantic-honesty hole (a plugin cannot emit a wrong
verdict when it emits none) and reuses the one assertion engine. The kinds form
a spectrum by information content (`ExitStatus` < `Measurement` < `Claim` <
`Report`); this slice ships the leanest.

- **Run-scoped checks, not tree nodes.** A whole-project gate (larastan,
  whole-suite phpcpd) has no file to anchor a `test:finish` to. Rather than
  forge a synthetic identity, outcomes gain a *scope*: file-scoped (a test) vs
  run-scoped (a check). A gate tests its `ExitStatus` and emits
  `check:finish {name, outcome, reason?}` — tallied separately, invisible to the
  file tree, the shard hash, and the D-071 `complete` gate, but a failing check
  votes the exit code with its named reason (never a bare non-zero — the
  deprecation-budget precedent). `CheckLog` collects them supervisor-side (the
  `IssueLog` discipline); `->command(label, argv, timeout?)` is the config, a
  timed `proc_open` the runner — output streams through verbatim, never parsed
  (exit code is the one contract that survives version bumps), and a command
  past its deadline is killed, so the run can never hang on a gate.

- **The ordering fix.** Checks belong inside the run bracket, but the runner
  owns `run:start`…`run:finish`. `TestRunner::run` and `Supervisor::run` gain an
  `afterTests` hook, invoked after execution and before `RunFinished`, so
  `check:finish` precedes the last event — verified on the stream. `complete` is
  untouched: checks are not planned tests.

- **No plugin ceremony yet.** The command gate is config, not a class — so this
  slice needs no `Extension` interface, no role dispatch, no boot isolation (a
  subprocess is isolated by construction), and no `TestId` change. The
  PHP-plugin surface (typed roles, a sealed artifact family evaluated through
  `Constraint::evaluate`, schema export) lands on this foundation when the first
  real consumer (phpcpd) does.

543 suite tests, 18 fixtures conform.

---

### D-073: The expected-outcome assertion — skip and incomplete made assertable

`->fails()` and `->throws()` let a test declare a terminal outcome and go
green when it happens: the expected failure *is* the pass. The idea stopped
there. Skip and incomplete — the two outcomes that are neither pass nor fail —
could only ever land in a separate tally, never assert anything. So a test that
*demonstrates* a skip (or a todo) sat in the run as an eternal `[skip]` /
`[incomplete]`, indistinguishable from an environmental skip that happened to
fire, and it could never prove the behavior it existed to show.

- **The construct is one metadata attribute.** `#[ExpectedOutcome(Outcome,
  ?reasonFragment)]` records the outcome a test expects, restricted at
  construction to `Skipped | Incomplete` — `Failed` and a throw are already
  `->fails()`/`->throws()`, `Passed` is the default, and `Risky`/`Errored` are
  defects, not outcomes one declares. It is a plain `CrucibleAttribute`: the
  phpunit dialect takes it as an attribute, and the pest dialect's
  `->expectsSkip(?reason)` / `->expectsIncomplete(?reason)` construct it — the `->fails()`
  family gaining its two missing members, reason-fragment matching and all.

- **Reconciled at the one choke point.** Rather than teach every outcome path a
  new trick, the runner reconciles in `finish()` — the single seam through
  which *every* terminal outcome passes: a body `markTestSkipped`, a declared
  `->skip()`, an unmet `#[Requires]`, a todo/wip block. If the definition
  carries an `ExpectedOutcome`, the actual outcome is judged against it there —
  a match (with the fragment, if any, in the reason) becomes `Passed` with a
  met-note; a mismatch becomes `Failed` naming both outcomes. The reconciled
  result is what `finish()` returns, so stop-on-failure and the exit code see
  the judged outcome, not the raw one. No caller knows the expectation exists.

- **The demonstrations became proofs.** The fixtures that only ever showed the
  skip/todo paths — `PestDialect.pest.php`'s skip, three `PestDialectLongTail`
  todos, and `TodoTest`'s `#[Todo]` method (now `#[Todo]` + `#[ExpectedOutcome]`,
  dogfooding both on one real method) — now assert their outcome and pass. The
  tally drops to the honest residue: the two `CoverageTest` xdebug skips, which
  are *environmental* (run when xdebug is present, skip when absent) and so
  cannot declare an expectation — left exactly as they were.

550 suite tests (`Skipped: 2, Incomplete: 0`), 18 fixtures conform.

---

### D-074: The superset is directional — the expected-outcome rename, the wip drift, and --no-wip

D-073 hung `->skips()` / `->incomplete()` on the shared pest handle, and a sanity pass
asked whether that was sound. It wasn't quite — but the fix was smaller and the lesson
larger than a revert. The engine is *single*, and its canonical dialect is crucible;
`*.pest.php` and `*.crucible.php` are not two engines but one, read through a directional
relationship: **pest ⊂ crucible.** Every pest file is valid crucible; a crucible file using the
superset extras is not valid Pest. The extras are *additive* — Pest has no such word, so
they cannot *drift*; the only thing a superset may never do is **redefine a construct it
inherited**, because that changes the result of *pest syntax*.

- **No gate — the false drift was a name.** The first instinct was to gate the crucible
  surface (`check`/`table`/`->skips`) out of `*.pest.php` with a runtime flag. Rejected:
  it inverted the model (the native path asking permission to exist), and the "drift" it
  chased was an illusion. `->skips()` only *looked* like it changed `->skip()`'s outcome
  because it mimicked a pest chainable on the same handle. Additive vocabulary in a pest
  file is harmless (it just would not run on real Pest); it is not drift. So nothing is
  gated. The lesson was in the **name**, not the placement.
- **The rename: `->expectsSkip()` / `->expectsIncomplete()`.** Superset-native names that
  deliberately do not shadow the inherited `->skip()`, so the confusion cannot recur.
  Flip-to-green semantics are unchanged (a met expectation is a pass, the `->fails()`
  family); the `#[ExpectedOutcome]` attribute and the single-choke-point reconciliation in
  `TestRunner::finish()` (D-073) are untouched. The phpunit dialect still takes the plain
  attribute.
- **The one real drift, fixed: `->wip()`.** Pest *runs* wip bodies; Crucible (D-032/D-045)
  had *blocked* them — a redefinition of inherited pest vocabulary, the exact thing a
  superset forbids. Now `blocksExecution()` is `status === Todo`: a todo is a pure
  placeholder and blocks, while **wip and done run their bodies** like Pest. A body-less
  wip/done has nothing to run, so `PestBuilder` folds it to a plain todo (fields kept,
  runnable status dropped) — no phantom pass. The "work in progress" label variant is gone
  with the blocking it described.
- **`--no-wip` — the excluded behavior as an opt-in, not a drift.** The workflow D-032's
  blocking was reaching for ("work in progress is not done, keep it out of my run") returns
  as a *custom* flag rather than a baked-in semantic: default runs wip (Pest parity),
  `--no-wip` excludes it. It rides the existing `TestSelection` Todo-metadata filter (one
  more bool beside `--todos`/`--assignee`), narrows the D-071 `fullSuite` completeness flag,
  and prints `N work-in-progress tests excluded (--no-wip)` so the exclusion is never
  silent (the no-silent-caps ethos). A custom flag changes no inherited construct, so it
  cannot drift — the same principle that clears the additive vocabulary clears this.
- **Verified**: 552 suite tests (`Skipped: 2, Incomplete: 0`), 18 fixtures conform, all of
  `composer check` green. `PestDialectLongTail.pest.php` now demonstrates a wip that *runs*
  and a body-less wip that *folds to a todo*; `TodoTest` flips "wip blocks" to "wip runs";
  `TestSelectionTest` pins `--no-wip` to dropping only wip. Smoke-checked end to end:
  `crucible --no-wip` reports one excluded test and runs 551.

### D-075: Untested is not a problem — classifying the skip that could not run

`550 passed, 2 skipped` reads, to a human, as *two tests did not pass — something is
wrong*. But a skip is not a failure, and not every skip is even a choice: the two skips
were the xdebug-driver tests, which cannot run where the extension is absent. They went
**untested**, not skipped-on-purpose, and lumping them under the summary's problem figure
(and the report's "Problems" section) is dishonest twice over — it alarms about a
non-defect, and it hides that a capability was simply missing.

The distinction is by **origin**, and the origin is knowable at exactly one place. A skip
that could-not-run is born in `TestRunner::runTest` in precisely two spots: an unmet
`#[Requires*]` and an unmet `#[Depends]`. Every other skip is a body-level
`markTestSkipped()` — a deliberate gap. So the classification is *captured where it is
known*, never *derived downstream*: sniffing the reason prose would couple a machine
decision to copy-editing, and re-evaluating requirements at report time would read a
*different* environment than the run's. This is a fact about the run; it rides the event.

- **A bool, not a seventh outcome.** `TestFinished` gains `blocked` — a flag on the
  `Skipped` outcome, exactly as `quarantined` flags without inventing an outcome. A new
  `Outcome::Untested` would break the PHPUnit six-outcome partition and ripple through
  every exhaustive `match`, the NDJSON `outcome` value, and the `#[ExpectedOutcome]` map,
  for no gain: nothing branches finer than "could-not-run vs everything-else." The
  free-text `reason` still carries the *specific* why (which extension, which dependency);
  the flag sorts the bucket, the reason is the detail, the reporter joins them.
- **Set at the two origins, gated to a final skip.** `runTest` passes `blocked: true` from
  the requirement and dependency sites; `finish()` gates it to `outcome === Skipped` *after*
  D-073 reconciliation, so an `->expectsSkip()` that turns a requirement-skip into a pass
  sheds the flag — it asserted its way out of "untested." Because `TestRunner` is
  `readonly`, `finish()`/`runTest` return `array{Outcome, bool}` and the local tally gains
  an `untested` key; the parallel `Supervisor` counts it straight off `$event->blocked` and
  merges it. `EventParser` round-trips the payload key, so `--parallel` classifies
  identically.
- **`RunSummary.untested` is a subset of `skipped`.** It is deliberately absent from
  `total()` and `toArray()` — the outcome counts already include these tests as skips;
  adding `untested` would double-count. It rides `run:finish` as its own additive field
  (the D-026 issues precedent, present only when non-zero), so a stream consumer reads the
  aggregate without re-counting the per-test `blocked` flags, and reporters split the figure.
- **The report says the consequence, the attribute keeps the rule.** `Requirements::unmet()`
  now phrases the pure presence checks as what is *absent* — "PHP extension xdebug **is
  missing**", "Function foo() is missing" — because the skip reason's job is to say why the
  test could not run. Version, OS, and setting checks already state the actual gap, so they
  are unchanged. "Required" still lives where it belongs: in the `#[RequiresPhpExtension]`
  declaration.
- **Problems first, then a quiet Untested block.** Every human-facing reporter splits the
  tally (`Skipped` becomes the deliberate skips alone, `Untested` appears when there is any)
  and lifts the blocked skips out of Problems into an **Untested** section placed *after*
  Problems, grouped **by reason** so a missing capability is stated once with the tests it
  stopped listed beneath. Console draws it in gray with a gray `S` progress mark; the PDF
  gives it a section (a drawn disc for the bullet — base-14 fonts carry no `•`) and splits
  `N untested` from `N flagged` on the strip; Markdown adds an `Untested` column and section;
  TestDox marks the untested test `⊘` rather than the skip's `↩`. The machine formats are
  left alone on purpose — a blocked skip is a legitimate `<skipped>` (JUnit) / `testIgnored`
  (TeamCity), and their consumers expect exactly that.
- **Verified**: 562 suite tests (`Passed: 560, Untested: 2` — the xdebug-driver tests), 18
  fixtures conform, `composer check` green. `UntestedClassificationTest` pins the two
  origins as blocked, a deliberate skip as *not* blocked, and the `->expectsSkip()` gate;
  `EventPayloadTest` pins the payload key and the subset arithmetic; `ReportersTest` covers
  the console, Markdown, and TestDox rendering (and finally gives `ConsoleReporter` a
  `CoversClass`). The shape — bool over enum, dependency-skips joining requirement-skips,
  order, and wording — was settled with the user *before* any code, the deliberate remedy to
  the drift the prior session recorded.

### D-076: Inline snapshots — the expected value written back into the test source

The file snapshot (D-042) keeps the recorded value in a `.snap` file beside the test; the
inline snapshot keeps it in the test's own source, as the argument to the assertion. For a
small, stable value that reads better in place than in a sidecar file, it is the nicer form —
and it is the first piece of **source-rewrite infrastructure**, which a future auto-fix pass
will share. Everything that can be reused from D-042 is: the `Exporter` serializer, the
ambient `Snapshots` context, the `--update-snapshots` recording gate, the created/updated
counters and their `test:finish` payload. Only the rewrite engine is new.

- **The spellings mirror the file flavor.** `assertMatchesInlineSnapshot($value, $expected =
  null)` and `expect($value)->toMatchInlineSnapshot($expected = null)`. The expected value is
  the argument; passing nothing is the request to record. Explicit-by-principle holds exactly
  as for files: a missing value **fails and names `--update-snapshots`** — never invented on a
  normal run.
- **The call site is captured at the entry point.** Each dialect method reads
  `debug_backtrace(…, 1)[0]` — its immediate caller is the user's test line — and passes
  `(file, line)` down. No fragile framework-frame walking.
- **Recording is buffered and applied bottom-up at run end.** A multi-line value writes as a
  nowdoc, which adds lines and shifts every later line; applying a file's edits highest-line
  first keeps each not-yet-applied call's captured line valid. Reassembly is exact — token
  text concatenated verbatim, only the one argument span swapped — so a file with no recorded
  snapshot is byte-untouched.
- **The rewrite is argument-position aware.** `toMatchInlineSnapshot` takes only the snapshot
  (the value came from `expect()`), so its whole argument is replaced; `assertMatchesInline
  Snapshot` takes the value *first*, so the writer preserves it and appends/replaces the
  **second** argument — found by the first top-level comma (depth-tracked, so commas inside
  nested calls or arrays do not mislead). The first cut of the writer wiped the value arg;
  the end-to-end record→compare smoke caught it before it shipped.
- **Multi-line → nowdoc, single-line → quoted.** A nowdoc (`<<<'SNAPSHOT'`) reads as itself
  in a diff and round-trips without interpolation; the marker lengthens until no content line
  collides with it. A one-line value is a single-quoted string.
- **Recording is sequential-run only (the v1 rule, user decision).** Rewriting a shared source
  file from parallel workers would race, so the writer only runs when the whole run is
  sequential and in-process. Comparison works everywhere (read-only); a `-u` that needs to
  *record* under `--parallel` or process isolation **fails naming the constraint** — never a
  silent race. Full worker→supervisor rewrite-intent plumbing is a possible v2, deferred.
- **Verified**: 578 suite tests, 18 fixtures conform, `composer check` green.
  `InlineSnapshotWriterTest` pins the pure transformation (both spellings, nowdoc, chain
  survival, marker collision, byte-exact no-op, bottom-up flush on a real temp file);
  `InlineSnapshotTest` pins the assertion semantics and runs two committed dogfoods through the
  live dialect entry. The full record → compare → update → parallel-guard cycle was smoke-run
  end to end against a throwaway project. The two forks — parallel-recording scope and literal
  format — were settled with the user before the writer was built.

### D-077: The mutation-facing query index — the half that has no consumer risk

"Mutation testing interop" is two halves with very different readiness. **(A)** a *queryable
coverage+timing index* — for a mutated line, which tests cover it, cheapest-first — and
**(B)** a *warm mutant re-run API* — swap the mutant in, run that ordered subset, stop at the
first kill, return a structured verdict. Half A assembles data Crucible already records; half B is
a whole re-run protocol whose shape is only knowable from a concrete consumer. So A shipped and
B is gated, the same discipline as the extension surface (its shape owned by phpcpd) and Cest
(gated on a demand probe): building a re-run protocol to a *hypothetical* Infection adapter is
the "if it can't express the real consumer, the surface is wrong" trap.

- **A is an inversion + a join, over existing data.** The per-test line map (D-047) already
  answers "which lines did test T run"; `MutationIndex` inverts it to "which tests ran line L",
  joins each with the result cache's last duration, and orders **fastest-first** — a mutation
  tool runs the cheapest covering test first to kill a mutant soonest. Untimed tests sort last
  (an unproven cost is not promised cheap) and ties break on the id, so the order is
  deterministic and replayable.
- **The interop surface is a CLI/JSON artifact, not a PHP API.** The eventual consumer is a
  separate process, so `crucible mutation-index` emits the whole index as JSON — `file → line →
  [{id, duration}, …]` — which the tool loads and queries. It reads the committed
  `coverage-lines.json` (from a prior `--coverage` run) and the result cache; with no line map
  it fails naming `--coverage`, the D-041 opt-in-owes-the-way-in rule. No overhead on normal
  runs.
- **B is deferred with a named re-open.** The warm-worker re-run API (mutant swap, ordered
  fastest-first run, stop-on-first-fail, killed/escaped/error/timeout verdict) lands when a
  real driver exists — an Infection framework-adapter, or an in-framework mutation runner —
  because only that driver's needs fix the protocol's shape. Recorded in `ROADMAP.md`.
- **A gotcha worth remembering.** Inserting `mutationIndex` *between* `lintInline`'s docblock
  and its method silently orphaned the docblock's `@param non-empty-string` onto the new
  method — and PHPStan, which reads a private method's `$workingDirectory` narrowing from that
  annotation, then failed five call sites *inside the untouched `lintInline`*. The fix was the
  docblock placement, not the types. A method's docblock and its signature must never be split.
- **Verified**: 581 suite tests, 18 fixtures conform, `composer check` green.
  `MutationIndexTest` pins the inversion, the fastest-first order, untimed-last with
  deterministic ties, and empty results for an uncovered line; the command was smoke-run
  against crafted line-map + result-cache fixtures and against the missing-coverage path. Scope
  — build A, gate B — was the user's call.

### D-078: The PHP-native extension surface — roles, the Claim artifact, proven on phpcpd

D-072 shipped the artifact model with one tier: the shell `CommandGate` (run a command,
Crucible tests its `ExitStatus`). This is the PHP-native tier — an `Extension` that works
in-process — and the discipline that gated it was met: **a real first consumer, phpcpd,
drove the shape.** The rule was "if it cannot express phpcpd, the surface is wrong," and the
port proved it can.

- **The consumer was not what the ROADMAP assumed.** phpcpd's existing PHPUnit integration is
  a *Constraint* (`assertNoDuplication`), not a `Runner\Extension`. So "porting it" meant
  building phpcpd as a *run-scoped* check — detect once for the whole project, not per test —
  which is exactly what this surface is for. Reading its real facts (`Phpcpd::detect()` returns
  a `CodeCloneMap`: a clone count plus locations) fixed the design: the artifact it needs is a
  count-against-zero with the locations as detail.
- **Composable role interfaces, dispatched by `instanceof`.** An `Extension` is a marker; it
  plays roles, each its own small interface. `Check` (`label()` + `inspect($wd): Artifact`)
  is the only role today — the project inspected once after the suite. Subscriber and reporter
  roles land with *their* consumers. A plugin carries only the roles it fills; Crucible filters
  `instanceof Check` and runs those. ISP over a fat interface.
- **The `Claim` artifact keeps the plugin from judging.** A check presents the two operands it
  observed — `actual` against `expected` — plus optional detail; `CheckRunner` tests identity
  and owns the outcome and message. phpcpd presents `Claim(count, 0, locations)`; Crucible is the
  one that tests `2 === 0` and fails the run. The requirement lives in the operand the plugin
  states, not in a boolean it computed.
- **Registered as a typed instance, not a class+params sketch.** `->extension(new PhpcpdCheck(
  paths: ['src'], minTokens: 70))` — the user constructs the extension with its own typed
  config in `crucible.php`. No dynamic `new $class(...$params)` reflection, no untyped param bag;
  the config file is typed PHP, so an instance is the idiom. Better than the ROADMAP's
  `->extension(Class, [params])` sketch.
- **It reuses everything.** `CheckRunner` grew a second artifact arm (`Claim` beside
  `ExitStatus`), and the check runs in the same after-tests hook as command gates, so its
  `CheckFinished` lands in the run bracket, tallies separately, and votes the exit code with a
  named reason — all D-072 machinery, unchanged. A throwing check errors (with its message)
  rather than crashing the run.
- **Verified**: 584 suite tests, 18 fixtures conform, `composer check` green.
  `CheckExtensionTest` pins a satisfied claim (pass), an unsatisfied one (fail, naming both
  operands and the detail), and a throwing check (error). The port — `PhpcpdCheck` in phpcpd's
  own `integration/crucible/`— was run end to end through the real `crucible` binary against a
  project with genuine duplication: the run went red with the clone locations printed and
  **exit 1**, and green (**exit 0**) once the duplication was removed. The three forks — role
  model, artifact kind, slice scope — were settled with the user before the surface was built.

### D-079: The Vitest stream adapter — one runner over the whole stack

Crucible runs PHP; a Vue/React app runs JavaScript through Vitest. The two-runner split means two
commands, two reports, two exit codes, two flakiness policies. The fix is to **orchestrate, not
reimplement**: spawn Vitest, read its machine output, and translate it onto the one NDJSON
stream, so a JS test is a first-class `test:finish` beside the PHP ones — same tree, same
tally, same report, same exit code.

- **The gate was a contract-stability question, and it was answered against reality.** The
  ROADMAP flagged the risk RESEARCH.md recorded from Node: do not parse a reporter output that
  is really an internal. So the design started by capturing the *actual* `vitest run
  --reporter=json` from a real Vitest 4 app (a production Vue/Inertia suite), not the docs. It is
  Jest-`--json`-compatible — `testResults[]` per file, each with `assertionResults[]` carrying
  `fullName`, `status`, `duration` (ms), `failureMessages[]` — a de-facto stable contract many
  tools already consume. Risk retired on evidence.
- **The translator is pure and defensive.** `VitestReport::translate(decoded, projectRoot)`
  turns each assertion result into a `TestFinished`: file made relative, status mapped
  (`passed`→Passed, `failed`→Failed, `todo`→Incomplete, else Skipped), ms folded to seconds,
  failure messages carried. Malformed entries are skipped, never fatal — the parser is the one
  place a foreign contract is trusted, so it trusts nothing.
- **The fold is a count delta, computed after the completeness gate.** `VitestRunner` spawns
  vitest (argv form, no shell; stdout discarded, the JSON rides `--outputFile`), emits a
  `test:start`/`test:finish` pair per JS test, and returns a `RunSummary` of what it saw. The
  after-tests hook — previously `(): void` for checks — now returns `?RunSummary`, and both run
  paths (`TestRunner::run` and `Supervisor::run`) merge it via `RunSummary::plus`. Crucially the
  **D-071 `complete` flag is computed on the PHP plan first**, so folded-in JS tests never touch
  completeness; being un-scheduled, they stay out of the shard hash by construction too. This is
  the whole reason JS becomes a real part of the tally rather than a bolted-on second section.
- **Never a hang, never a silent pass.** A suite whose vitest cannot start (its spawn warning
  suppressed, since the failure is handled) or produces no JSON reports one **errored** test
  with the reason — visible on the stream, failing the run — rather than quietly passing.
- **Verified end to end on a real suite.** `VitestReportTest` and the `RunSummary::plus` test
  pin the pure pieces; then a `crucible` run with `->vitest(...)` pointed at a real Vue/Vitest
  project folded its **369 real JS tests** in with the PHP one — `Tests: 370 … OK`, the JS tests named in the testdox
  tree, all green at exit 0; pointing at a vitest-less directory reported one errored `vitest`
  test at a failing exit. Scope was the user's call: results translation now, cross-stack impact
  selection (changed `.vue` → `vitest --related`) and watch integration deferred to later slices.

### D-080: Cross-stack impact selection — a changed `.vue` narrows the JS suite too

The first of the two Vitest slices D-079 deferred: impact selection, which until now spoke only
PHP, learns to narrow the JavaScript tier from the same change set. A changed file under a
configured Vitest suite turns that suite's run from `vitest run` into `vitest related <files>
--run` — and Vitest's own module graph decides which JS tests re-run, so Crucible keeps
orchestrating rather than reimplementing a second dependency walk (the D-079 principle).

- **The JS tier is symmetric to the PHP one.** `Impact\VitestImpact::select()` sits beside
  `ImpactSelection`: it takes the configured suites and a `ChangedFiles`, and returns the suites
  to run — each narrowed to the changed files that fall under its directory — plus human notes.
  The safety direction is copied deliberately: **any deletion widens every suite to a full run**
  (a deleted file's dependents are invisible to the graph, exactly why `ImpactSelection` widens
  PHP to everything), and a suite with no JS change is **dropped but named**, never silently
  skipped. `VitestSuite` gained a `related` field; a non-empty one is what flips `VitestRunner`
  to the `related` command form.
- **An affected JS suite keeps the run alive when no PHP test does.** This is the case that
  matters — a changed `.vue` with no PHP dependents. Impact mode used to `return 0` ("No tests
  are affected") the moment the PHP selection came back empty, and the "No tests found" guard
  would have refused an empty plan anyway; both now yield to a `$jsAffected` flag, so the run
  proceeds with **zero PHP groups** and the Vitest suite folds in through the normal
  after-tests hook — bracketed by the same `run:start`/`run:finish`, tallied on the one stream.
  A JS-only run has nothing to parallelize or isolate, so it always takes the sequential runner
  path. Completeness is untouched: `fullSuite` is already false under any impact flag, so a
  folded-in JS suite never marks the run complete (the D-071 gate holds).
- **Verified end to end on the real suite.** `VitestImpactTest` pins the pure selector (narrow,
  drop-and-name, deletion-widens, per-suite independence). Then a `crucible --related` at a real
  source file in a Vue/Vitest project, with an **empty PHP suite**, folded in only the **38**
  dependent JS tests (`vitest related` narrowing 369 → 38) at exit 0; the same run pointed at an
  unrelated file dropped the suite and reported "No tests are affected" at exit 0; and a PHP
  impact run with no Vitest configured was byte-for-byte unchanged. Watch integration (JS files
  in the D-037 watcher) remains the last deferred slice.

### D-081: Watch-loop integration — a JS edit re-runs the JS graph, not the whole suite

The last of the two Vitest slices D-079 deferred: the D-037 file watcher, which spoke only PHP,
now watches the JavaScript tier too. Editing a `.vue`/`.ts`/`.js` file under a configured Vitest
suite re-runs through `--related` — where D-080 narrows it to `vitest related` — instead of
`WatchSession` bailing every non-PHP change to a full run. Thin wiring, because the child re-run
already threaded `--related`; the work was teaching the watcher and the state machine that a
second graph exists.

- **Watch the suite, know its root.** `Application::watch()` adds each Vitest directory to the
  watched set, and constructs `WatchSession` with those roots. The session — still pure, no IO —
  gains one classification: a changed file is re-run via `--related` when it ends in `.php`
  **or** lives under a JS root; only a file in *neither* graph widens to a full run (its message
  reworded from "a non-PHP file changed" to "a file outside every dependency graph changed").
  The failed-set stickiness (the atoum loop, the issue-#10247 fix) now spans both stacks for
  free: a red JS test rides along on every subsequent edit like a red PHP one.
- **`node_modules` is the JS `vendor`.** `FileWatcher` already refused to descend into `vendor/`
  and hidden directories; watching a JS root would otherwise walk `node_modules`, so it joins the
  skip list. Without it the poll would scan a dependency tree of thousands of files every quarter
  second.
- **JS test paths made round-trippable (a D-079 refinement this slice required).** Vitest test
  files were reported relative to the JS project root, while PHP files are relative to the working
  directory — so re-feeding a failed JS test's path to `--related` (which resolves against the
  working directory) would have missed. `VitestRunner` now translates against the **working
  directory**: a suite inside it yields a working-directory-relative path (consistent with PHP,
  and locatable), one outside keeps its absolute path — both of which `--related` resolves. The
  translator already kept a non-matching prefix as-is, so this was a one-argument change, pinned
  by a new round-trip test.
- **Verified by driving the real loop.** `WatchSessionTest` and `FileWatcherTest` pin the
  classification and the `node_modules` skip. Then `crucible --watch` on a project whose Vitest
  suite is a real 369-test Vue app: the initial full run went green (`Tests: 370`, "waiting for
  changes"), and **touching a source file triggered "Running: affected by the change → Vitest
  suite … running related → `Tests: 38`"** — the JS module graph narrowing the run, no full-suite
  bail, no hang. The browser tests that visit a changed `.vue` — the other half of cross-stack
  impact, needing a browser-visit → asset observed-edge source — remain a slice of their own.

### D-082: Mutation testing — the warm re-run engine, and its portable cold fallback

The other half of D-077 (which shipped the covering-tests *query*) lands as a runnable command:
`crucible mutate` generates mutants across the covered source, runs each against its covering tests
fastest-first, and reports the mutation score. What made this buildable now rather than
consumer-gated was recognizing that the pieces are all *researched or observable* — the verdict
lexicon is standard, the warm-execution technique is in the literature, Infection is the PHP
reference — so only the integration seam ever needed a consumer, and the CLI/JSON precedent from
D-077 supplies it.

- **Warm application, proven before it was built.** A mutant runs in a `pcntl_fork`ed child that
  loads the mutated bytes on first reference, through a **prepended autoloader serving a unique
  temp path** — a fresh path means opcache compiles the mutant cleanly, so no invalidate is ever
  needed (the one real unknown, retired by a 40-line spike). The child writes its verdict to a
  file and dies by `SIGKILL`, skipping every shutdown handler the parent registered. The
  precondition is redeclare-safety: a class already loaded cannot be re-mutated in a fork, so the
  applier exposes `canApply` and the parent is built to **discover and generate but never run** a
  test — the source under mutation stays unloaded, and every mutant stays warmable (verified: the
  target class is absent from the parent after discovery).
- **Fail-soft to a cold path, never a crash.** Warm needs `pcntl`/`posix` — the fork and the
  signal — which are absent on Windows and minimal builds. Rather than fatal, `MutantApplier::
  isSupported()` gates a **cold executor** that spawns an ordinary `crucible --worker` over
  `proc_open` (the machinery the whole runner already uses), injects the mutant through the
  environment so the worker's autoloader serves it before discovery, and reads the verdict off the
  NDJSON stream. Core Crucible touches neither extension; mutation is their sole user, and it degrades
  in *speed*, never correctness. A `MutationRunner` routes each mutant — warm when it exists and
  can run it, else cold, `NotCovered` (no spawn) when no test covers it — so a pcntl-less box runs
  every mutant cold by construction.
- **An engine, not a catalog.** A `Mutant` is an *input* — mutated source plus its autoload key.
  The token-based catalog (arithmetic, comparison, logical) is a separate, pure concern: each
  mutator is a swap table keyed by exact token text, so the tokenizer's own resolution of `+=`,
  `++`, and a `+` inside a string makes a bare `+` unambiguously the binary operator, every swap
  preserves arity (the mutant is always syntactically valid), and the generator rebuilds each
  mutant by token-text concatenation — **byte-exact**, differing by exactly one operator.
- **The staleness guard, the valuable inverse of a bypass.** The command consumes a *pre-built*
  D-077 index, so the risk is not over-verifying but using a **stale** one: coverage older than the
  source it maps is a lie — an "escaped" mutant may just be a covering test that no longer runs. So
  the run warns and names the drift rather than reporting a false score.
- **Verified end to end, warm, on real code.** Six slices, each green through `composer check` +
  conformance. On a scratch project the warm path reported `Killed: 1  Escaped: 1  MSI: 50.0%` at
  exit 1 — naming the arithmetic mutant a weak `+ 0` test lets slip — with the target confirmed
  unloaded (warmable) and the staleness guard firing after a touch. Pointed at **Crucible's own
  source** it generated **291 mutants across 23 files** and surfaced genuine gaps in the assertion
  constraints. Inside the fork the covering tests run **fastest-first**, through the same
  dependency-safe scheduler the suite uses (D-021): the cheapest test gets the first shot at the
  kill, and dependency deferral guarantees a reordered dependent never runs ahead of its
  dependency and fakes one.

### D-083: Cross-stack retrigger — the producer pushes, Crucible never reads the build

*Both halves have shipped — declared impact rules, then the retrigger endpoint. Most of this
entry's length is the alternatives declined, which is the part worth keeping.*

D-081 left one slice open: "the browser tests that visit a changed `.vue`", needing a
browser-visit → asset **observed-edge source**. Investigating that premise against a real
multi-build Vite project retired it. Every mechanism that could map a loaded asset back to a
source file requires Crucible to own working knowledge of a JavaScript build system — and each one
fails in the single direction a test tool must never fail: **silently selecting too few tests**.

- **Three candidate edge sources, all declined on the same ground.** *Manifest inversion* —
  reading Vite's `manifest.json` to turn a hashed URL back into a source path — presumes one
  manifest at a conventional location. The project examined had **two**, one per build, each
  nested under its own build directory, with keys relative to its own Vite config root at
  *differing* depths; the count grows with the number of themes, and none of it is derivable from
  the manifest's own position on disk. *Root inference* — recovering that root by matching a
  manifest key's suffix against project files — is a guess whose failure raises nothing, it just
  yields no edges. *Static `visit()` detection* — identifying browser tests by scanning for the
  call — is dialect-specific (a bare function in the Pest dialect, not a class reference the
  `ReferenceScanner` resolves) and equally quiet when wrong. Each converts a wrong assumption into
  a test that never ran, which is strictly worse than the full run it was avoiding.
- **A stale build is a silent lie, and a guard is not the answer.** In the project examined, both
  manifests predated three of their own shared sources by fifty minutes. Against those manifests a
  component change would have selected *fewer* browser tests than it should — the D-082 staleness
  guard's failure mode inverted: there, stale coverage yields a misleading number; here it yields
  a test that did not run. Widening on staleness would have been mandatory, which is a strong hint
  the mapping should not exist at all.
- **The scope line: the browser tier tests answers, not components.** A browser test asserts what a
  route renders and how it responds to interaction; it does not test a component. Its natural
  granularity is the **route**, which is server-side and already visible to Crucible. Mapping a
  component to the routes that reach it is a fact of the JavaScript build. D-079 settled the
  principle — Crucible orchestrates Vitest for the JS module graph rather than reimplementing it — and
  applying it consistently forbids a second, worse copy of a bundler's resolver living in a PHP
  test tool.
- **The inversion: whoever knows, says so.** The information Crucible could not reconstruct already
  exists on the side that owns it, at the moment it is true — a Vite plugin's `handleHotUpdate`
  receives the changed file and its affected module set; `writeBundle` fires when a build
  completes. So the producer **pushes** a change set instead of Crucible reconstructing one from
  artifacts. Staleness disappears rather than being guarded: no build event means nothing to
  re-test, and no manifest is ever read. HMR's `?vue&type=style` and `?t=` query forms — Vite
  conventions, not standards — stop mattering for the same reason.
- **The transport: an opt-in loopback endpoint, with a hot file as address and liveness.**
  `Browser\Server\InProcessServer` already binds `tcp://127.0.0.1:0` — loopback-only, ephemeral
  port, non-blocking, and `Pumpable` by design — and the `WatchLoop` parent already polls on an
  interval while each run happens in a child, so hosting the listener adds no concurrency. Its
  ephemeral port is published the way Vite publishes its own dev server: a **hot file** holding the
  full URL including a token, written when listening starts and removed when it stops, so its
  presence *is* the liveness signal and a producer needs no second configuration to know whether to
  bother. Listening is **opt-in** — a test tool must not open a socket by default — and the port is
  configurable for the cases (containers, fixed firewall rules) that cannot read the file. The
  payload is **paths only**, never filters or anything command-shaped: the worst a caller can do is
  make the developer run their own suite. `ChangedFiles` gains a second source beside `fromGit()`;
  everything downstream — the router, the impact rules, Vitest narrowing — is untouched.
- **Declared impact rules replace every per-file-type rule.** The gap was never about Vue, or even
  about JavaScript: the graph resolves *class references*, so anything reached by convention, path,
  or runtime lookup is invisible to it regardless of extension — Blade templates and `lang/*.php`
  files end in `.php` and are equally unreachable, since no test references them. So `crucible.php`
  declares **path pattern → groups**, and a matching change adds those groups to the selection.
  **Additive only**: a rule may widen, never narrow, so a missing rule is never worse than today
  and the widen-on-unknown invariant holds. This subsumes the browser tier as one ordinary rule
  rather than a special case, and leaves Crucible inferring nothing about builds, bundlers, or
  frameworks — only the author knows which tests a non-code file's change puts in doubt.
- **No broker.** Reverb, Redis pub/sub, Mercure and Pusher were all declined for one disqualifying
  reason: they invert the dependency direction, making a core dev-loop feature of a
  framework-agnostic tool depend on the application under test — and most projects Crucible runs on
  are not Laravel, or not web applications at all. They also couple lifecycles wrongly (a test
  runner that cannot re-run because the app's socket server is down) and bring channels, keys and
  auth to carry one fire-and-forget message that `curl` can send with no dependencies. Fan-out to
  many subscribers — an IDE, a dashboard, a live status overlay — is the case that would justify
  one, and it would sit on the existing NDJSON event stream rather than replace it. HTTP in for the
  trigger, NDJSON out for results, nothing in between.
- **Sequenced by demand, not by design appetite.** The two halves are independent, and the rules
  are the load-bearing one: they deliver the selection on their own, need no server, and help files
  that have nothing to do with JavaScript. The endpoint only makes the same signal precise and
  immediate. So the rules landed first and the endpoint followed once a producer was actually
  wanted — the D-070 method applied to our own design rather than only to other people's formats,
  since the temptation to build the interesting half first is exactly what this entry spent its
  length arguing against.
- **Shipped: the rules, verified end to end.** `Impact\ImpactRule` is a pattern plus the groups it
  puts in doubt; a wildcard-free pattern is a **prefix**, so naming a directory covers everything
  beneath it and the silent no-match is not the easy mistake to write. `ImpactSelection` takes them
  as a fourth argument and offers **every** changed file to the rules *before* the `.php` gate —
  which is what makes a Blade template and a `lang/*.php` file reachable, the two cases that proved
  this was never about JavaScript. A rule-matched file stops being counted in the "non-PHP … not
  considered" note, since it *was* considered. Selection is a union, never a filter: a group the
  graph already chose is not re-added, and a rule that matches nothing leaves the graph's answer
  byte-identical. The one way a declared rule can be silently wrong — naming a group no test
  carries — is detected and named, the same reflex as every other note here. On a scratch project
  (baseline `Tests: 3`): `--related resources/js/Cart.vue` reported "group(s) browser put in doubt"
  and ran `Tests: 1`; `--related resources/lang/messages.php` — a file the graph cannot reach
  *despite ending in `.php`* — selected the i18n test alone; an unmatched `README.md` still reported
  "not considered" and no tests affected; and a typo'd group name reported "carried by no test"
  rather than quietly selecting nothing. 628 self-hosted tests green, 18 fixtures conform.
- **Shipped: the endpoint, on the machinery that already existed.** `Browser\Server\
  InProcessServer` was already a dependency-free HTTP server bound to loopback on an ephemeral
  port, non-blocking and `Pumpable` — browser-specific only in where it was first needed — so it
  gained one optional `$port` argument and a second consumer rather than a twin. `WatchLoop` joins
  its streams to the `stream_select` it *already* performs on STDIN, so a push wakes the loop at
  once instead of on the next 250 ms tick, and the endpoint's streams are selected even with no
  tty, where there is no key to wait for. `RetriggerEndpoint` is pure (parse, authorize with
  `hash_equals`, accumulate) and `RetriggerListener` owns the socket and the hot file; `open()` is
  **fail-soft** — a taken port or a read-only directory returns null and the session watches
  exactly as it did before the feature existed. `WatchSession` had to learn the rules too:
  its "outside every dependency graph" branch would otherwise have widened every pushed asset to a
  full run, defeating the precision the push exists to provide.
- **The bug only a real run could find.** Driving an actual `crucible --watch` showed the hot file
  **surviving a killed session** — `finally` does not run on a signal, and a watch loop is normally
  ended with Ctrl-C, not `q`. A stale hot file claims a dead session is listening, which is the one
  thing the file must never do. The same gap was already leaving the user's terminal with echo off
  after Ctrl-C, unnoticed until now. Both are fixed by a guarded `pcntl_signal` handler — the only
  place outside mutation that touches the extension, `function_exists`-gated so it remains an
  enhancement and never a requirement.
- **Verified end to end, against the shipped artifact.** The suite runs the **real
  `integration/crucible-retrigger.mjs` under node** against a live listener rather than a
  re-implementation of it, skipped when node is absent — testing a copy would prove nothing about
  the file people paste into their projects. Then the whole chain, driven for real: `crucible --watch`
  published its endpoint, `retrigger(['resources/js/Cart.vue'])` from node returned true, and the
  loop ran "group(s) browser put in doubt → `Tests: 1`" **without the file ever being touched on
  disk** — the push alone drove it. A `curl` of the documented contract selected the i18n test the
  same way, a wrong token got `403`, killing the session removed the hot file, and a producer
  finding no hot file reported "not listening" and exited cleanly. 643 self-hosted tests green, 18
  fixtures conform.

### D-084: The network-settle primitive — the blocker under the framework assertions

The Inertia/Livewire row named one thing standing between the browser tier and framework-protocol
awareness: *"a network-settle primitive the driver does not have yet."* It is built and proven, on
its own, because it is worth more than the row that asked for it — every round-trip assertion needs
it, and `wait(0.5)` is the flake it replaces.

- **The driver already knew; nobody had asked it.** Probing the live protocol showed `Request` and
  `Response` objects never arrive at all — until the client calls `updateSubscription`, after which
  both flow with their URLs. So the primitive rests on the browser's own request lifecycle rather
  than a `performance.getEntriesByType` heuristic reconstructed page-side. Subscription is
  remembered in `Connection::subscribe()` — the surfaces that need it are `readonly`, so the memory
  belongs with the connection, and the existing console subscription is the same shape.
- **`request`/`requestFinished`/`requestFailed`, deliberately not `response`.** A request that
  fails, or whose body never arrives, must still end the wait it started; counting responses would
  hang until the timeout on exactly the cases worth testing. Network events arrive on the
  **browser-context** guid rather than the page — probe-pinned, the same asymmetry `consoleLogs()`
  already lives with.
- **A quiet period, not an instant.** "Nothing in flight right now" is true a millisecond after
  load and again between two requests, so a bare check sails past a handler that fires 50 ms later
  — the exact shape of an Inertia visit or a Livewire update. The wait requires continuous silence,
  and a page that never provides it **throws a named failure** rather than passing as settled.
- **Verified against a real browser and a real server, then sabotaged to check the tests bite.**
  Four cases through Chromium over `InProcessServer`: a quiet page settles; a fetch that starts
  150 ms *after* load is still waited for (`window.LANDED` proves the wait outlasted it); a page
  fetching every 30 ms fails with "never went quiet"; a 404 ends its own wait. Then the wait was
  temporarily replaced with an immediate `return` — two of the four failed, which is the evidence
  that they test the behavior rather than accompany it. 647 self-hosted tests green, 18 fixtures
  conform.
- **What this shipped without, and the gate that had to go.** The framework layer above it —
  `assertInertiaProp`, `wire()` — is not in this entry; it follows. It was first deferred as
  "gated on a real application to prove against", and that reasoning was **half right and half
  circular**. The honest half: an assertion about a framework protocol proven only against a
  hand-written page imitating that protocol tests the imitation — the trap D-083 avoided by
  executing the shipped producer rather than a copy. The circular half: treating "no project I can
  reach runs Livewire" as a *demand* signal, when Crucible is unreleased and therefore no feature can
  ever demonstrate demand. A gate that cannot open is not a gate. Both packages are public and
  installable, so the constraint was never evidence — it was the absence of a scratch app nobody
  had built yet.

### D-085: Inertia awareness — recorded before boot, proven against the real library

The client half of the last roadmap row, built on D-084's settle primitive. It exists at all
because the deferral that held it was examined and found circular (see D-084's closing bullet):
a demand gate cannot be satisfied by an unreleased tool, so the only real question was whether the
behavior could be *proven* — and it could, for the price of an `npm install`.

- **The claim the design rests on, and why it needed the real package.** Inertia keeps the current
  page in its client adapter; `data-page` carries only what the server rendered and is **never**
  updated by a client-side visit. Everything else follows from that, so it is the one thing that
  must not be taken on trust. `inertia-oracle/` holds a real `@inertiajs/core` (3.6.1) bundled with
  esbuild into one browser-loadable file — the same relationship `browser-oracle/` has to
  Playwright. A page that merely dispatched `inertia:navigate` would have satisfied any test
  written against it while proving nothing about the library.
- **Observed through the documented event, not through internals.** `inertia:navigate` and
  `inertia:success` both carry `detail.page` — a public contract — so the current page is readable
  without the application exposing its router and without Crucible reaching into the adapter. The
  recorder is two listeners and one object; the DOM's `data-page` is the **fallback**, correct
  before any visit has happened and stale forever after.
- **Installed before the application boots.** A visit that completes before an assertion subscribes
  has already fired its event, so a listener added at assertion time sees nothing. `BrowserContext::
  addInitScript()` runs the recorder in every page of the context ahead of the page's own scripts.
  Worth recording how that was established: a throwaway probe script reported `addInitScript` as
  accepted-but-ineffective **twice**, and both readings were wrong — once because the probe used a
  `data:` URL (which runs neither init scripts nor network requests), once because shell quoting
  silently mangled the edit. Written as an ordinary test against the real harness, it passed on the
  first run. `tests/unit/Browser/InitScriptTest.php` now pins it, so the seam is guarded rather
  than rediscovered.
- **Verified, then sabotaged with the sabotage itself verified.** Three cases drive the real
  router: the server-rendered page reads from the DOM; a real client-side visit — `router.visit()`
  against an `X-Inertia` JSON response, settled with `waitForNetworkIdle()` — is seen as
  `Users/Index` **while the same test asserts `data-page` still says `Home/Index`**; and a
  non-Inertia page returns null rather than a failed match. Removing the two listeners fails the
  middle test with exactly `Home/Index` where `Users/Index` was expected, which is the premise
  proving itself. An earlier sabotage attempt appeared to change nothing — it had silently failed
  to apply, so the script now asserts its own target exists before claiming anything. 652
  self-hosted tests green, 18 fixtures conform.
- **Livewire is not done, and is not gated either.** It needs its own oracle — a real
  `livewire/livewire` install, which is a Laravel application rather than a bundled JS file, so it
  is a larger harness rather than a blocked one. `wire()` waits on that harness, not on permission.

### D-086: Livewire round-trip helpers — and the asymmetry only a real app could show

The last open row. `wire()` reads a component's state, `assertWireSet()` checks one property
(named after Livewire's own server-side `assertSet()` so the two tiers read alike), and
`wireClick()` clicks and waits for the update cycle it starts.

- **The finding: Livewire is the mirror image of Inertia.** A scratch Laravel 4.x application was
  built solely to answer one question — does a round trip update `wire:snapshot` in the DOM, or is
  it only what the server first rendered, the trap `data-page` turned out to be? It updates:
  clicking moved the snapshot from `count:0, label:untouched` to `count:1, label:incremented` with
  a fresh checksum. So Livewire needs **no recorder at all**, where Inertia needs one installed
  before boot. Assuming the two frameworks behaved alike — in either direction — would have
  produced either a redundant recorder or a permanently stale read. No amount of reasoning would
  have settled it; running the real thing took minutes.
- **The oracle has to be an application, not an asset.** Inertia's client is one JS bundle, so
  `inertia-oracle/` is an esbuild output. Livewire's client is inseparable from its server: the
  cycle `wireClick()` waits for is an HTTP request to Livewire's own update endpoint, which
  recomputes the component and rewrites the snapshot. `livewire-oracle/` is therefore a real
  Laravel install with a real `livewire/livewire`, and the test boots `artisan serve` on an
  ephemeral port and shuts it down again. It is gitignored like the other oracles, and its absence
  skips rather than silently passes. One consequence worth recording: an application inside the
  repository is in Pint's path, and Pint duly rewrote Laravel's own skeleton — the oracles are now
  excluded, and the app was rebuilt from the README's own instructions, which incidentally proved
  those instructions work.
- **A silent bug the sabotage check caught, and a weak test it caught first.** With `wireClick()`
  neutered to skip its wait, the suite still passed — because the component returned instantly and
  there was no race to lose. The test was accompanying the behavior, not testing it. Giving the
  oracle's `increment()` a deliberate 600 ms delay made the race real, and then the test failed
  **even unsabotaged** — exposing a genuine defect: `waitForNetworkIdle()` subscribed to network
  events *inside itself*, so an action that dispatches its request first (a click, a submit) had
  its `request` event missed entirely, the page looked idle, and the wait returned at once. Silent,
  and wrong in the dangerous direction. Fixed at the source: `Page` subscribes at construction, so
  no caller can get the ordering wrong. D-084's primitive is stronger for every caller, not just
  this one.
- **Verified.** Three cases against the real app: initial state reads from the snapshot; a click
  waits for the 600 ms round trip and sees `count:1`, `label:incremented`, and `#count` showing 1;
  a component is addressable by name, and an absent one reads null rather than as a failed match.
  Sabotaging the wait now fails the middle test with `0` where `1` was expected. 655 self-hosted
  tests green, 18 fixtures conform.

### D-087: `--profile` and `--dirty` — two Pest surfaces the engine already had the data for

Two of the Pest CLI surfaces `spec/pest-api.md` §7 lists, chosen because neither needed new
machinery — only a decision about what to show and which reference to diff against. The gap that
remains after them is architecture testing (§5), which is a feature rather than a slice.

- **`--profile` measures nothing new.** `TestFinished` already carries the wall-clock duration the
  scheduler uses for duration ordering (D-021) and D-082's fastest-first covering runner, so the
  reporter is a pure event-stream listener like every other one: collect, sort, print the slowest
  ten. It reports the **share of total runtime** beside each duration, because a bare `2.0s` is
  not actionable until you know whether it is half the suite or a rounding error — on Crucible's own
  Impact tests it showed four git-shelling tests taking 99% of the run. Retries count as they ran:
  a test that passed on its third attempt did cost three attempts, and hiding that would flatter
  exactly the tests worth looking at.
- **`--dirty` is `--changed` against `HEAD`.** `ChangedFiles::fromGit()` already reports staged,
  unstaged **and** untracked files — untracked because a brand-new test must be selected rather
  than be invisible — which is precisely what "uncommitted" means. So the flag resolves to a
  reference and everything downstream (the impact router, the declared rules of D-083, the Vitest
  tier of D-080) is untouched. Verified on Crucible's own working tree: three modified files narrowed
  91 test files to 1.
- **Named, not silent, on both ends.** `--profile` prints nothing at all for an empty run rather
  than an empty heading, and the git error path now names whichever flag the user actually typed
  instead of always blaming `--changed`. Both flags refuse a value: `--profile=10` would read like
  a limit, so it is an error rather than a silently ignored argument.
- **Verified.** Four reporter cases (slowest-first ordering, the percentage share, the limit, the
  empty run) and two CLI cases. Sabotage-checked: removing the sort fails two of them, naming the
  tests that were no longer listed. 661 self-hosted tests green, 18 fixtures conform.

### D-088: `arch()` — architecture rules, targeting first

The one genuinely absent Pest surface (spec §5), and the only remaining item that is net-new
capability rather than PHPUnit parity. Built from the spec — which was written from public
documentation — and from machinery that already existed. Pest's own source sits in the tree for
consulting its docs; the preset classes were **not opened**, because reading them to decide what
to build would end the clean-room claim this project is built on.

- **Targeting is the load-bearing part, so it was built first.** The tempting order is the easy
  expectations — `toBeFinal()` is one Reflection call — but they are also the least useful, and
  building them first would fix a targeting layer around trivial cases. `expect()` takes namespaces
  or exact names, `*` matches within a namespace segment and `**` across segments, a pattern with
  no wildcard is a prefix, and `ignoring()` subtracts. Every expectation then reads one resolved
  set.
- **The universe is the configured source, not the project.** `->source(include: [...])` already
  states which directories hold the code you own — coverage reads it — so vendor and tests are
  outside a rule by construction rather than by every rule remembering to exclude them.
  `ClassLocator` names what a file declares and `ReferenceScanner` names what it references; both
  already existed for other reasons, and both are memoized because a rule suite asks the same
  questions repeatedly.
- **Dependency rules are scoped to your own code, deliberately.** `toOnlyUse()` ignores references
  that leave the configured source. The first run against Crucible's own tree reported
  `ReferenceScanner uses PhpToken` — correct, and useless: counting the standard library would
  force every rule to carry a whitelist of `Closure` and `ReflectionClass` before it said anything.
  `toUseNothing()` was already scoped that way, so the two now agree. `toOnlyBeUsedIn()` is the
  inverse rule, and the one that catches a boundary crossed from the far side, where the offending
  file is not the file the rule is about.
- **A rule that matches nothing fails.** The worst outcome available is a renamed namespace leaving
  a green test that enforces nothing, so an empty target set is an assertion failure, as is a rule
  with no expectation. That guard immediately earned itself: `arch()` resolved its universe when the
  rule was *built*, during collection, while the run configures the ambient afterwards — so every
  rule got an empty universe. The guard turned a silent false pass into a loud failure, and the
  universe is now resolved when the rule runs.
- **Proven against Crucible's own source, where the answers are independent of the implementation.**
  Nine tests assert real facts — the Impact tier does not reach the browser tier, the retrigger
  endpoint stays inside the Watch tier, `src/` declares strict types — and four of them assert
  *failures*, including the two guards. Two test expectations were wrong on the first run and the
  rules were right, which is the evidence worth having. `tests/unit/Architecture/rules.pest.php`
  then states three of those rules in the dialect itself, so Crucible's architecture is now guarded by
  Crucible. 673 self-hosted tests green, 18 fixtures conform.
- **What is not built.** Presets (`->preset()->php()` and friends) are named bundles of
  expectations, so they cost nothing once the set is wider — and they cannot be honestly designed
  from a spec summary. The rest of §5's expectation families are additive against a targeting layer
  that now exists.

### D-089: CI, `HELP.md`, and a test that keeps them honest

Three gaps that are not features: the gate ran only on the author's machine, two options
shipped undocumented, and there was no user-facing reference for any of the 74 the parser
accepts.

- **The drift was mine, and reviewing harder is not a mechanism.** `--profile` and `--dirty`
  shipped in D-087 with tests and a decision record, and were absent from `--help` — as were
  `--browser` and `--debug`, undocumented since they landed. So the fix is a test:
  `DocumentedOptionsTest` reads the option names out of the parser and fails when one is
  missing from `--help` or from `HELP.md`, **and** fails the other way when the manual names
  an option the parser would reject, which is the direction that rots quietly. Writing it
  immediately found a hole in itself — the parser has four sources of option names, not the
  two constants I first read, so the test briefly accused the manual of inventing
  `--testsuite`. `--worker` is the single deliberate omission, named as such in both places.
- **`HELP.md` is derived, not recalled.** Every option grouped by what you are trying to do —
  select, run, report, cover, set the exit policy — rather than alphabetically, because the
  alphabet is not a question anyone has. The footer of `--help` also still pointed at
  `ROADMAP.md`, deleted earlier the same day.
- **CI, with the failure mode that matters designed out.** Three jobs: the gate
  (`composer check`), conformance against real `phpunit` and `mockery` installs, and the
  browser tier. PHP comes from `shivammathur/setup-php`: not GitHub's, not this project's,
  and running with full access to the runner — but the ecosystem's de-facto standard, since
  GitHub ships no first-party PHP action, and what Pest, Laravel and Symfony all depend on.
  Refusing it would mean hand-rolling PHP installation for a marginal reduction in trust
  surface, when the runner image, Composer and every downloaded package are equally outside
  this project's control. The mitigation that matters is applied instead: **every action is
  pinned to a commit SHA, never a tag**, since a tag can be moved and a SHA cannot — the same
  hardening Pest applies. The workflow says all of this in a header comment rather than
  leaving a reader to infer it, and states the condition under which the trade stops holding:
  this repository has no secrets and read-only permissions, so a compromised action poisons a
  build result rather than exfiltrating anything, and that ceases to be true the day a publish
  step with a token is added. The last one is the interesting one. Browser, Inertia and Livewire tests
  **skip themselves** when their oracle is absent, which is right locally and catastrophic in
  CI: the job would go green having proved nothing. So that job builds all three oracles —
  Playwright, the Inertia bundle, and a real Laravel + Livewire app — and then **asserts that
  nothing skipped**, failing loudly if it did.
- **The guard was verified in both directions, because the first version was wrong.** It
  originally grepped for a `test:skip` *event*; there is no such event — a skip is an
  `outcome` on `test:finish`, so the check would have matched nothing and passed forever,
  which is precisely the failure it exists to prevent. Corrected and then proven: with the
  oracles present the run reports **0** skips, and with one hidden it reports **30**. 676
  self-hosted tests green, 18 fixtures conform.

### D-090: Documentation that is executed, not reviewed

A framework with 706 tests and no way for anyone else to use it. `HELP.md` (D-089) covered
the command line; this covers the rest — a manual, a README written for someone who has
never seen the project, and an `examples/` directory that is **registered as a test suite**.

- **The examples are the documentation, and they run.** `crucible.php` gains an `examples` test
  suite, so every sample in `MANUAL.md` is executed on every run. This is the same reflex as
  D-089's option test and D-083's decision to execute the shipped `.mjs` rather than a copy
  of it: a document checked only by a human at review time drifts silently, and the fix is
  never "review more carefully".
- **It caught a wrong example within a minute of existing.** The higher-order expectation
  sample read `expect($user)->name->toBe('Ada')->roles->toHaveCount(2)`, which is wrong —
  naming a key narrows the expectation *to that value*, so there is no `roles` on the string
  `'Ada'`. As prose in a manual that would have shipped, been copied, and wasted a reader's
  afternoon. As a test it failed immediately. The corrected form, with `->and()`, is in both
  the example and the manual, and the manual says why it is easy to get wrong.
- **Examples are excluded from Pint, deliberately.** The style rules that govern Crucible's own
  source produce `\arch()` and `\ceil()` — correct there, and misleading in a document,
  because nobody writes that in their own suite. Documentation that shows a reader something
  they would never type is documentation that lies a little.
- **`MANUAL.md` is one sectioned file** rather than a `docs/` tree: one file to keep
  consistent, greppable, and with no navigation scaffolding to maintain before there is a
  site to put it on. It documents the migration path in four steps, and is explicit that step
  four — removing PHPUnit — is what turns the compatibility aliases on.
- **The README now opens with how to install and what it looks like**, not with a status
  banner. Its stale claims went with it: 17 conformance fixtures (18), and a test count from
  several hundred tests ago. 706 self-hosted tests green, 18 fixtures conform.

### D-091: `RunModel` — one shared classification, five independent reimplementations retired

Investigating whether to adopt a different personal project's `Document`/`Block`-AST report
pattern (evaluated and mostly declined — see below) surfaced that `src/Reporting/`'s 7
reporters had drifted further than `improvements.md`'s original PdfWriter-only finding
described: the file-grouping map (`$sections[$event->test->file][] = $event`, copy-pasted
docblock included) was independently reimplemented in four reporters, and the blocked/problem
classification (`blocked → untested; !Passed → problem`) plus its by-reason grouping loop in
three more — and, more importantly, **"is `Risky` a problem?" was answered six different ways
by six reporters**, with no documentation anywhere explaining the disagreement. One of those
six *is* a deliberate, documented decision — D-018's JUnit granularity (`risky` as a plain
pass, `incomplete` as skipped, matching the spec's own report) — and stays exactly as it was.
The other five had no such rationale: organic drift, not a decision.

`src/Reporting/RunModel.php` is the fix: a small `readonly` value object, built once per
`RunFinished` from the flat list of finished tests, computing `$sections` (by file),
`$problems`/`$untested` (the canonical rule), and `$untestedByReason` — the four groupings
that were genuinely duplicated. `ConsoleReporter`, `MarkdownWriter`, `JUnitXmlWriter`,
`TestDoxReporter`, and `PdfWriter` now build one and read from it instead of recomputing their
own; `RunSummary` (already a shared, centralized value object on `RunFinished`) was never
part of the problem, so `RunModel` doesn't touch outcome *counts* at all, only the event
*lists* `RunSummary` doesn't carry.

**Deliberately not touched, and why:**
- `TeamCityReporter` — its `Failed/Errored → testFailed`, everything-else-non-passed →
  `testIgnored` split has no blocked/untested distinction at all; it was never actually
  duplicating the other reporters' logic, just superficially resembling it.
- `JUnitXmlWriter`'s own outcome `switch` (the D-018 mapping) — kept exactly as-is; only its
  file-grouping moved to `RunModel`.
- `TestDoxReporter`'s narrower Failed/Errored-only Problems rule — kept as an explicit,
  commented override on top of `RunModel`'s canonical classification, not unified away.
- `ProfileReporter` — flat name+duration timing data, no file or outcome tracking of any
  kind; nothing here overlapped with what `RunModel` computes.

**One real bug fixed as a side effect**: `MarkdownWriter` was the only reporter silently
missing the `Deprecations: N, Notices: N, Warnings: N.` line that Console and TestDox both
show when `$summary->hasIssues()` — an inconsistency with no rationale behind it either. Added.

**Addendum, same day: the `Document`/`Block` content AST, implemented after all.** A
`Document`/`Block` model with per-format renderers was evaluated against a similar pattern in
a different project (lrv) and initially scoped out of this entry — its concrete block catalog
there (7 block types, 4 inline runs: Heading/Paragraph/Table/BulletList/KeyValue/Rule/
PageBreak) has no color/severity/badge concept at all and a text-only, per-column-alignment-
only `Table`, so it doesn't reach Crucible's verdict badge, proportion bar, file-tile grid, or
folding directory tree without genuinely new block types — not a port. The user chose to build
it anyway, accepting real behavior changes across all four human-readable reporters to get a
genuinely unified content model, not just deduplicated classification.

`src/Reporting/Document/` now holds: `Block`/`Run` (pure marker interfaces — no `render()`
method, no `toArray()`; a `Document` is just `list<Block>`), inline runs (`Text`, `Code`,
`Strong`), and block types built fresh for this content — none exist in lrv's catalog:
`Heading`, `Paragraph`, `BulletList` (the generic three), `Badge` (the verdict, carrying a
semantic `Tone` enum — Success/Caution/Danger/Muted — never a literal color, so each renderer
maps it to its own visual), `ProblemList` (a numbered list of `ProblemEntry`, optionally
toned per group for Pdf's severity subheadings), `ProportionBar` (the passed/skipped/flagged/
failed share strip), `RankedList` (the slowest-tests outlier list), and `FoldingTree`/`TileGrid` (the passed-directory tree with sub-threshold folding, ported
verbatim from `PdfWriter`'s old `forest()`/`branch()`/`tiles()`/`height()` — now
`FoldingNode`'s own methods, since none of it needed a PDF primitive to compute, only to
draw). Renderer dispatch mirrors lrv's own idiom exactly: `match (true) { $block instanceof
X => ..., default => throw }` — one method per type, and forgetting an arm is a loud,
immediate failure in this project's own self-hosted suite, not a silent gap (`ReportersTest.php`
exercises every block type through real reporter output).

`src/Reporting/PdfWriter.php` split the same way lrv splits `PdfWriter`/`PdfRenderer`: the low-
level PDF mechanics (fonts, WinAnsi encoding, geometry, xref/trailer) moved verbatim into a new
`src/Reporting/Document/PdfPrimitives.php`; `src/Reporting/Document/Renderers/PdfRenderer.php`
composes it and walks the `Document`; `PdfWriter` itself is now a thin `Listener` and content-
builder, the same shape `MarkdownWriter` already had from this entry's first pass.
`MarkdownWriter`, `TestDoxReporter`, and `ConsoleReporter`'s summary half got the same
treatment, each with its own renderer and its own judgment about what belongs in its
`Document` — there is no single shared "build the document" function, matching how lrv's own
content producers each build their own tree.

**Two reporters deliberately stayed outside this model, unchanged in structure**:
`TeamCityReporter` (a live protocol pass-through, no `RunFinished` handling, nothing to build)
and `JUnitXmlWriter` (schema-mandated XML attributes aggregated bottom-up during traversal — a
different shape than static content blocks; routing it through a generic renderer would add
indirection without removing any bespoke logic). `TestDoxReporter`'s distinctive per-file,
per-test listing also stayed hand-written deliberately — it's an audit trail (every test, its
own mark), not an aggregate, and doesn't fit `FoldingTree`/`TileGrid` any better than lrv's
own blocks fit Crucible's content; only its Problems section converts.

**Real behavior changes, accepted, each for a stated reason:**
- **The verdict badge is now the same 3-way OK/FLAKY/FAILED rule everywhere** —
  `Badge::forRun()`, one canonical decision. `MarkdownWriter` previously had no flaky
  tracking at all and could only ever show 2-way OK/Not-OK; this was a real gap, not a
  preserved feature.
- **Duration formatting is now plain `%.3fs` everywhere in Pdf**, dropping the adaptive
  µs/ms/s scale it used to have to itself. Keeping a Pdf-only nicety the other three never had
  would itself have been the inconsistency this work closes. One measurable cost: a very fast
  folded-tail summary that used to read "3µs" now rounds to "0.000s" — a real precision loss
  in one edge case, traded for one consistent format everywhere else. (`RankedList`'s own
  per-list adaptive ms/s scale stayed — picking one unit for a list of comparable entries so
  they align is a self-contained, still-justified choice, not a leftover inconsistency.)
- **`ConsoleReporter` gains a Passed section it never had** (a plain-text `FoldingTree`/
  `TileGrid` walk, no color — its `progress()` half is untouched, still fully live).
  `TestDoxReporter` and `PdfWriter`'s Passed sections restructured onto the same shared
  `FoldingTree` directory grouping; `MarkdownWriter`'s "## Results" now walks that same
  `FoldingTree` too, but its own renderer still emits a full per-test table under each file
  heading — the directory folding/grouping is new and shared, the per-test row detail
  Markdown always had is still there. (An earlier pass of this port had `MarkdownRenderer`
  degrade `FoldingTree`'s leaves to `TileGrid`'s aggregate-only per-file counts, the same
  compact form Pdf uses — a real capability loss that was never actually approved. Caught and
  fixed same day: `MarkdownRenderer::node()` renders its own per-test table instead of
  delegating to `TileGrid`, so Markdown gained directory grouping without losing anything it
  had before.)
- **Pdf's Problems entries dropped their own prettified format** in favor of the plain
  `file::test [outcome]` form Console/Markdown/TestDox already used — one less bespoke format
  to keep in sync.

**One real bug the rewrite caught, not introduced**: the "already on the Slowest list, don't
repeat the tile's time" suppression (`PdfRenderer`'s spotlight set) was silently never firing
in the original code path this replaced — inspecting real rendered output during the port
showed a spotlighted file's tile still carrying its duration. Traced to a key mismatch once
the concept became explicit data (`RankedEntry`/`TileEntry` both needed to carry the *raw*
file path, not a prettified display string, for the two to compare equal) — the kind of bug
that's easy to introduce by accident and just as easy to miss by eye, caught here specifically
*because* separating content from rendering made the mismatch visible as a data-shape question
instead of two blocks of interleaved formatting code that happened to agree by inspection.

`RunModel` (the base of this entry) remains the deduplication and correctness win on its own;
this addendum is the second, larger layer built on top of it the same day.

**Follow-up, same day: `MarkdownRenderer` reaches 9/9 block types.** `ProportionBar` and
`RankedList` were initially left Pdf-only above — not because either lost anything in the
port (both were always Pdf-exclusive; nothing pre-dates this redesign for either in Markdown),
but because a colored bar and a ranked outlier list read as visual conveniences a plain-text
format might not need. Revisited and added: `ProportionBar` renders as a plain percentage line
(`Passed 50% · Skipped 25% · Failed 25%`, `Tone` mapped to a text label instead of a color),
and `RankedList` as a numbered list matching its Pdf ordering and selection floor exactly. Both
are genuinely new Markdown content, not restorations — `ReportersTest.php`'s Markdown case
covers both.

### D-092: `crucible compat-check` — diagnosing and fixing PHPUnit-internals-coupled tests

`src/Compat/Migration/`. A real-world PHPUnit-compat audit (a full Monolog 3.9.0 run through
`->phpunitCompatibility()`) found and fixed genuine Crucible bugs, then hit a different kind of
gap: 5 remaining failures that were not Crucible bugs at all — tests asserting PHPUnit's own
internal implementation (its vendor file layout, its internal dispatch-method name) rather than
the code under test. Migrating between test runners hits this kind of test occasionally, even
between PHPUnit versions; `compat-check` productizes the manual diagnose-and-fix workflow that
audit used, for any project's own real suite, not just this one benchmark.

**Mechanism**: `crucible compat-check` runs the project's real, installed `phpunit/phpunit` (the
oracle, `PhpUnitCompatibility::phpUnitIsInstalled()` gates the whole command — D-019's own
detection, reused) and Crucible itself as two subprocesses, each producing outcomes keyed by
**relative file path + method** (`OracleRun` parses JUnit's `file` attribute; `CrucibleRun`
reads Crucible's own NDJSON `id` field verbatim — the two are already the same shape by
construction, no translation needed). `OutcomeDiff` flags any test the oracle passes that
Crucible fails — the signature of internals-coupling, as opposed to a genuine Crucible bug
(which shows up as errors across many unrelated tests, not one drifted assertion). Every
drifted test is diagnosed with a message explaining why; `--auto-fix` additionally rewrites
anything matching a small, explicit, individually-oracle-verified table
(`KnownCouplingPatterns`); the interactive default asks before writing. `--revert` restores
every touched file from a full-content backup (`FixManifest`, JSON at
`<cacheDirectory>/compat-fixes.json`) — no revert mechanism existed anywhere in Crucible before
this.

**The rewrite engine is token-based** (`SourceRewriter`, `PhpToken::tokenize()`), not an AST
library — Rector is a dev-only dependency of Crucible itself, not available to a consuming
project's own runtime, so the same constraint `InlineSnapshotWriter` (D-076) already solved
applies here too, and the same locate-then-splice technique is reused.

**Two real bugs, found only by running this against the real Monolog benchmark — invisible to
unit tests built on synthetic fixtures, because the fixtures encoded the same wrong assumption
the implementation held**:
1. `OracleRun` first keyed outcomes by FQCN (matching `conformance/run.php`'s own convention,
   D-018 — safe there only because every conformance fixture is a single class). Crucible's own
   NDJSON `id` actually keys by file path. Both sides reported 1153 outcomes and **zero**
   detected drift — a silent, total mismatch, not a crash — because nothing was comparing what
   it thought it was comparing.
2. The vendor-path auto-fix pattern initially matched any occurrence of `vendor/phpunit/phpunit`
   regardless of which stack frame it appeared in. Only frame 0 (the immediate call site) is
   structurally guaranteed to be some `TestCase.php` in every engine — real PHPUnit's frame 1 is
   *also* `TestCase.php` (its own internal quirk, two consecutive `TestCase.php` frames), but
   Crucible's frame 1 is `TestBuilder.php`, a different file entirely. The pattern "fixed" a
   second occurrence into a regex that looked right and was not — caught by re-running the full
   benchmark after applying the fix, not by inspection. Fixed by widening
   `CouplingPattern::matchesLiteral` to see the call's other arguments too, and adding two more
   patterns, ordered before the general one, that weaken correctly for a non-immediate frame
   (keep a verified `#N ` marker if the literal carries one, drop to `assertIsString()` if it
   doesn't) instead of either guessing or leaving the case unfixed.

**A third, structurally different shape — closed as a second, separate detector, not folded
into the first**: `assertEquals($expected, $actual)` where `$expected` was built several
statements earlier via `$expected['someKey'] = [ 'a' => 1, 'class' => 'PHPUnit\Framework\TestCase', ... ]`
— the coupling is nested inside an array *value*, not in the comparison call's own arguments, so
no single-call literal matcher can reach it. `CompositeEqualsPattern` requires both comparison
arguments to be bare variables, searches backward for the exact `$var['literalKey'] = [
...short-array-syntax... ]` shape, classifies each array entry (a value matching the same
coupled-literal check is dropped; everything else — including `null`, numbers, and strings that
don't match — survives as a direct `assertSame()` on the real value), and replaces only the
comparison call's own text span, the same splice `SourceRewriter` already uses. **Deliberately
never deletes** the original array-literal assignment: it becomes harmless dead code, not a
correctness risk, since deleting statements risks a dangling reference if the variable is used
elsewhere — reading one never does. Scoped exactly to the one real, verified case (a 3-argument
call, `array(...)` long syntax, a non-bare-variable argument, or no backward assignment at all
all decline the pattern rather than guess), same discipline as the rest of the table.

**Verified end to end, not just unit-tested**: after both fixes and the composite pattern,
`crucible compat-check --auto-fix` closes all 5 originally-drifting Monolog tests with zero
manual intervention, and `crucible compat-check` alone reports **"No drift: every test the real
PHPUnit oracle passes, Crucible also passes."** — 1153/1153 on both engines, the tool confirming
its own success rather than a hand count. `--revert` restores every touched file to
byte-identical original content, `diff`-verified.

### D-093: `Pest\Laravel\*` / `Pest\Livewire\livewire()` — the plugin-surface gap, closed as proxies

The same real-world audit that drove D-092 (a full `spatie/laravel-data` run through the pest
dialect) found a second kind of gap, not a Crucible bug at all: 67 of 1054 tests errored on
`Call to undefined function Pest\Laravel\*`/`Pest\Livewire\livewire()` — the real
`pestphp/pest-plugin-laravel`/`pestphp/pest-plugin-livewire`/`spatie/pest-plugin-snapshots`
simply aren't installable alongside Crucible's pest dialect (each hard-requires `pestphp/pest`
itself, which `PestBuilder::ensureUsable()`'s D-019 guard already refuses to coexist with), so
their functions were never defined at all.

**Reading the real plugin packages' own source (installed in a scratch project, not guessed)
found almost the entire surface is a one-line proxy** — `return test()->methodName(...func_get_args());`
— where real Pest's zero-argument `test()` returns the currently-running instance, and the
underlying methods (`mock`, `assertDatabaseHas`, `actingAs`, `postJson`, `handleExceptions`, …)
are native to `Illuminate\Foundation\Testing\TestCase` (via its own bundled `Concerns\*` traits),
which Orchestra Testbench's `TestCase` extends. `PestBuilder` already instantiates and
lifecycle-manages real Testbench instances for `uses()`-bound suites, so nothing new was needed
except a way to reach "the instance currently running a test" — `CurrentTest`
(`src/Dialect/Pest/CurrentTest.php`), a tiny `set()`/`get()` holder scoped by `PestBuilder` around
each test body (set unconditionally before `setUp()`, cleared last in the `finally` block, after
`afterEach`/`tearDown` have had their own chance to still reach it). Deliberately not reused as
`test()`'s own zero-argument form (`src/Dialect/Pest/functions.php`'s `test(string $description, …)`
keeps its existing non-nullable signature) — growing an already-shipped, high-traffic dialect
function's meaning is a materially different risk than adding a new, inert-until-called one.

**`Pest\Livewire\livewire()` needed no such lookup at all**: its real implementation
(`InteractsWithLivewire::livewire()`) doesn't read `$this` — it's
`return Livewire\Livewire::test($name, $params);`, a direct static facade call, verified against
the real trait source.

**Scoped to what the real benchmark exercises, not the plugins' full surface**: `mock`,
`partialMock`, `spy`, `instance`, `swap`, `actingAs`, `assertDatabaseHas`, `postJson`,
`handleExceptions`, `withoutExceptionHandling` (`src/Bridge/PestLaravel/functions.php`) and
`livewire` (`src/Bridge/PestLivewire/functions.php`) — every signature pinned from the real
packages' own source, not reconstructed from memory. Loaded from `PestBuilder::ensureUsable()`
unconditionally once the existing D-019 guard has already passed, each function
`function_exists`-guarded like `functions.php`'s own convention; growing the list further is
adding one more proxy line, not a design decision. `Spatie\Snapshots\*` (the remaining 13 of 67)
was scoped out of this pass — its methods come from a trait real Pest mixes into the base TestCase
via its own `Plugin::uses()`, dynamically, at file-load time, which looked like it needed trait
composition Crucible's `uses()` didn't have. It turned out to already exist
(`TraitComposer::compose()`, built for `pest()->use()`) — see D-094, landed the same session once
that was noticed.

**One design fight, not a Crucible bug**: `scanDirectories`/`scanFiles` cannot register a path's
function symbols for phpstan reflection while that same path is also `excludePaths`'d — tried
both (PHPStan 2.2.6), calling code still reported `function.notFound`. Since these proxy files
compile against `illuminate/*`/`livewire/*`/`mockery/*` classes that are deliberately not
dev-dependencies of the engine (same reasoning as `src/Bridge/Laravel`, which has zero unit tests
for exactly this reason), the one test file that calls them directly
(`tests/unit/Bridge/PestLaravel/FunctionsTest.php`) is excluded from static analysis too, matching
that precedent — verified by execution against the real benchmark instead.

**Verified against the real benchmark, not just a unit-test fixture — and it caught a real bug
unit tests missed**: `spatie/laravel-data`'s own suite went from 973/1054 passing (67 errors, 2
failures) to 1029/1054 (13 errors, 0 failures) — every remaining error is `Spatie\Snapshots\*`,
exactly the deferred scope, nothing else. Getting there took two more passes, both found only by
re-running the real suite:

1. **Visibility.** Most of the underlying `Illuminate\Foundation\Testing\Concerns\*` methods are
   declared `protected` (confirmed by reading the real, installed traits — `assertDatabaseHas`,
   `mock`/`partialMock`/`spy`/`swap`, `handleExceptions`, `withoutExceptionHandling` all are), so a
   plain `$instance->method()` call from a global-namespace function fatals with "Call to
   protected method ... from global scope." Real Pest's own plugin proxies never hit this because
   `test()`'s return value is reflection-dispatched (`Pest\Support\HigherOrderMessage::call()`),
   not called directly — `CurrentTest::call()` mirrors that (`ReflectionMethod::invoke()`,
   bypassing visibility uniformly, even for the couple of methods — `actingAs`, `postJson` — that
   happen to already be public). This closed 51 of the remaining errors in one pass (67 → 16 → 0
   real "undefined function" cases); the unit-test fixture hadn't caught it because every fixture
   method was, at the time, `public` — fixed by making the fixture's visibility mirror the real
   traits exactly, so the regression is now caught locally too.
2. **A masked function.** `WrapTest.php` calls `Pest\Laravel\post()` in the same test as
   `postJson()` — PHP's fatal on the first undefined function stopped execution before the second
   one ever ran, so `post()` didn't surface as its own error until `postJson` was already fixed.
   Added as one more proxy line, same shape as the rest.

### D-094: `Spatie\Snapshots\*` — the trait D-093 assumed needed new architecture already existed

D-093 scoped `Spatie\Snapshots\*` out, reasoning that real Pest mixes `MatchesSnapshots` into the
base TestCase via its own `Plugin::uses()`, dynamically, and Crucible's `uses()` had no equivalent.
That assumption was wrong: `TraitComposer::compose()` (`src/Dialect/Pest/TraitComposer.php`,
built for `pest()->use(Trait::class)`) already generates a real subclass mixing arbitrary traits
into a base class, `eval()`-once and cached — exactly the mechanism needed, just never pointed at
this specific case. Once noticed, this was proxy-layer work again, not new architecture, same
session.

**Two pieces, mirroring D-093's own shape**:

1. **Auto-mixing.** Real Pest's `spatie/pest-plugin-snapshots` calls
   `Plugin::uses(MatchesSnapshots::class)` unconditionally at its own file-load time — every
   `uses()`-bound test gets the trait whether it asks for it or not. That plugin package can never
   be installed alongside Crucible's dialect (same D-019 reasoning as D-093: it hard-requires
   `pestphp/pest`), but the trait's own package, `spatie/phpunit-snapshot-assertions`, has no such
   dependency and is genuinely standalone. `AutoMixedTrait::forClass()`
   (`src/Bridge/PestSnapshots/AutoMixedTrait.php`) mirrors the auto-mixing: `PestBuilder::build()`
   appends its result to `$state->traits` before calling `TraitComposer::compose()`, skipping a
   `final` `uses()` class (composing there would throw for every such class, not just ones that
   call a snapshot function — `TraitComposer`'s own guard). Kept as its own class rather than
   inline in `PestBuilder.php` for a phpstan-mechanical reason: `excludePaths` and
   `scanDirectories`/`scanFiles` don't compose (D-093's own finding) for *functions*, but classes
   resolve through Composer's autoloader independent of `excludePaths` — so an excluded *class*,
   unlike an excluded *function*, can still be referenced with full typing from non-excluded code.
   `PestBuilder.php` itself needed no exclusion at all.
2. **Proxies.** `assertMatchesSnapshot`, `assertMatchesJsonSnapshot`, and the rest of the trivial,
   same-shape family (`src/Bridge/PestSnapshots/functions.php`) — routed through `CurrentTest::call()`
   (D-093's reflection-dispatch helper) uniformly, though most of these particular methods are
   public on the real trait. The `expect()->extend()`-based matchers (`toMatchSnapshot` etc.) are a
   separate feature and stayed out of scope, matching D-093's own "scoped to what's exercised"
   discipline.

**A second real bug the real benchmark caught, unit tests couldn't**: the first pass checked
`class_exists(MatchesSnapshots::class)` — silently `false`, always, because `MatchesSnapshots` is
declared `trait`, and `class_exists()` does not match traits (`trait_exists()` does). Every
`Spatie\Snapshots\*` call failed with `Method ...TestCase::assertMatchesSnapshot() does not
exist` — the auto-mixing had silently never fired. Invisible to a unit test built against this
suite's own environment (the trait doesn't exist there either way, so both functions return
`false`) — caught only by reinstalling the real `spatie/phpunit-snapshot-assertions` and re-running
the actual benchmark, the same "verify against ground truth" discipline D-093's visibility bug was
caught by.

**First verification was a false positive — caught by a follow-up task, not by inspection.**
The initial run reported 1042/1054, 0 errors, 0 failures, and was written up as complete closure.
It wasn't: every `Spatie\Snapshots\*` call was silently creating a *fresh* snapshot and comparing
it against itself, never against `spatie/laravel-data`'s real, git-committed fixtures — and, worse,
briefly wrote real files into `src/Dialect/Pest/__snapshots__/`, inside this repository, as a side
effect of running an unrelated scratch test. Root cause: `TraitComposer::compose()` generates
classes via `eval()` (D-017's own established pattern), and any `eval()`'d class reports its
*calling* file — `TraitComposer.php`'s own location — via `ReflectionClass::getFileName()`, not the
test's. `Spatie\Snapshots\Concerns\SnapshotDirectoryAware::getSnapshotDirectory()` derives the
snapshot directory from exactly that reflection call, and `SnapshotIdAware::getSnapshotId()`
similarly derives the snapshot's own filename from `getShortName()` — meaningless for a
hash-named, `eval()`-generated class.

Confirmed this is not a Crucible-specific defect: real Pest's own `TestCaseFactory` also `eval()`s
its generated classes and hits the identical `getFileName()` result
(`.../TestCaseFactory.php(N) : eval()'d code`, verified directly). Real Pest's actual fix is that
`spatie/pest-plugin-snapshots` ships its own same-FQCN override of both `Concerns` traits, using
`Pest\TestSuite::getInstance()->rootPath` and the test file's own basename instead of reflection —
a package that can never be installed alongside Crucible's dialect (same D-019 reasoning as
everything else in this entry). `SnapshotFileContext` + `SnapshotIdentityWrapper`
(`src/Bridge/PestSnapshots/`) reproduce the same fix by a different mechanism: a small static
context `PestBuilder` populates per test (mirroring `CurrentTest`'s own established pattern) with
the project-root-relative `tests/__snapshots__` directory and the test file's own basename (up to
the first dot, matching real Pest's own `TestCaseFactory::evaluate()` exactly) — read by a second,
minimal `eval()`'d wrapper class whose two directly-declared methods override the trait-provided
ones outright (no `insteadof` conflict resolution needed, since a class's own method already wins
over a trait's).

**Closing this properly surfaced three more real, previously-undiscovered engine gaps along the
way, none Snapshots-specific**:

1. `MetadataParser` only recognized Crucible's own `#[Before]`/`#[PreCondition]`/`#[PostCondition]`/
   `#[After]` attribute classes, "by design" per its own docblock — never real PHPUnit's, even when
   phpunit/phpunit is genuinely installed (no aliasing then). `MatchesSnapshots`'s real
   `#[PHPUnit\Framework\Attributes\PostCondition]`-attributed method was silently never discovered.
   Fixed narrowly (`RealPhpUnitHookAttributes`, translating the four real attribute classes into
   Crucible's own equivalents, so every downstream consumer keeps matching on its existing
   `CrucibleAttribute` types unaware either source exists) rather than broadening the parser's
   documented contract.
2. Even with attributes recognized, the Pest dialect never invoked hooks at all —
   `PestBuilder::definition()` never called the shared `HookPlan`/`invokeTest()` machinery the
   PHPUnit dialect already used. `HookPlanner` (`src/Framework/`) extracts what was a private method
   on `TestBuilder` into shared code both dialects now call, rather than duplicating the scan.
3. `TestRunner`'s outcome classification (`instanceof SkippedTestError`/`IncompleteTestError`,
   Crucible's own only) didn't recognize real PHPUnit's `SkippedWithMessageException`/
   `IncompleteTestError` either — a real `markTestIncomplete()` call, from *any* real
   PHPUnit-descended `uses()` class, was landing as `Errored`. Fixed via `RealPhpUnitOutcomes`,
   checking real PHPUnit's own marker interfaces (`SkippedTest`/`IncompleteTest`, both
   `extends Throwable`) — the same interfaces real PHPUnit's own code checks against.

A fourth issue was a genuine collision, not a gap: Crucible's own `Assert` class already declares a
*static* `assertMatchesSnapshot()` (this same D-076 feature, unrelated), and mixing in a trait whose
method of the same name is an *instance* method fatals — "Cannot make static method ... non static"
— for any `uses()` class descended from Crucible's own `TestCase`/`Assert` hierarchy (not
`spatie/laravel-data`'s own, which is real-PHPUnit-descended and unaffected).
`AutoMixedTrait::forClass()` now declines to mix in the trait at all when the target class already
declares any of `MatchesSnapshots`'s own method names, and `dispatch()`
(`src/Bridge/PestSnapshots/functions.php`) checks the resolved instance's full class ancestry before
forwarding, so the failure mode for that narrower case is a clear, actionable error instead of
either a fatal or a silent call into the wrong, differently-behaved method.

**Verified against the real benchmark, this time for real**: `spatie/laravel-data`'s own suite now
reports **1040/1054 passed, 0 errors, 0 failures, 11 skipped, 3 incomplete**. Two of those
incompletes are `SerializeableTest.php`'s virtual/backed-property cases, both genuinely creating a
first-ever snapshot — confirmed byte-for-byte against real Pest itself (a fresh, separately cloned
`spatie/laravel-data`, real `pestphp/pest` + real `spatie/pest-plugin-snapshots` installed, no
Crucible involved at all): identical "2 incomplete," identical untracked `__1.txt` files created.
Not a Crucible discrepancy — the project's own git history has a stale, orphaned `__2.txt`
committed for each (from some earlier version of the test that called `assertMatchesSnapshot()`
twice) but never committed the `__1.txt` the *current* test body actually produces; real Pest
reproduces the exact same "incomplete" outcome on a fresh clone. The remaining 11 skips and 1
incomplete (`CreationTest.php`'s own `TODO`) are the project's own pre-existing, deliberate
exclusions (Inertia v2 support, `v5` TODOs), unrelated to Crucible. Across the whole D-093/D-094
arc, from the plugin-surface audit's starting 973/1054: every one of the 69 originally-identified
gap tests now passes or reproduces real Pest's own verified outcome exactly.

### D-095: comparing *total test counts* against real Pest, not just pass/fail — a 284-test gap

Every prior verification in D-092 through D-094 compared Crucible's own pass/fail/error/skip
tallies against Crucible's own prior runs, or against a real oracle's tallies for the *same*
Crucible-reported total. None of them had ever run the real, unmodified `spatie/laravel-data`
suite through real `pestphp/pest` end to end and diffed the total test *count* — only individual
outcomes, on the assumption the totals already agreed. They didn't: real Pest reported
**1338** tests; Crucible reported **1054**. A 284-test gap, invisible to every "does this test's
outcome match" check, because those 284 tests were never being discovered as tests at all.

**Root cause, isolated to one file**: `tests/Attributes/Validation/RulesTest.php`'s `it('gets the
correct rules', …)->with('attributes')`, where `'attributes'` (`tests/Datasets/RulesDataset.php`)
composes roughly 35 sub-generator functions via `yield from`. Each sub-generator's own `yield`
calls restart PHP's implicit key numbering at 0 independently. `PestScopes::materialize()`
converted the combined generator to an array via plain `$materialized[$key] = $row;` — every
sub-generator after the first silently overwrote the previous one's rows at the same colliding
int-keyed offsets, collapsing ~400 rows down to a handful. Confirmed by reading real Pest's own
`DatasetsRepository::processDatasets()`: `iterator_to_array($generator, preserveKeys:
$generator instanceof Generator && is_string($generator->key()))` — keys are preserved *only*
when the first one is a string (a deliberate, author-chosen case label); implicit int keys are
discarded, and rows are appended sequentially instead. `PestScopes::materialize()` now does the
same, gated on the *first* row's key type alone (matching real Pest's own single up-front check,
not a per-row decision). Verified end to end: the benchmark's total went from 1054 to exactly
**1338**, matching real Pest bit for bit.

**A second, real gap — found, then fixed the same session**: fixing the count surfaced 4 of the
newly-discovered `RulesTest.php` rows erroring for real
(`tests/Attributes/Validation/RulesTest.php:26` calls `$this->expectException($exception)` — real
PHPUnit's own instance-level expectation API, set on the real PHPUnit-descended `uses()` class,
not Crucible's own dialect-level `->throws()` chainable). `PestBuilder` never delegated to real
PHPUnit's own runner and never inspected that state after the test body returned, so when the
expected exception fired, it propagated as a genuine uncaught error instead of being recognized as
"expected, test passes." `RealPhpUnitExceptionExpectations`
(`src/Dialect/Pest/RealPhpUnitExceptionExpectations.php`) reads the real, private
`expectedException`/`expectedExceptionMessage`/`expectedExceptionMessageRegExp`/`expectedExceptionCode`
properties (verified directly against real, installed phpunit/phpunit source — property names and
types, not guessed) and verifies them via Crucible's own `Assert`, mirroring real PHPUnit's own
`TestCase::verifyExceptionExpectations()`/`expectedExceptionWasNotRaised()` exactly: instance-of,
message-contains, message-matches-regex, code-equals, and "expected but never thrown" all fail the
test the same way real PHPUnit would.

**The first fix attempt looked correct and silently didn't work** — caught only by re-running the
real benchmark, not by its own unit tests. Root cause: `ReflectionClass::hasProperty()` (and
`getProperty()`) only recognizes a *private* property at the exact class level that declares it —
confirmed directly with a two-line repro (`class Base { private $x; } class Mid extends Base {}`,
`(new ReflectionClass(Mid::class))->hasProperty('x')` → `false`) before believing it, since it
contradicted the initial assumption that private properties are visible reflection-wise anywhere
in the inheritance chain. `PestBuilder`'s own `uses()` class composition wraps a real
PHPUnit-descended class in one or more `eval()`-generated subclasses
(`TraitComposer::compose()`, `SnapshotIdentityWrapper::wrap()` when `spatie/phpunit-snapshot-assertions`
is installed) — meaning the *running instance*'s own immediate class never directly declares those
4 properties, only some ancestor several levels up does. The original unit tests all passed because
every one of them constructed the duck-typed fixture directly, with zero inheritance — a shape the
real benchmark never actually produces. Fixed by walking up via `getParentClass()`
(`declaringClass()`) to find the actual declaring level, then reading the *instance*'s own value
through that ancestor's `ReflectionProperty` — also verified directly (a `ReflectionProperty`
obtained from an ancestor's `ReflectionClass` correctly reads a descendant instance's value). New
regression coverage added specifically for this shape:
`ComposedFakeRealPhpUnitCase extends FakeRealPhpUnitCase` in
`tests/unit/Dialect/Pest/RealPhpUnitExceptionExpectationsTest.php` — the one test case the first
pass didn't have, and the one that would have caught it.

**Verified against the real benchmark twice — once for the false green, once for the real fix**:
first attempt: benchmark still showed 4 errors, unchanged; unit tests all green regardless (the gap
in coverage, not a gap in the code being tested). Second attempt, after the `declaringClass()` fix:
**`spatie/laravel-data`'s own suite now reports 1338/1338 total — 0 errors, 0 failures — matching
real Pest exactly.** New regression coverage for the dataset-collision half of this entry:
`tests/unit/Dialect/Pest/PestScopesTest.php` (direct `materialize()` proof: a
multi-generator-composed dataset keeps all 5 rows instead of collapsing to 3, plus a
single-generator string-key-preservation case guarding against regressing the collision fix into
also breaking author-chosen labels) and a self-hosted `.pest.php` case
(`tests/unit/Dialect/PestDialectConfig.pest.php`, dataset in `tests/unit/Datasets/Brews.php`)
proving the fix end to end through the real dataset-registration pipeline, not just the isolated
function.

### D-096: broader real-PHPUnit-recognition audit — closing the `instanceof`/`ofType()` blind spot beyond `expectException()`

D-095's `RealPhpUnitExceptionExpectations` fix and the standalone `#[Before]`/`#[PreCondition]`/
`#[PostCondition]`/`#[After]` fix (`RealPhpUnitHookAttributes`) both trace to the same root cause:
in coexistence/migration mode (`phpunit/phpunit` installed, `PhpUnitCompatibility::load()`'s
aliases off by design — D-019), a real, un-aliased `PHPUnit\Framework\*` object is a genuinely
different class from Crucible's own equivalent, so any Crucible-internal `instanceof`/`ofType()`
check against Crucible's own class silently fails to recognize it. A grep for the rest of this
pattern surfaced more candidates than had been checked; this entry closes three of them, each
verified against a real, installed `phpunit/phpunit` first, not assumed from the pattern alone.

**Confirmed bug 1 — assertion failures misclassified as Errors.** A real, un-aliased
`PHPUnit\Framework\TestCase`-descended `uses()` class's inherited `assertSame()` (etc.) throws real
`PHPUnit\Framework\ExpectationFailedException` (extends real `PHPUnit\Framework\AssertionFailedError`).
`TestRunner`'s outcome classification (`$thrown instanceof AssertionFailedError` — Crucible's own
class only) never matched it, so a deliberate `assertSame('a', 'b')` reported `Errors: 1` instead
of `Failed: 1` — confirmed with a direct repro before touching anything. Invisible to both prior
benchmarks (Monolog, `spatie/laravel-data`) purely because neither has a single failing assertion
in its entire suite. Fixed with `RealPhpUnitOutcomes::isFailure()` (mirroring the file's existing
`isSkipped()`/`isIncomplete()`, D-095), OR'd into the one classification site that needed it
(`TestRunner::settleCoverage()`'s `match(true)`). `TestCase::execute()`'s own
`instanceof AssertionFailedError` check (the other site the original grep flagged) was traced and
verified *not* to need the same fix: real-PHPUnit assertion failures already fall through to its
generic `catch (Throwable $throwable)` branch, which calls the same `verifyThrowableMatchesExpectation()`
either way — confirmed with a repro (`expectException(\PHPUnit\Framework\AssertionFailedError::class)`
around a real failing `assertSame()`) passing correctly before any change. Left untouched rather
than "fixed" for symmetry's own sake — the audit's own discipline (verify, don't assume) cuts both
ways.

**Confirmed bug 2 — real PHPUnit's metadata attributes silently no-op.** `MetadataParser::forClass()`
had zero real-attribute recognition; `forMethod()` only recognized the four hook attributes.
Every other attribute Crucible has its own equivalent for — `CoversClass`/`CoversTrait`/`CoversMethod`/
`CoversFunction`, `UsesClass`/`UsesTrait`/`UsesMethod`/`UsesFunction`, `RequiresPhp`/`RequiresPhpExtension`/
`RequiresFunction`/`RequiresMethod`/`RequiresOperatingSystem`/`RequiresOperatingSystemFamily`/
`RequiresSetting`, `Group`, `Depends`/`DependsUsingDeepClone`/`DependsUsingShallowClone`,
`DataProvider`/`DataProviderExternal`, `TestWith`/`TestWithJson`, `ExcludeGlobalVariableFromBackup`/
`ExcludeStaticPropertyFromBackup` — was confirmed unrecognized via a direct probe against
`MetadataParser` using the real, installed attribute classes (not synthetic stand-ins). Fixed with
`RealPhpUnitAttributes` (`src/Metadata/RealPhpUnitAttributes.php`), one generic translator rather
than 20+ hand-written mapping methods: every candidate's constructor has an exact parameter
name/order match with Crucible's own equivalent (verified against real PHPUnit source directly), so
the translator reads `ReflectionAttribute::getArguments()` off the real attribute usage and forwards
it straight into `new $crucibleClass(...$arguments)` — no need to instantiate the real class or
touch its own property/getter shape at all. The one known signature delta
(`DataProvider`/`DataProviderExternal`'s extra trailing `validateArgumentCount`, which Crucible's
own equivalents don't accept) is handled by filtering `$arguments` down to the names/positions the
target constructor actually declares, rather than a per-attribute special case — verified directly
with `#[DataProvider('provider', validateArgumentCount: false)]`. The candidate list is generated
with the same glob-by-basename convention as `PhpUnitCompatibility::aliases()` (`src/Attributes/*.php`),
minus the four hook attributes `RealPhpUnitHookAttributes` already owns (co-running both would fire
every hook twice); the handful of Crucible-only attributes with no real PHPUnit counterpart (`Check`,
`Retry`, `Todo`, …) simply never match a real name, verified safe (`getAttributes()` against a
nonexistent class-string filter just returns `[]`, no autoload, no error). Wired into both
`MetadataParser::forClass()` and `forMethod()` (real PHPUnit allows the class-level attributes in
this list at class level; probing both reflectors for every candidate, rather than hardcoding which
target each one carries, stays correct if a future PHPUnit version's target flags change).

**A third bug, found investigating the first two, not on the original candidate list.**
`RealPhpUnitHookAttributes`'s existing guard — `if (!PhpUnitCompatibility::phpUnitIsInstalled())
return [];` — conflates "the real package is installed" with "aliasing is active," which are the
same thing only in auto/drop-in mode. Explicit opt-in (`->phpunitCompatibility(true)`) with the real
package installed *also* activates aliasing (`PhpUnitCompatibility::load()` runs), but
`phpUnitIsInstalled()` stays `true` either way — so the guard let the translator run in a case where
`PHPUnit\Framework\Attributes\PostCondition` was now aliased *to Crucible's own class*, and
`$attribute->newInstance()->priority()` fataled (`Call to undefined method
LucianoPereira\Crucible\Attributes\PostCondition::priority()` — Crucible's own attribute exposes the
value as a property, not that method). Confirmed by actually enabling the opt-in against the
harness's real `phpunit/phpunit` install and hitting the crash before writing any fix. Fixed with a
new `PhpUnitCompatibility::isLoaded()` accessor (exposing the existing `$loaded` flag) and switching
both `RealPhpUnitHookAttributes` and the new `RealPhpUnitAttributes` to guard on that instead —
correct in all four installed/opt-in combinations, re-verified against the same opt-in scenario
afterward: no crash.

**Verification**: every fix confirmed against a real, installed `phpunit/phpunit` first (a direct
`MetadataParser`/`RealPhpUnitOutcomes` probe script, not synthetic fixtures), then Crucible's own
suite re-run unchanged (976 tests, 944 passed, 0 errors, 32 skipped — same as before any of these
changes) and `phpstan analyse` clean on every touched file. No re-run of the `spatie/laravel-data`
or Monolog benchmarks: both are Pest-dataset/PHPUnit-TestCase suites that use Pest's own `dataset()`
mechanism and real PHPUnit's inherited assertions respectively, not the specific
`PHPUnit\Framework\Attributes\*` names this entry's translator targets — re-running them would
reconfirm the existing 1338/1338 and 1153/1153 totals without exercising the new code path at all.

### D-097: a third real-world benchmark (phpcpd-next/phpcpd) — `Constraint::matches()`'s visibility didn't match real PHPUnit's

A fresh, previously-untested real-world project (`phpcpd-next/phpcpd`, a maintained PHPUnit-based
fork of the archived `sebastianbergmann/phpcpd`, chosen because it exercises real PHPUnit's own
custom-`Constraint` API — a pattern neither Monolog nor `spatie/laravel-data` happens to use) run
through Crucible's PHPUnit dialect, `->phpunitCompatibility(true)`, real `phpunit/phpunit` 11.5.55
installed alongside it for ground truth (`OK (201 tests, 444 assertions)`).

**Isolating the actual gap first**: the initial run fataled immediately on class-loading. Setting
the two files that reference it aside confirmed the fatal was the *only* blocker — the other 194
of 201 tests (file finding, caching, tokenization, every detection algorithm, CLI parsing, JSON/
SARIF/PMD formatters) already passed 194/194 under Crucible, unmodified.

**Root cause**: `LucianoPereira\PhpcpdNext\PHPUnit\DuplicationConstraint extends
PHPUnit\Framework\Constraint\Constraint`, overriding `matches()` as `protected` — matching real
PHPUnit's own declared visibility exactly (verified against installed phpunit/phpunit source:
`protected function matches(mixed $other): bool`). Crucible's own `Constraint::matches()` was
`public` — a deliberate design choice (`~20` call sites elsewhere in the engine call `->matches()`
directly, outside the `Constraint` hierarchy, notably `Dialect/Pest/Expectation.php` and
`Double/Mockery/*`). Once `PhpUnitCompatibility::load()` (under the explicit opt-in — real
`phpunit/phpunit` installed, D-019's coexistence policy) aliases
`PHPUnit\Framework\Constraint\Constraint` onto Crucible's own class, Crucible's own class becomes
the *effective parent* for every real, un-aliased subclass — and PHP forbids narrowing an
inherited method's visibility on override. `DuplicationConstraint::matches()` (protected) narrowing
from a public parent fatals with `Access level to ... matches() must be public`. Confirmed with a
direct repro (empirically verifying PHP's own protected-access rules first, not assumed) before
touching anything.

**Fix, scoped to the two callers actually at risk, not a blanket rewrite**: changed
`Constraint::matches()` itself to `protected`, matching real PHPUnit exactly. Every one of
Crucible's own ~35 concrete subclasses (`IsEqual`, `TraversableContains`,
`MatchesRegularExpression`, …) already explicitly redeclares `matches()` `public` — a legal
*widening* PHP always permits — so calling `matches()` on any of Crucible's own hardcoded instances
(e.g. `(new IsEqual($x))->matches($y)` in `Expectation.php`) stays exactly as legal as before,
because PHP resolves method-visibility access against the object's *actual*, most-derived class,
not the declared/static type. `LogicalNot::matches()` calling `$this->constraint->matches($other)`
on another `Constraint` instance is likewise unaffected: `LogicalNot extends Constraint`, and PHP's
protected-access rule permits a class to call a protected member on *another* instance sharing the
same ancestry (verified directly with a repro before relying on it, not assumed from memory of the
rule). The only two call sites where the constraint could genuinely be a **user-supplied**,
potentially-real-PHPUnit-descended instance, called from a class **outside** the `Constraint`
hierarchy, were `MethodConfigurator::appliesTo()`'s `argumentListMatcher`/`argumentMatchers`
branches (Mockery-style argument matching, `withArgumentList(Constraint $matcher)`'s own public
contract accepts any `Constraint`) — both switched to `evaluate($value, '', returnResult: true)`,
`Constraint`'s own existing public entry point (already how `Assert::assertThat()` — the standard,
most user-facing constraint consumer — was already calling it, unchanged).

**Verified against the real benchmark, not just Crucible's own suite**: `phpcpd-next/phpcpd`'s own
201-test suite now reports **201/201, 0 errors, 0 failures** under Crucible — exact parity with
real PHPUnit's `OK (201 tests, 444 assertions)`. Crucible's own suite re-run unchanged (976 tests,
944 passed, 0 errors, 32 skipped) and `phpstan analyse` clean.

### D-098: the report-format/subscriber/progress-view plugin architecture, and landing D-091's own code

D-091 documented the `RunModel`/`Document`/`Block` content-AST work but its actual code sat
uncommitted through D-092–D-097; this entry both finally lands it and builds the extensibility
layer on top that D-091 didn't describe: third-party report formats, subscribers, and progress
views, registered in `crucible.php` the same class-string + `#[Attribute]` + reflection shape
`extension()`/`CommandGate` already established, not a bespoke mechanism per kind.

**`AbstractRegistry`** (`src/Reporting/Registry/`) is the shared describe/list/validate machinery
all three plugin kinds need — extracted once `ReportFormatRegistry` and `SubscriberRegistry` turned
out identical except which attribute/contract they reflect against and the noun in their error
prose. `PluginAttribute` (the six-property shape every kind's own attribute extends) and
`PluginDescriptor` (what `describe()` returns — metadata plus computed `available`/
`missingRequirements`, never throwing for a misconfigured entry so `crucible extensions` stays
usable as a diagnostic even when one plugin is broken) are the two shared value types.
`resolve()`/`instantiate()` are deliberately *not* on the base — each kind constructs its instance
differently (report format: zero-arg + call-time params; subscriber: constructor param-spread;
progress view: stream + constructor param-spread), and PHP fatals overriding an abstract method
with incompatible arity.

**Three concrete registries**, one per kind: `ReportFormatRegistry` (`ReportFormatContract`,
`#[ReportFormat]`), `SubscriberRegistry` (`SubscriberContract`, `#[Subscriber]`),
`ProgressViewRegistry` (`ProgressViewContract`, `#[ProgressView]`). Crucible's own PDF/Markdown
formats, JUnit subscriber, and console/testdox/teamcity progress views are pre-seeded through this
identical registration path in `Configuration\Builder`'s constructor — no privileged, hardcoded
dispatch; a project's `crucible.php` overrides any entry by calling `->reportFormat()`/
`->subscriber()`/`->progressView()` again with the same key. `ConsoleReporter`/`TeamCityReporter`/
`TestDoxReporter` moved from the plain `Listener` interface to `ProgressViewContract` (still a
`Listener` for the live event stream — the new interface adds nothing to that contract, it's what
the registry reflects `#[ProgressView]` against) as part of this same registration.

**`GenericReportWriter`** (`src/Reporting/`) replaces `PdfWriter`/`MarkdownWriter`'s two hardcoded,
one-per-format `Listener`s with a single writer driven entirely by `ReportFormatRegistry`:
accumulates finished tests the same way those writers did, builds one `RunReportDocument`
(`src/Reporting/Document/`, D-091's `RunModel` plus the run's own metadata) at `run:finish`, and
renders it through every registered, selected format — a third party's own format goes through
this identical path, no separate wiring. Four new `Block` types support the richer PDF this
enables: `Image` (embedded JPEG, verbatim DCTDecode bytes), `Svg` (vector counterpart, for
diagrams/logos that would lose sharpness rasterized), `PageBreak` (forces the next block onto a
fresh page — how a cover composes), and `Toc` (a table-of-contents page with dotted leaders to
each `Heading`'s page number; must be the document's first block, since a real page number can't
be known without a first, discarded render pass).

**`crucible extensions`** (`ExtensionsCommand`, new): introspects all three registries —
`list()` (every registered plugin, its availability, missing requirements), `detail(--key=)`
(one plugin's full param schema), and `--preview` (a live, code-driven render against
`SampleDocument`/`SampleRun` — a fixed, representative fixture — so a plugin's actual output is
never something you have to trust documentation for). All three kinds walk through one
`array<string, AbstractRegistry>` map rather than a per-kind branch, so a fourth plugin kind later
touches one map entry, not a new command branch.

**Verification**: Crucible's own suite (976 tests, 944 passed, 0 errors, 32 skipped) and
`phpstan analyse` clean across the full, now-landed diff — the same baseline re-confirmed at every
checkpoint through D-092–D-097 while this code sat uncommitted alongside it.

---

## The pre-release audit — proving what was only asserted

### D-099: the assertion tail, built and proved rather than audited

D-070 reflected both `Assert` surfaces, found 45 missing `assert*` methods, grepped every
real consumer available, found zero uses, and declined the lot. The finding was sound and
the conclusion did not follow from it: "nothing observed demands it" answers whether the
gap is urgent, not whether a drop-in replacement should have it. A migrating suite meets
the surface, not the audit, and the two failure modes are not comparable — a method that
disagrees can be argued with, one that is absent is a parse error in someone else's
codebase with nothing that names Crucible's position.

All 45 are implemented, in five families, and held to the standard every other
compatibility claim here meets: fixture `19-assertion-tail` runs 98 cases through both
engines and compares outcomes, both directions per method.

Four families needed their semantics observed rather than reasoned about, and three held a
surprise that reasoning would have gotten wrong:

- `IgnoringWhitespace` **collapses** runs of whitespace and trims; it does not strip.
  `'a b'` and `'ab'` stay unequal, so the assertion still tells two words from one. The
  other reading would have quietly passed a class of genuine defects.
- `assertArraysAreEqual([1,2],[2,1])` **fails**. "Ignoring order" relaxes key *order* and
  never the pairing — `0=>1` and `0=>2` disagree whatever the order. Only the
  `Have*Values` spellings drop keys, and only their `IgnoringOrder` forms are multisets,
  which keep duplicate counts. Eight names, three independent axes: one constraint taking
  them as flags rather than eight near-copies.
- The XML family compares canonical form (C14N), where whitespace *between* elements is
  insignificant and whitespace *inside a text node* is not. Comments drop unless the
  `ConsideringComments` spelling keeps them — C14N's own `withComments` flag rather than a
  second code path. Input that will not parse raises `XmlException` and ends the test as an
  **error**: the assertion could not ask its question, which is not the answer being no.
  Deliberately not an `AssertionFailedError`, and the fixture pins that distinction because
  it is observable and the incumbent makes it too.

`ext-dom` becomes a declared requirement; D-041's no-XML rule was always about
configuration *input*, not assertions over a user's own document.

The fixture also exposed a defect in the conformance harness itself, present since it was
written: tests were keyed by method name and dataset, so two classes naming a method the
same thing collapsed into one key — last read wins, and a divergence in the other could not
be seen. Keyed by file now, which both sides state directly. Fixture 19 went from 85
compared to 98, and every other fixture is compared more strictly than before.

### D-100: the report formats, compared against the incumbent for the first time

`--coverage-xml` had the only probe, and it is the format with the fewest readers. Jenkins
and Sonar read Clover, GitLab and Codecov read Cobertura, every CI reads JUnit, and none of
those had been compared to anything. Their unit tests assert what Crucible emits, which is
the question already answered — a writer cannot catch its own omission by agreeing with
itself.

Four of the five were wrong. Clover was wrong in the worst way, stating false information
rather than omitting it: no `<package>`, no `<class>`, no method lines, and `loc="0"` for
every file it described — a document that parses cleanly and renders as a project
containing no code. Cobertura's `<methods/>` was always empty and every complexity zero,
the metric the format exists to rank by. JUnit carried no `assertions`, `file`, `line` or
`class`, so nothing could navigate from a CI report back to the test that failed. Crap4J
needed no structural work.

`vocabulary()` moved to `conformance/vocabulary.php`: five documents asking one question
deserve one implementation of it.

Two notes on the JUnit half. Assertion counts existed only inside the runner's risky-test
decision and now ride `test:finish` as a default-omitted field, read and cleared in
`finish()` rather than where they are measured — the counter resets in `attempt()`, which a
pre-empted skip never reaches, so reading it at the measurement reports the previous test's
count for every skipped test. And the failure message moved from an attribute into the
element's text where the incumbent puts it; the skip reason rides as text too, which the
incumbent drops entirely, because text content costs no shape and losing the reason would
be a real loss.

### D-101: shape parity is not value parity, and the coverage that proved it

Probe 22 asks whether a consumer would parse Crucible's reports. Probe 23 asks whether it
would read the same numbers, and these are different questions: Cobertura scored 9 of 11
shapes while hardcoding `complexity="0"`, and a Clover document with the right elements and
`loc="0"` renders as a project containing no code. A coverage number decides whether a
build passes, so a report that is structurally perfect and numerically wrong is worse than
one that fails to parse — it is believed.

The probe opened with eleven recorded divergences, each with a reason written for it, and
every reason was wrong. Not a judgement call about differing conventions: a defect with an
explanation attached. **That is the failure mode worth remembering — a divergence you can
explain plausibly is indistinguishable from one you have understood, and only going and
looking separates them.**

None of the eleven lived in a writer. Four causes, all in coverage:

- A file's last line went uncounted when the file ended in a newline, so every `loc` and
  `ncloc` was one short and every ratio against them slightly wrong.
- A method's closing brace counted as a statement. Xdebug reports it executable because the
  implicit return lives there, and nothing filtered it back out, so a two-line body measured
  three. It is only redundant when the method has something else to measure: a body that is
  empty, or only a comment, has no other executable line and the brace is the sole evidence
  it ran. That boundary was found by comparing both reports over one fixture, not by reading
  the incumbent's analyser.
- A method's range stopped at its last statement rather than its closing brace. The token
  walk cannot see the brace — `token_get_all` returns a bare string for `}` with no line
  attached, so `lineOf()` reported the last token that had one — so every method was
  recorded ending a line early and a comment-only body reported itself untested. The
  incumbent reports `end="16"` where Crucible reported 15: wrong, not a different
  convention. `PhpToken` sees it, and the range is extended once at parse time so every
  report inherits the fix.
- A test that never reached a verdict still contributed its hits. A skip runs
  `markTestSkipped()` and stops; an error throws partway through. Those lines execute in the
  literal sense and say nothing about the code under test, and the incumbent lists them
  executable with no hits — which is what `withoutHits()` already said for risky. A
  **failure** still counts: it reached its assertion and disagreed with it, a complete
  measurement.

Together they made the same code report a 63.6% line rate where the incumbent reports
42.9%. Nothing in the suite could have caught it: 1088 tests passed throughout, and all five
formats sat at perfect shape parity while the numbers inside them were wrong.

### D-102: TeamCity, and the format where matching would have been the wrong goal

TeamCity started at 0 of 10 messages, and the reasons are worse than the number. No
`flowId` on anything, which is what a reader uses to keep interleaved parallel runs apart —
without it two workers' output merges into one stream with failures attributed to whichever
test was adjacent. No suite messages at all, so a reader got one flat list with no route
back to a file. No `locationHint`, so an IDE could name a failing test but not open it,
which is the one thing the protocol exists to make possible. All ten agree now.

Incomplete tests gained the trace the incumbent carries for them. A skip says something
about the environment and points nowhere; an incomplete says "not finished yet" about a
specific line, and the reader wants to jump to it. `RunStarted` carries the plan size, since
`testCount` is how a reader shows progress against a total and nothing on the stream said
how many tests were coming.

**`--coverage-php` is where parity is the wrong goal, and the probe says so rather than
recording a divergence.** The incumbent's file embeds a serialized
`SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData` — its own object, with its
own private properties. Reproducing that shape means writing objects under another library's
class names so that library's `unserialize()` accepts them, which is not compatibility but
impersonation, and it breaks the day a private property is renamed. Every other format in
these probes is an interchange format with outside readers; this one is a library talking to
itself.

Probe 25 asserts instead what a consumer depends on: the dump is plain data, needing no
class of Crucible's to read it — the entire reason to dump coverage to a file — it announces
the format it is in, and it agrees with the other reports of the same run. Two
serializations of one truth that disagree mean one is lying, and probe 23 cannot catch it
because it never reads this format.

---

### D-103: a measured bug is not a specification, and quirks are named one at a time

Pest 5's value matchers were implemented against the running engine, not against their
names — Pest 5.1.1 installed in a scratch project and probed by execution, which is the same
oracle discipline the rest of the project uses. Three of the names do not mean what they
look like, and the reading only came out under execution: `toBeSlug` asks whether a slug
*can be made*, not whether one is already there, so `'run tests'` and `'a@b'` pass there;
`toBeDomain` is `toBeHostname` plus a dot, agreeing on ten of eleven probed inputs and
differing only on `localhost`; `toBeHexadecimal` refuses the `0x` prefix. A fourth,
`toHaveSuspiciousCharacters`, is **not registered in 5.1.1 at all** — calling it raises "The
expectation does not exist", so the note listing it was simply wrong.

The probe also settled a classification that guessing would have got backwards. `toBeClass`,
`toBeEnums`, `toHaveConstructor`, `toHaveMethods`, `toBeCasedCorrectly`,
`toHaveLineCountLessThan` and `toHaveFileSystemPermissions` are **arch** matchers there: they
sweep a namespace's source files and report per file. Handed anything else they match nothing
and pass — `expect('NoSuchThing')->toBeClass()` passes, and so does
`expect(Plain::class)->toHaveMethods(['imaginary'])`. A matcher that cannot fail is what
D-066 already refused, and Crucible's presets answer the same questions one class-string at a
time so they bite on exactly those inputs.

Over 63 inputs spanning the eight format matchers, the two engines now agree on 62. The
sixty-third is a bug: the incumbent rejects `'0'` for `toBeSlug` and accepts `'9'`, because it
tests the reduced string for emptiness and PHP counts the string `'0'` as empty. Nothing about
slugs separates the two digits.

**Crucible answers correctly by default and lets a suite ask for the bug back by name.** The
default is correctness because copying a defect across makes it the specification, and every
later reader then has to guess whether the behaviour was intended. The opt-in exists because
correctness is the wrong answer for one audience: a suite mid-port that already asserts the
quirk, where the failure is noise rather than a finding.

What it is *not* is a mode. A single `->pestCompatibility()` switch would become a bucket
whose contents nobody can audit, and a user flipping it to silence one failure would silently
accept every other bug inside it — including ones added later, which is the part that rots.
`Quirk` is an enum, opted into individually as `->quirks(Quirk::SomeName)`, and follows the
same one-name-three-jobs shape as phpcpd 1.4's rule registry and Crucible's own
`ImpactReason`: the case's string value is at once what is written in `crucible.php`, the heading
in the documentation, and the key the runtime reads. There is nothing to keep in sync, and a
quirk that is never opted into costs a comparison against an empty list.

**As of D-110 the enum has no cases** — every divergence that once needed one turned out to be
ours rather than the incumbent's. The mechanism is kept for the bar it enforces, not for its
current contents; see D-110 for why an empty enum is the parity claim rather than dead code.

---

### D-104: a refusal is not a verdict, and `->not` must not be able to launder one

Crucible reported **green where the incumbent reports red**, on 373 of the matcher grid's
3,124 cells. Pest 5.1.1 type-guards eleven matchers and declines a subject of the wrong type
instead of answering about it, raising `InvalidExpectationValue`: seven that require a string
(`toBeHostname`, `toBeUuid`, `toBeDomain`, `toBeIpAddress`, `toBeMacAddress`, `toBeUlid`,
`toBeHexadecimal`) and the four `toHave*CaseKeys`, which require an iterable. Crucible had no
equivalent and answered `false`.

Positive, the two are one outcome — both stop the test — which is the whole reason this
survived a 3,124-cell sweep. Negated they are opposites, because a failure inverts into a pass
and a refusal stays a refusal. `expect([])->not->toBeHostname()`, `expect([])->not->toBeUuid()`,
`expect(1)->not->toBeIpAddress()` and `expect('x')->not->toHaveCamelCaseKeys()` all error in the
incumbent and all passed here, so a migrated suite went green **exactly where it had been red**.

Two things hid it, and both were measurement habits rather than code. The probe caught
`\Throwable` and wrote `f`, so "the assertion failed", "the subject is the wrong type", "the
matcher does not exist" and "PHP crashed" shared one character; the grid now carries a third
state and a divergence may not sit on it. And every measurement to date was the **positive
form only** — the one form in which the defect is invisible.

So the refusal is an `InvalidArgumentException` and not an `AssertionFailedError`. Routing it
through the constraint engine would hand it to `LogicalNot` and reintroduce precisely the
laundering it exists to stop; it is raised before any constraint is built. It is applied per
**subject** rather than per call, because that is what the incumbent does — measured,
`expect([[]])->each->toBeHostname()` refuses the item while
`expect(['localhost'])->each->toBeHostname()` passes, so the container's own type is never what
is asked about.

The guard's shape is the incumbent's, not a tidy-up. `toBeEmail` and `toBeUrl` take a
non-string and answer `false` there, so neither grew a guard here. The key-case family guards on
`iterable`, not `array`, and then answers about what it admits — `expect(new ArrayObject(['camelCase' => 1]))->toHaveCamelCaseKeys()`
passes in the incumbent, so refusing it as a non-array would have failed a suite the incumbent
passes. No corpus value could have caught that one: every value in it is an array or a scalar.

None of this is a quirk. A refusal is not the incumbent being wrong — it is the incumbent being
*narrower*, which D-004 and D-008 make the dialect's behaviour rather than something to
correct. The bar a quirk has to clear is a false answer, and declining to answer is not one.

**The decision is how the negated form gets recorded.** Not as a second grid: 44 duplicated
rows are 44 rows to keep in sync, and a stale one agrees with whatever Crucible does — the
single failure this probe exists to prevent. Measured instead over all 3,124 cells and all 44
rows, the incumbent's negated verdict is its positive one flipped, with a refusal staying a
refusal. So the record stays one grid, the probe **derives** the negated expectation from it,
and the generated suite re-proves that relation against the live incumbent on every run. If the
incumbent ever stops inverting, the derivation stops being safe and the probe says so, rather
than going on being trusted — the same standing-proof shape as `contradictions.php`.

---

### D-105: fidelity is judged from the answer, not from the internals

D-104 guarded eleven matchers by widening the *matcher* axis. Widening the *subject* axis found
36 more cells of the same false-green class, in the twelve matchers D-104 deliberately left
alone.

The cast family — `toBeAlpha`, `toBeAlphaNumeric`, `toBeDigits`, `toBeLowercase`,
`toBeUppercase`, `toBeSlug`, the four `toBe*Case`, `toBeEmail`, `toBeUrl` — reads its subject
*through* a string cast rather than requiring a string. That is the whole mechanism, and it is
why `[]` reaches `toBeAlpha` as `'Array'` and passes. It follows that the only subject the
family cannot handle is one PHP refuses to render: an object with no `__toString`. Measured, the
incumbent dies there with a **raw PHP `Error`**, over all twelve matchers, in both forms, for
`stdClass`, a `Closure` and an `ArrayObject` alike — while a resource, an array, an int and
`null` all cast and answer. Crucible answered `false`, so `expect(new stdClass())->not->toBeAlpha()`
passed here and errored there, identically with the cast quirk on and off.

**Crucible declines instead of crashing, and that is not a compromise.** Fidelity is owed at the
level of the *answer*: both engines produce no verdict, so `->not` cannot launder either into a
pass, and a migrated suite behaves the same. Reproducing the `Error` itself would copy an
internal for no gain — and the incumbent's own deliberate guard on D-104's eleven matchers is
the evidence that declining is what it meant to do here too. The guard is quirk-independent,
because there is no verdict for a quirk to restore.

`SubjectRule` carries the three requirements a matcher can place on its subject — `IsString`,
`IsIterable`, `ReadableAsString` — with the measured justification on each case. Its predicates
delegate to `ValueType` wherever one already exists, so the two enums cannot drift apart about
what a string or an iterable is; `ReadableAsString` is the one PHP has no type for.

**One quirk was incomplete, and the same measurement found it.** `Quirk::StringifiedSubject`
reproduces the incumbent's cast, but `stringify()` returned `null` for a resource while the
incumbent casts one to `'Resource id #N'`. `toBeSlug` is the only matcher in the family where
that changes the answer rather than coinciding with it — the reduced string is truthy, where the
rest reject the digits and the spaces either way — and it passes in the incumbent. The quirk was
claiming to bridge a case it did not.

**How it was verified, which is the part worth repeating.** A side-by-side harness with the
subject set defined **once** and required by both engines, so neither could be measured against
a different value than the other, run before either fix landed. It validated itself first: it
reproduced all 37 already-recorded `StringifiedSubject` divergences, and only then were its two
new findings trusted. Before: 50 rows agreed, 36 were false-green, 1 quirk row was unbridged.
After: 86 agree, 22 are recorded divergences the quirk bridges, 0 false-green, 0 unexplained —
and the counts reconcile exactly.

**A gap this exposed and did not close.** §5's rule is that Crucible's own, broader reading
"belongs to the `crucible` dialect". There is no mechanism for that. `expect()` resolves to one
`Expectation` shared by both dialects, and D-034 defines the crucible dialect as "Pest minus the
ceremony" — a different set of *globals* over the same matcher layer. So the pest half of the
rule is implementable today and the crucible half is not, which means the 55 divergences
withdrawn to that dialect went nowhere. Recorded here as an open architectural gap: matcher
behaviour has no per-dialect seam.

### D-106: a quirk restores an incumbent bug; a gap in Crucible is filled by default

`Quirk::StringifiedSubject` carried 37 of the 42 recorded divergences, and its docblock framed
all 37 as the incumbent's defect. Measured against **PHP itself** rather than against either
engine, they are two populations that want opposite fixes — which is the project's own test for
a drawer.

| subject | `(string)` | agrees with `var_export`/`json_encode` | engine warns | verdict |
|---|---|---|---|---|
| int (12 rows), float `1.5` | `'98'`, `'-1'`, `'1.5'` | yes | no | Crucible lacking |
| float `0.0` / `1.0` (5 rows) | `'0'` / `'1'` | no — loses the `.0` | no | Crucible lacking |
| bool (3 rows) | `'1'` / `''` | no — both say `true`/`false` | no | incumbent wrong |
| array (16 rows) | `'Array'` | no | **yes** | incumbent wrong |
| resource | `'Resource id #N'` | id is not stable across runs | no | incumbent wrong |
| Stringable | its own declared string | — | no | Crucible lacking |

**The distinction is what PHP endorses, not what Pest does.** The engine emits "Array to string
conversion" for the array cast, so it is a rendering PHP warns about rather than one it means;
`contradictions.php` already proves the incumbent inconsistent there. `false` casts to `''`
while `true` casts to `'1'`, so `toBeDigits(true)` passes and `toBeDigits(false)` fails —
lossy-asymmetric, the same shape as the falsy-`'0'` trap that already earned a quirk, and now
carrying its own contradiction pair. A resource's id is not stable across runs. Those three stay
quirk-gated.

An int, a float and a `Stringable` are the other thing entirely. They render the way `var_export`
and `json_encode` render them, the engine raises no warning, and PHP accepts a `Stringable`
wherever a string parameter is declared — `filter_var()` coerces one to its string, and only
`is_string()` refuses. Reading them through the cast is **a capability Crucible lacked**, not a
defect it was declining to copy. So Crucible casts them by default, and those 18 rows leave
`divergences.php` rather than moving to a second quirk. Offering a quirk there would be offering
to restore a bug that was Crucible's own.

**The measurement was not a clean subtraction, and that is the part worth repeating.** Filling
the cast healed the 18 rows *and reported 2 NEW*: `toBeSlug(0)` and `toBeSlug(0.0)`. Those cells
had been **agreeing for two unrelated reasons** — the incumbent failing them through its
falsy-`'0'` trap, Crucible failing them for having no cast at all. Removing one of the two
mistakes left the other exposed. They were recorded under `quirk_falsy_slug` — **superseded by
D-109**, which removed that quirk and the rows with it: composing `IsEmpty` made both cells
agree with the incumbent outright, so there was no divergence left to bridge. A cell can agree because both engines are right about it, or because two different errors
land on one answer, and only changing one of them tells the two apart — so a gap-fill is never
a pure subtraction from the record, and reasoning about one as though it were is how a
divergence stays hidden.

`divergences.php` 42 → 26; the quirk's population 37 → 19. Sabotage-checked three ways: the
`Stringable` arm dropped → 2 tests fail; `is_float` dropped → 1 fails; the code reverted with the
new record kept → the exact mirror image, 18 `NEW` and 2 `HEALED`.

**What this does not cover.** No corpus value is an object, so the `Stringable` arm is proved by
the suite alone and is invisible to the probe — the same blind spot that hid D-105's guard, now
carrying default behaviour rather than only a refusal. Widening `values.php` is owed by this
change, not merely by the standing Tier 1 item.

**And it sharpens the open gap in D-105.** The same `Stringable` is now *read* by the cast family
and *refused* by the `is_string` family, both faithfully to the incumbent, while `filter_var()`
accepts it in both. The rule applied here — a rendering PHP endorses is read by default — says
the `crucible` dialect should accept it either way, and there is still no per-dialect matcher
seam for that to live in.

### D-107: the corpus travels by `require`, so the value axis is not bounded by `var_export()`

The matcher probe's blind spot was never the matcher list. Every one of the 71 corpus values was
an array or a scalar, and that bound — not the 44-of-101 matcher coverage — is what hid the
`ArrayObject` key-case divergence (D-104) and all 36 cells of the cast family's false-green on an
unrenderable object (D-105). Both sat in matchers the sweep already covered. It could not see
them because it had nothing to ask them about.

**The cause was the transport, and it was two lines.** `compare.php` and `regenerate.php` shipped
the corpus into the oracle by embedding `var_export($corpus, true)` in the generated suite.
Measured: `var_export(new SplFileInfo('abc'))` emits `\SplFileInfo::__set_state(array(...))`, and
`SplFileInfo` has no `__set_state`, so it is a **fatal on the far side**; `ArrayObject` and
`Closure` export the same way. Only `stdClass` round-trips. So the corpus could not hold an
object, and the probe's blind spot was a property of how it serialised rather than of what anyone
chose to measure.

Both entry points now `require` the canonical `values.php` by absolute path. This is **not** the
"copy the corpus next to the oracle" the old comment warned against — there is still exactly one
corpus file, and now one fewer representation of it. A `return [...]` data file is also not the
framework autoload D-019 refuses.

**The transport change was proved a no-op before anything was added:** regenerating against the
unchanged 71-value corpus produced a byte-identical `sweep.php`, 3,124 cells. Only then were
values appended.

**Five objects, each earning its place**, appended never inserted, because `divergences.php`
addresses cells by corpus index:

| value | what it puts under the grid |
|---|---|
| `stdClass`, `static fn() => 1` | D-105's guard: PHP cannot render them, so the cast family must decline |
| `ArrayObject(['camelKey' => 1])` | both at once — unrenderable as a string, but iterable, so the cast family declines while the key-case family answers about its keys (D-104) |
| `SplFileInfo('abc')` | D-106's default cast: renderable, and read with no quirk |
| an anonymous `Stringable` returning `'localhost'` | the D-106 asymmetry — the cast family reads it, the `is_string` family refuses it, exactly as the incumbent does |

No `Generator`, for a mechanical reason rather than a principled one: one corpus is swept by every
matcher in turn and a `Generator` is consumed by the first of them, so `ArrayObject` carries the
non-array-iterable requirement instead. No resource, because it would need an `fopen` at require
time that nothing closes; `Quirk::StringifiedSubject` covers that in the suite.

**Result: 3,124 → 3,344 cells, 373 → 460 refusals matched, and the record did not move** — 26
divergences, all 26 still bridged, zero `NEW`, zero `ANSWERED`, zero `ONEWAY` on the first run.
The 87 new refusals reconcile exactly: 23 each for `stdClass` and the `Closure` (7 `IsString` + 4
`IsIterable` + 12 `ReadableAsString`), 19 for `ArrayObject` (the same minus the four key-case
matchers it satisfies), and 11 each for the two renderable objects (7 + 4, none from the cast
family).

**What this buys, stated as the sabotage that now fails.** Drawing `ReadableAsString` at "accept
anything" — dropping D-105's guard — previously produced **no probe finding at all**, because no
corpus value could reach it; the suite was the only instrument. It now reports **36 `ANSWERED`**,
the exact cell count D-105 fixed. Dropping D-106's `Stringable` arm reports **14 `NEW`**, likewise
invisible before. Two decisions that were suite-only are now grid-covered, which is what the
widening was for.

### D-108: code written as text is invisible to every gate, so write less of it

The drift code is what every parity claim rests on, and it carried the verdict classification —
`p` pass, `f` fail, `x` refused — in **six** places, not the five previously recorded: Crucible's
own `crucibleCell()`, **four** loops inside `compare.php`'s generated suite (two three-state, two
two-state), and one in `regenerate.php`. The oracle round trip — write a suite into the
incumbent's `tests/`, exec from the incumbent's own directory, `unlink`, reconcile the reported
count — was duplicated wholesale between the two entry points.

**That duplication is what produced the false-green bug.** The old code caught `Throwable` and
wrote `'f'`, so moving to three states meant editing every copy, and the negated sweep then added
two more. The count went 3 -> 5 *while the bug the duplication had caused was being fixed*.

**Why no gate caught it, corrected by measurement.** The standing explanation was "nothing scans
`conformance/` — phpstan's paths are `src` + `tests/unit`, and the duplication check defaults to
`src`". Half of that holds. The other half does not, and the difference matters:

- PHPStan genuinely did not scan the directory. It does now, and the coverage is real rather than
  nominal: a deliberate `strlen(42)` planted in `compare.php` is reported.
- **A clone detector would not have caught it at any threshold.** Measured against phpcpd 1.4:
  *no* clone in this directory at default settings, before or after the copies were removed, and
  the single clone found at `--min-tokens 40` is in `probe.php`'s data rows — identical in both
  states, untouched by this work. The five copies lived inside **string literals** being
  concatenated into a generated suite, and code inside a string is not code to a tokeniser.

So the lesson is not "point more gates at this directory". It is that **generated code written as
string concatenation is unreachable by static analysis of any kind**, and the only fix is to stop
writing it that way.

**What changed.** A shared `oracle.php` carries the round trip, the reported-count parse, and the
oracle lookup; both entry points build their suite bodies as nowdoc rather than escaped
concatenation. The classification moved into `cell.php` — **a real file the generated suite
`require`s**, the same transport D-107 gave the corpus — so a type checker can read it. It names
`PHPUnit\Framework\ExpectationFailedException`, which Crucible does not depend on, so
`phpstan.neon` excludes it and a new `drift` tier in `composer analyse:oracles` checks it against
the real pest-oracle, exactly as the Laravel bridge and duplication check are handled. Six
classification sites became **two**, and the second is `crucibleCell()`, which must not merge with
the first: different process, different engine, different exception type. Merging them would mean
one classifying by a name its own engine never raises, which is how a refusal came to read as a
failure in the first place.

**The type gate found 73 real errors** in the two entry points, every one the same root cause:
`require` is `mixed`, so every data file loaded untyped. They are fixed by six loaders in
`oracle.php` that **validate** the shape and throw on a malformed file, rather than by an
annotation asserting it — these files are edited by hand and by generator, and a bad one used to
surface as a puzzling verdict far from the edit.

**Sabotage-checked, because a gate nobody has seen fail is not known to work.** Wrong return type
in `cell.php` -> `return.type` + `return.phpDocType`; a nonexistent matcher -> `method.notFound`,
which also proves the tier resolves Pest's own `Expectation` from the oracle checkout rather than
passing vacuously over an unresolvable file. Behaviour preservation was proved the same way it was
for D-107: `regenerate.php` reproduces `sweep.php` byte-identically, and `compare.php` reports the
same 3,344 cells, 26 divergences, 26 bridged, 460 refusals and 3,419 rows re-proved.

**Still not covered, and worth saying plainly.** The suite *bodies* remain strings, so nothing
checks them; what changed is that there is one copy of the part that classifies rather than six.
And the rest of `conformance/` is still outside the type gate — measured at **236 errors** if
added today, mostly in the 19 fixture suites, which are deliberately odd because their job is to
exercise the runner. That is a visible gap now rather than a silent one.

### D-109: compose the primitive; two quirks were artifacts of not doing so

Two of the three quirks were removed, and not because the bar in §5 moved. The mechanism was
measured, and it showed there was never an incumbent bug to restore.

**How the incumbent actually assembles a value matcher**, probed by execution rather than read
(constraint 4): the test case is a real `PHPUnit\Framework\TestCase`, `Pest\Expectation` has **no
parent and no interfaces**, and every matcher — `toBeUuid` and `toHaveCamelCaseKeys` included —
increments PHPUnit's static assertion counter by exactly **+1**. They all route through one
`Assert::assertThat()`. Most compute a boolean in PHP and assert `IsTrue`, which is why almost
every failure there reads "Failed asserting that false is true". Where a PHPUnit primitive fits,
the incumbent composes it.

`toBeSlug` is one of those. Measured: `'0'`, `'!!!'`, `'---'` and `'   '` all fail with the
**identical** two-line message — the second line being PHPUnit's own *"Failed asserting that a
string is not empty"* — while `'00'` passes. So the predicate is "reduces to a NON-EMPTY slug",
where non-empty is `IsEmpty`, and `empty('0')` is true in PHP. `'0'` failing is not a
falsy-string trap the incumbent fell into; it is the primitive doing what it says, and `'00'`
passing is the proof it is emptiness rather than zero-ness. `toBeHostname` is the same shape one
layer down: `filter_var()` validates `'0'` and returns it, and that return is tested for truth.

**Crucible disagreed only because these two matchers wrote the predicate out by hand** —
`$slug !== ''` and `$value !== '0'` — where `IsEmpty` already existed. Composing
`LogicalNot(IsEmpty)` instead makes the incumbent's answer fall out. `compare.php` reported both
quirks **STALE** on the run that landed it, and 7 divergence rows **HEALED**, with zero `NEW`.
`toBeHostname` now matches the recorded incumbent on **76 of 76** corpus cells.

`contradictions.php` had claimed *"'9' is a slug and '0' is not, though nothing about slugs
separates them"*. Something does separate them, and it is the primitive the assertion is composed
from. The contradiction dissolved rather than being overturned, which is the outcome the file is
supposed to make possible.

| | before | after |
|---|---|---|
| `toBeSlug` | 27 lines, hand-written predicate + quirk branch | **8**, `reduce → LogicalNot(IsEmpty)` |
| `toBeHostname` | 19 lines, same shape | **8**, `filter_var → LogicalNot(IsEmpty)` |
| divergences | 26 | **19** |
| quirks | 3 | **1** |
| contradiction entries | 5 | **3** |

**The quirks were REMOVED, not deprecated.** A quirk that bridges nothing is a `crucible.php`
value that silently does nothing, which is worse than one that is gone. A test now asserts
`Quirk::tryFrom('quirk_falsy_slug')` is null, so the names cannot come back by accident. This is
a removal from a published surface, taken pre-1.0 and deliberately.

**Scope, measured so it is not over-generalised.** The pattern applies to exactly one thing: a
matcher that hand-wrote a predicate where a primitive already existed. A scan found **no others**.
The remaining value matchers are already `stringify`/`is_string` plus a single predicate —
`ctype_alpha`, `ctype_digit`, a UUID regex, `filter_var` — which is the same shape the incumbent
uses, and no PHPUnit primitive would replace them. So there is no third pilot worth running, and
the LOC effect of this decision is about −30, not the several hundred a family-wide refactor
would have suggested.

**`Quirk::StringifiedSubject` survives, and composition cannot reach it.** Its 19 rows live in the
`stringify()` step, *upstream* of any constraint: whether `[]` becomes `'Array'` is not a
predicate-versus-primitive question. It stands on independent evidence — PHP itself **warns** on
that cast — which is exactly the ground D-106 used to keep the array and bool arms while filling
the numeric ones. The two quirks removed here had no such independent evidence, and that absence
is what this decision turned on.

**It also revises D-106.** The two `toBeSlug(0)` / `toBeSlug(0.0)` rows recorded there as
newly-exposed `quirk_falsy_slug` divergences are gone: with the primitive composed, both cells
agree with the incumbent outright. D-106's finding that a gap-fill is never a pure subtraction
still stands; the rows it produced did not.

### D-110: no quirks, and that is the parity claim

`divergences.php` and `contradictions.php` are now **empty**, and `Quirk` has **no cases**. Over
3,344 grid cells — 44 matchers, 76 corpus values, both forms — Crucible answers exactly as Pest
5.1.1 does, with nothing to opt into. ✓ `compare.php`: *0 known divergences carried, 0 bridged,
460 refusals matched, 3,409 rows re-proved.*

The last quirk was `StringifiedSubject`, 19 rows: an array reaching `toBeAlpha` as `'Array'`, a
bool as `'1'`. D-106 kept it on the ground that PHP *warns* on the array cast, so the incumbent
was wrong to rely on it. **That reasoning was right about PHP and wrong about the dialect.** D-004
is unambiguous — a migrated suite must behave identically *without touching an option* — and
gating the cast meant `expect([])->toBeAlpha()` **failed on Crucible until someone opted in**.
That is precisely the violation the rule exists to prevent, and no amount of being right about the
warning repairs it. A quirk is for a bug worth declining by default; it is not a place to keep an
opinion that costs users green suites.

Crucible spells `'Array'` literally rather than casting, so it reproduces the **answer** without
emitting the engine's "Array to string conversion" warning — fidelity at the level of the answer,
which is the level that matters (D-105).

**The four causes of a divergence, now that all of them have been worked through.** This is the
useful residue of taking 97 rows to zero:

1. **The incumbent is genuinely wrong** — correct by default, quirk restores it. Requires evidence
   from *outside both engines*. **Nothing survived this test.**
2. **The incumbent is merely narrower** — a narrow answer is still a true one, so match it. 97 → 42.
3. **Crucible lacks a capability PHP endorses** — fill by default, no quirk, no row. D-106, 18 rows.
4. **The disagreement is an artifact of our own implementation shape** — a predicate written by
   hand where a primitive existed. Compose it and the disagreement vanishes. D-109, 7 rows, 2 quirks.

Categories 2–4 are all "not the incumbent's fault", and between them they account for every row.
Category 1 — the only one a quirk serves — turned out to be empty. That is worth stating plainly,
because the quirk mechanism was built on the assumption it would not be.

**The mechanism stays, with zero cases.** Deleting it would remove the bar it enforces: any future
divergence must NAME the quirk that restores the incumbent, and the probe verifies the quirk
actually does. A correction nobody can opt out of is a fork, not a fix. An empty enum says "we
currently reproduce no incumbent bug", which is both the accurate claim and the strongest parity
statement available. Three case names were removed rather than deprecated, and a test asserts
`Quirk::tryFrom()` returns null for each, so they cannot return by accident.

**The cost, recorded so it is not rediscovered as a bug.** `expect([])->toBeAlpha()` now passes
here exactly as it does there. Crucible's broader, more defensible reading of these matchers has
**nowhere to live** until the crucible dialect gets a matcher seam — which makes the open D-105/D6
gap the most load-bearing item left before 1.0, not a nicety. Every correctness opinion withdrawn
across D-106, D-109 and this entry was withdrawn *into that gap*.

**What this does NOT establish.** Zero divergences is a statement about 44 zero-argument matchers
over 76 values. It says nothing about the 39 argument-taking matchers, which the harness has no
axis for, and nothing about whether a real Pest 5 suite runs — ✓ no conformance fixture calls
`expect(`, both PHPUnit benchmarks have no path to this code, and the only Pest benchmark is
pinned to Pest ^4. The probe validates itself; nothing yet validates the probe.

### D-111: the pest coexistence asymmetry is PHP's, and the way round it is not loading the names

D-019 lets a PHPUnit project opt into coexistence with `->phpunitCompatibility()`. A Pest project
had no equivalent, and `PestBuilder::ensureUsable()` refused outright while `pestphp/pest` was
installed. That looked like an unfinished feature. It is not.

**The asymmetry is structural.** ✓ Measured: PHPUnit's surface is CLASSES, so aliasing can be
conditional and can fail fast. Pest's surface is GLOBAL FUNCTIONS, delivered through Composer's
`files` autoload (`pestphp/pest/src/Functions.php`, `src/Pest.php`). They are declared the moment
`vendor/autoload.php` runs — before any Crucible code — and PHP has **no `function_alias()`**;
redeclaring is an *uncatchable fatal*, not a trappable error. An in-process opt-in of the
`->phpunitCompatibility()` shape is therefore impossible, not merely unbuilt.

**What is possible is never loading them.** Composer's `autoload_real.php` guards every `files`
entry with `if (empty($GLOBALS['__composer_autoload_files'][$hash]))`, so pre-marking a hash makes
Composer skip that file. `src/Dialect/Pest/vocabulary-prelude.php` marks Pest's two function files
and nothing else. ✓ Measured: `function_exists('test')` and `function_exists('expect')` become
false while `Pest\Expectation` still resolves — the globals are suppressed, every class stays
autoloadable. Nothing is patched, monkeyed or unregistered; Composer is asked, through its own
documented guard, not to include two files.

It has to run before the entry script reaches the autoloader, so it is loaded with
`-d auto_prepend_file=` and never by `require`. ✓ End-to-end: with `pestphp/pest 5.1.1` and
Crucible both installed, `php -d auto_prepend_file=.../vocabulary-prelude.php vendor/bin/crucible`
runs the suite. The two coexistence mechanisms compose — the prelude frees Pest's *functions*,
`->phpunitCompatibility()` frees PHPUnit's *classes*, and a Pest project needs both because Pest
brings PHPUnit with it.

**The refusal now keys on the NAMES, not the package.** `ensureUsable()` asked
`InstalledVersions::isInstalled('pestphp/pest')`, which was a proxy for the real invariant and
wrong in both directions: it refused where the names were free (exactly the prelude case, where
the package is installed and `test()` is not defined) and would have said nothing if some other
package had taken them. It now refuses when `test()` is declared and Crucible did not declare it,
and the message explains *why* they cannot coexist and hands over the exact command.

**Deliberately NOT a self re-exec.** Making `->pestCompatibility()` work as a config option would
require Crucible to re-execute itself, because `crucible.php` is read long after the autoloader has
run. That is D2 — an open decision about restarters and the first runtime dependency — and this
entry does not pre-empt it. The capability is delivered where it is actually needed without one:
`CrucibleRun` already spawns its child through `proc_open`, so compat-check can add the flag
itself, and a human gets the command in the error message.

**What this unblocks — half of it.** `compat-check` needs BOTH engines live, so on a Pest project
it could not run at all. The prelude frees the *Crucible* half: measured, the child goes from 0
tests to 3 on a pest-installed project (D-114). The *oracle* half is still blocked, and this entry
originally claimed otherwise on the strength of a "No drift" that turned out to be vacuous — see
D-114 for the measurement and the correction.

### D-112: a pest build that declares nothing is not a pest file

The first real Pest 5 project ever run against Crucible found a false green at the level of
**discovery**, which no verdict-level parity check can see. Recorded after D-111 but shipped
before it, in `a472890`.

✓ `spatie/schema-org`, unmodified, `pestphp/pest ^5.0.4` pinned by the project itself:

| | tests | result | time |
|---|---|---|---|
| real Pest 5.1.1 | **1,926** | 1,926 passed, 31,059 assertions | 270.07s |
| Crucible, before | **64** | "OK", exit 0 | 0.07s |
| Crucible, after | **1,926** | 1,926 passed | 238.81s |

**96.7% of the suite silently not run, reported as success.** `tests/AnalysisTest.php` declares a
PHPUnit `TestCase` whose `#[DataProvider]` yields 1,862 cases, and ends with
`uses(AnalysisTest::class);`. `uses` is pest vocabulary, so the sniffer classified the file as
pest and handed it to `PestBuilder`, which found no `it()`/`test()` and produced an empty group —
and the class in that same file was never collected. The incumbent does not treat the two as
exclusive: it runs PHPUnit underneath and collects the class regardless. `uses()` **binds** a base
class; it does not **declare** a test.

So a pest build yielding zero tests now falls through to class discovery. Falling through is safe
and cheap: `build()` has already required the file, so the class is loaded and the guard below
finds it without executing anything twice.

**The alternative was tried first and rejected**, and the reason is worth keeping. Narrowing
`ENTRY_FUNCTIONS` to test-defining calls only (`test`, `it`, `describe`, `todo`) is the tidier
rule — and it is the same reasoning that already keeps `fixture()` out of that list. But it
changes classification for *every* project, and it cascaded: the file then reached class discovery
with `uses()` undefined and died on `Call to undefined function`, and defining it there hit a
second guard inside `PestRegistry`. Three patches deep on a symptom chain is a signal, not a
setback. The fallback touches only files that currently produce zero tests — the silent-loss case
by definition — and needed no other change.

**What this says about the probe.** 3,344 cells of verdict parity, zero divergences, and none of
it could see this: the matcher probe measures what a matcher *answers*, never which files get
collected. §7's false green was a wrong verdict; this is **no verdict at all**, reported as
success. The instrument that found it was a real project, which is the argument for benchmarking
over widening the grid further.

**Scarcity, measured.** Only **13 of 100** pest dependents are on pest 5 — mostly Laravel packages
or pest's own plugins. `spatie/schema-org` was chosen because it pins `^5.0.4` exactly (so
`composer install` respects constraint 6 with no override), requires only `ext-json`, and drags in
no framework.

---

### D-113: a covers target that cannot load resolves to nothing, like one that does not exist

`CoversTargets` has always documented the rule — "a target naming an undeclared symbol resolves to
no range — **never an engine error**" — and broke it for the one case that matters. `class_exists()`
autoloads, and autoloading a class whose parent is absent throws out of the loader. Crucible's own
`CrucibleServiceProvider` extends `Illuminate\Support\ServiceProvider`, which is deliberately not a
dependency (the bridge is type-checked against the vendored oracle instead, D-019). So on any
machine without illuminate — every developer machine, by design — resolving the one
`#[CoversClass(CrucibleServiceProvider::class)]` in the suite killed the process:

```
Uncaught Error: Class "Illuminate\Support\ServiceProvider" not found
  src/Coverage/CoversTargets.php(213): class_exists()
  src/Runner/TestRunner.php(577): settleCoverage()
```

**The coverage gate could not be run locally at all**, and CI never saw it: `composer check` has no
coverage step. A gate nobody can run is not a gate, and it had been that way silently.

✓ Measured against the oracle rather than assumed — phpunit 13.3.1 + xdebug, a covered class
extending a missing parent, both as a skipped test and an executing one: **exit 0 both times**, the
run completes, the unresolvable target contributes nothing and stays in the denominator as an
uncovered class. So the incumbent's answer is exactly what this file's own docblock already
claimed.

The fix is one guarded helper: the load is still attempted, but a throw during it means the name
resolves to no range instead of ending the run. `addMethod` shares it — same hazard, same answer.
The fixture is a class extending a namespace nothing declares, reached only through an autoloader
the test registers, so it can never be resolved by accident.

Sabotage-checked: with the guard removed the new test errors, and the full suite under
`-d xdebug.mode=coverage --coverage` goes from **dead at test 57** to 1,180 tests, exit 0, 57.26%
of 15,888 executable lines.

**What this says about a claimed gate.** The eight gates are listed as run; this one had been
*listed* and not *runnable* for as long as the laravel bridge has carried a covers claim. The other
seven pass in seconds and were exercised constantly, which is exactly why the missing one went
unnoticed — a gate that fails loudly gets fixed, a gate nobody invokes does not exist.

---

### D-114: an oracle that ran nothing is not agreement, and phpunit cannot be a pest suite's oracle

`compat-check` printed its verdict from two lists — tests missing from Crucible, and tests that
drifted to failure. Both empty means "No drift", and an oracle that collected **nothing** hands
over exactly that pair. So the vacuum and the real agreement printed the same green. The
Crucible-side hole was already guarded (`reportMissing` says so when Crucible ran no tests); this
is its mirror, and it is now a pure function of the two outcome maps — `nothingToCompare()` —
precisely so it can be proven without either engine installed.

**Why it mattered immediately.** ✓ Measured on a minimal pest 5.1.1 project with Crucible
installed alongside: `vendor/bin/phpunit` **cannot run a pest suite at all**. Pest refuses from
inside its own `TestSuite`:

```
Message:  Pest must be run through its own binary. Please run [./vendor/bin/pest] instead.
Location: vendor/pestphp/pest/src/TestSuite.php:72          exit 255, 0 tests collected
```

`compat-check`'s oracle *is* `vendor/bin/phpunit`. So on any pest project the oracle collects
nothing, and before this guard the command answered **"No drift: every test the real PHPUnit
oracle passes, Crucible also passes"** — true, and worthless, because the oracle passes none. The
D-111 end-to-end claim rested on that output. It is withdrawn.

**The prelude wiring, sabotage-checked in the same command.** The guard's message reports what
each side ran, which makes one run prove both halves:

| `CrucibleRun::command()` | oracle | crucible | verdict |
|---|---|---|---|
| with the prelude flag | 0 | **3** | oracle ran nothing, exit 1 |
| flag removed | 0 | **0** | oracle ran nothing, exit 1 |

The child really does depend on the flag — without it the dialect stands down inside the child
exactly as it does at the top level, and Crucible contributes nothing to the comparison.

**What that left open** — a pest project's oracle has to be `vendor/bin/pest`, not
`vendor/bin/phpunit` — is closed by D-115.

---

### D-115: the oracle is the binary that owns the suite, and what it cannot key is named

`compat-check` picked `vendor/bin/phpunit` because that was the only incumbent it knew. D-114
measured what that costs on a pest project — nothing collected, a vacuum reported. The binary is
now chosen: `vendor/bin/pest` where pest is installed, `vendor/bin/phpunit` otherwise. Pest logs
through the same JUnit logger, so the oracle side needed no new transport, only the right process.

**The keys line up almost by themselves.** ✓ Measured against pest 5.1.1 — its JUnit `file`
attribute for a pest-declared test is already `<relative file>::<test name>`, which is Crucible's
NDJSON id exactly:

| | pest JUnit | Crucible id |
|---|---|---|
| plain | `file="tests/ArithmeticTest.php::addition"` | `tests/ArithmeticTest.php::addition` |
| positional set | `…::doubles with data set "(1)"` | `tests/DatasetTest.php::doubles#0` |
| named set | `…::named sets with data set "dataset "short""` | `tests/DatasetTest.php::named sets#short` |

A named set carries its name and is read. A positional one is labelled with its **values**, never
its ordinal, so the ordinal is counted in the order JUnit lists the cases — which is execution
order on both sides. Sabotage-checked: replacing the counter with pest's own label turns 7 matched
tests into 2 reported missing, so the mapping is load-bearing rather than decorative.

**What cannot be keyed is named, not dropped.** ✓ Measured: for a classic PHPUnit class inside a
pest run, pest puts its own *description* where the file goes — `file="Classic
(Tests\Classic)::Plain assertion"` — and its teamcity `locationHint` says the same. There is no
file in it, so no id can be built. Those rows leave the comparison and are printed by name before
any verdict:

```
3 oracle test(s) could not be matched to a Crucible id and are NOT part of what follows
(pest reports a classic PHPUnit class by description, not by file):
  Plain assertion
  ...
No drift: every test the real pest oracle passes, Crucible also passes.
```

Silently omitting them would shrink the suite under comparison without saying so — the same defect
as D-114's vacuous pass, one size down, and the harder one to notice because the number that
shrinks is never printed. Guessing a file for them would be worse: a fabricated key can collide
with a real one.

**Superseded by D-118**: the second phpunit pass is gone, because the run's own event log answers
for those rows without a second process and without the file restriction that capped it. What
survives from here is the candidate-set derivation, the name-what-you-cannot-key contract, and the
exit codes.

**End to end**, a pest 5.1.1 project with Crucible installed alongside: 7 pest-declared tests
matched and compared, 3 classic ones named and excluded, exit 0. The same command a minute earlier
could only say the oracle had run nothing.

---

### D-116: what one engine describes, ask the other — and a partial comparison is not a pass

D-115 left pest's classic-class rows out of the comparison and named them, which was honest and
incomplete. They are recoverable, and the mechanism needs no inference.

✓ Measured: real phpunit refuses a pest **suite** — pest stops it from inside its own TestSuite —
but not a classic **file** handed to it by path:

```
$ vendor/bin/phpunit tests/ClassicTest.php --log-junit …
OK (3 tests, 3 assertions)

<testcase name="testPlainAssertion" file="…/tests/ClassicTest.php" class="Tests\ClassicTest"/>
```

Real path, real method name, real dataset labels — the shape `OracleRun` already keys. So a second
pass over those files answers exactly what the first could not, and ✓ measured the ids line up with
Crucible's own on both sides: `tests/ClassicTest.php::testPlainAssertion`,
`::testWithAProvider#one`.

**The candidate set needs no configuration and no scan.** It is implied by the two runs already
made: every file Crucible reported an id in, minus every file the pest oracle managed to key, minus
anything `PestFileSniffer` calls pest-shaped. The last filter is not tidiness — a pest file in that
list stops the whole pass and would lose the classic tests along with it. Sabotage-checked: drop
the sniffer and the pest file joins the set.

**Reversing pest's prose was the alternative, and it is guessing.** Pest hands over `class` and a
prettified name — `Tests\ClassicTest`, "Plain assertion" — and even with the class resolved to a
file, `testPlainAssertion` has to be reconstructed from English. Ambiguous at the first acronym,
and a fabricated key can collide with a real one. Asking the engine that owns the test costs one
subprocess and invents nothing.

**A verdict over part of a suite is not a pass.** When rows stay unrecovered, "No drift" is still
printed — it is true of what was compared — followed by what was left out, and the command now
exits **1**. The same green for "they agree everywhere" and "they agree about the 70% I could ask
about" is D-114's defect wearing a smaller number. Measured, both branches on the same project:

| crucible.php | oracle | compared | left out | exit |
|---|---|---|---|---|
| with `->phpunitCompatibility()` | pest + 1 phpunit file | **10** | 0 | 0 |
| without it | pest only | 7 | 3 | **1** |

The second row also gets the cause rather than a shrug: Crucible discovered no tests in any file
the oracle could not key, which on a pest project means the classic classes are invisible to it —
so the message names `->phpunitCompatibility()` and why it exists (D-019).

**The exit code is 2, not 1**, and the project's own table said so before this entry did: `1` is
"failures, errors", `2` is "the run could not proceed — a configuration error, no tests found".
Every refusal added across D-114 to D-116 is the second kind, and they had all been returning 1.
The line that matters to a CI job is *the engines disagree* (a finding about the code — fix the
tests) against *I could not ask* (a finding about the setup — fix the configuration), and one exit
code for both makes the distinction unreadable exactly where it is acted on. So: no oracle binary,
no phpunit installed, an oracle that ran nothing, a Crucible that ran nothing, a comparison missing
part of the suite — all 2. Tests missing from a Crucible run that *did* happen stays 1: D-112 is
the proof that it can be a real discovery bug rather than a setup one.

**Why 2 and not 0, since a partial run did produce a verdict.** `1` was never the candidate — it
means "failures, errors", and in this case nothing failed: the engines agreed everywhere they were
asked. The real choice was a hard 2 or an advisory 0, and the case for 0 is not weak. compat-check
is used interactively during a migration, where the message is the product and the exit code is
noise, and the shape that trips this is not exotic — `spatie/schema-org`, an existing benchmark
case, is a pest suite with a classic PHPUnit class in it.

It is decided by the asymmetry of being wrong. A wrong 2 costs a message and one line of config,
and the message names the line. A wrong 0 costs a migration believed to be proven and not proven:
CI green in perpetuity while a third of a suite is never compared, the warning sitting in
scrollback nobody reads. That is D-114's defect exactly, and preventing it is what the command is
for, so the tie does not go to the softer option.

The strongest objection is that with no `--allow-incomplete` escape hatch, an unkeyable test means
a permanently red compat-check. It dissolves on inspection: the second pass recovers those rows
whenever Crucible discovers the files, so a *permanent* remainder means those tests genuinely do
not run under Crucible — a real unmigrated part of the suite. There should be no way to green that
except migrating them or removing them.

The verdict line carries the qualifier rather than following it. "No drift" printed first with a
caveat underneath is what a skim reads as a pass; **"No drift among the 7 test(s) both engines
could be asked about — but 3 could not be, so the suite is not proven"** cannot be misread that
way. The unearned green this command refuses is specifically one that *reads* green.

---

### D-117: on the real project, 3.3% of the suite is comparable, and that is the finding

D-116 was validated on a scratch project of ten tests. Run against `spatie/schema-org` — the real
Pest 5 benchmark case, unmodified, both engines installed and `->phpunitCompatibility()` on — it
reports:

```
1862 oracle test(s) came back without a source file …
No drift among the 64 test(s) both engines could be asked about — but 1862 could not be,
so the suite is not proven.                                                       exit 2, 8m48s
```

**64 of 1926 comparable — 3.3%.** Both engines run all 1926 (pest's 1926 is the manifest baseline;
Crucible's is the coexistence measurement recorded there). What cannot be done is line them up.

**The cause is D-112's file, seen from the other side.** `tests/AnalysisTest.php` declares a classic
PHPUnit `TestCase` whose `#[DataProvider]` yields 1862 cases, and ends with
`uses(AnalysisTest::class);`. Pest runs it and reports every case by *description*
(`References with data set "…/src/Zoo.php"`), so no id can be built. And ✓ measured directly,
phpunit cannot be asked about that file instead:

```
$ vendor/bin/phpunit tests/AnalysisTest.php
Message:  Pest must be run through its own binary.
Location: vendor/pestphp/pest/src/TestSuite.php:72
```

Loading the file executes `uses()`, which is pest's, which stops phpunit before it collects
anything. So the second pass was right to exclude it — the sniffer filter it was given for safety
turns out to be the filter that names the limit.

**The bug this exposed was in the diagnosis, not the mechanism.** The empty retry set printed
"Crucible discovered no tests in any file the oracle could not key … add `->phpunitCompatibility()`"
on a project where that option was already set. Two different situations shared one message. They
now do not, because their remedies are opposites: an empty candidate set is a **setting** to
change; a candidate set emptied by pest-shaped files is a **limit** — neither engine can be asked,
and the way out is splitting the class into a file of its own. Reported with the files named.

**What was declined.** Running phpunit over that file under the vocabulary prelude plus a no-op
`uses()` would collect the class. It would also mean the oracle no longer running as the project's
own engine runs it, which is the one thing an oracle may not do (D-018). A comparison bought by
altering the incumbent's environment measures the alteration.

**The ceiling turned out to be the mechanism's, not the problem's** — D-118 lifts it to 1926 of
1926 by reading a log this entry had not yet looked at. What stands is the measurement of the file
shape and the rule that a limit and a setting must not share a message.

**What this says about the benchmark.** `spatie/schema-org` found D-112 as a discovery false green,
and now finds the ceiling on compat-check's reach — the same file, both times, for the same reason:
a suite may mix the two dialects inside one file, and an id has to come from somewhere. A
ten-test scratch project could not have shown either.

---

### D-118: the incumbent's own event log, read where JUnit loses the file

D-117 measured a ceiling of 64 comparable tests out of 1926 and called it a structural limit. It
was a limit of the log being read. ✓ Measured: pest passes **PHPUnit's event stream through
untouched**, and there a classic class reports its real class, its real method, and the dataset in
the exact `#name` form Crucible's ids already use:

```
Test Prepared (Tests\ClassicTest::testWithAProvider#one)          ← real, joinable
Test Prepared (P\Tests\ArithmeticTest::__pest_evaluable_it_slugs) ← pest's own, mangled
```

The same test in pest's JUnit is `References with data set "…"` under a description with no file in
it. Two records of one run; each exact about a different half.

**One mechanism, not a fallback.** The two logs come from a SINGLE pest run
(`--log-junit` + `--log-events-text`, ✓ measured to work together) and own disjoint rows, decided
by a property of the row — pest's own tests carry the `__pest_evaluable_` prefix and a synthetic
`P\` class. There is no ordering, no retry, no failure that triggers a second attempt. Each log is
read only where it carries an exact id, and neither where it would need a transformation undone:
reversing `__pest_evaluable_it_slugs` cannot tell "it slugs" from "it_slugs", and JUnit holds those
files exactly, so they stay JUnit's.

**The class-to-file link was already built.** `ClassLocator::classesIn()` — a token scan, no
loading — over the candidate files, which the two runs already imply: every file Crucible reported
an id in, minus every file the oracle keyed. A class no candidate file declares is returned
*unlocated* and named, never placed by resemblance to a filename; a fabricated key can collide with
a real one.

**Verdicts by precedence, not arrival.** ✓ Measured: PHPUnit emits `Test Passed` and *then*
`Test Considered Risky` for the same test. A reader taking the last line would call a
failed-and-risky test risky, so the rank is fixed — error, fail, risky, skip, pass — and the log's
ordering is left as the log's business.

**Result on the real project**, `spatie/schema-org` unmodified, both engines installed:

| | comparable | left out | exit | time |
|---|---|---|---|---|
| D-116/D-117 (phpunit second pass) | 64 of 1926 | 1862 | 2 | 8m48s |
| this (event log) | **1926 of 1926** | 0 | **0** | 8m19s |

Faster as well as complete: a subprocess disappeared. The pest-shaped file that could not be
handed to phpunit — D-112's `AnalysisTest.php` — is read like any other, because reading a log
asks no engine to load anything.

**What was removed.** `OracleRun::outcomesForFiles()`, the phpunit second pass, and the
`PestFileSniffer` guard in the candidate set. That guard existed only to keep a pest file away from
phpunit; with no phpunit pass it guarded nothing, and it was the exact filter that had excluded the
1862. What was kept is the part that was never about the mechanism: name what cannot be keyed,
state the size of a clean verdict, and exit 2 when the comparison could not be performed in full.

---

### D-119: an uncatchable fatal has no exit code and no message of ours

`600142f` recorded a trait collision as parity with the incumbent and it was half wrong. The
verdict matches — the run cannot proceed — but the manner does not:

| | exit | what the user sees |
|---|---|---|
| pest 5.1.1 | **1** | caught in a shutdown handler, reported as `Pest\Exceptions\FatalException` with the collision message, inside normal output |
| Crucible, before | **255** | a raw PHP fatal naming `ComposedTestCase_<md5>` and `eval()'d code`, no run summary at all |

✓ Both measured on the same two-trait file. Reproducing a *verdict* is fidelity; reproducing a
crash where the incumbent reports is not, and the entry that called it parity has been corrected.

**The general rule this settles.** An uncatchable compile fatal cannot be given an exit code or a
message — there is no point at which anything of ours runs. So wherever generated code can fail for
a reason reflection can see, it has to be refused BEFORE `eval()`. That is not a divergence
invented for tidiness: the run still fails, still red. Only the diagnosis changes, from Crucible's
internals to the user's own `uses()` call.

Two such refusals now, both reflection-only:

- **A collision**: two traits declaring the same method, which PHP resolves solely with an
  `insteadof` clause that `uses()` has no way to write. Abstract trait methods are excluded — ✓
  measured, an abstract `greeting()` beside a concrete one composes, because it is a requirement
  rather than a rival declaration.
- **An abstract base whose abstract methods nobody implements**, which would make the composed
  concrete class illegal.

**The exit code is 2**, and discovery failure in general moved to 2 with it. `HELP.md`'s table
reserves 2 for "the run could not proceed — a configuration error"; a suite that could not be
built is exactly that, and 1 ("failures, errors") claims tests ran and disagreed. Pest answers 1
because PHPUnit's exit space has no third state; Crucible has one and D-114 already committed to
using it. Same reasoning, same conclusion, one layer down.

**What the corpus asks now.** Its sweep no longer asks "does this shape survive" but *how it ends*
— composed, refused, or died — and the pinned answer is that **nothing dies**. A corpus that can
only observe what does not kill it proves the wrong thing, which is why the axis still runs in a
subprocess even though nothing fatals there any more.

### D-120: a name is the TestId, so the incumbent refuses a second one

Crucible accepted two tests with the same composed name in one file. `PestRegistry::uniqueName()`
suffixes a repeat, but it is reached only from `autoName()` and from each `table()` row — the names
the engine DERIVES. An explicit description went straight through, so `test('x', …)` twice, or
`check($a, 'x')` and `check($b, 'x')`, filed two tests under one id.

That is the unearned-green shape rather than a tidiness complaint. The id is what the event stream,
the result cache, `--filter`, the impact map and every report key on, so one of the two silently
stands for both and the run still says it passed everything it collected.

✓ Measured against the Pest 5.1.1 oracle, by execution. **The version is the package's, not the
banner's:** `pest --version` prints `5.0.5` from a constant its own release did not bump, while
`composer.lock` and `vendor/composer/installed.json` both say `pestphp/pest v5.1.1`. Every
"5.0.5" in this project came from reading that banner and has been corrected.

| | what happens |
|---|---|
| pest 5.1.1, duplicate description | `Pest\Exceptions\TestAlreadyExist` at COLLECTION, exit 1 — and **nothing in the file runs**, not even the first of the pair |
| Crucible, before | both collected, both run, one TestId between them |

So the incumbent does not disambiguate, it refuses, and it refuses before any test executes. Matched
exactly, message included.

**Uniqueness is on the composed name, not the leaf.** ✓ Same oracle run: `group A → shared`,
`group B → shared`, `shared` and `it shared` all four coexist and pass. So a `describe()` scope
separates two identical leaves, and the `it ` prefix is part of the identity — which is precisely
what `TestCall::name()` already builds, and why the check compares that rather than the description
it was handed.

The refusal lands in `PestRegistry::test()`, the one place `test()`, `it()`, `arch()`, `check()` and
every `table()` row already pass through, against a set of taken names rather than a scan of the
collected calls — the check is per call, and a linear scan would be quadratic over a large file.
`ConfigurationException`, like every other load-time refusal in the registry; Crucible does not
alias pest's exception classes, and D-019's aliasing is a PHPUnit-surface concern.

**Derived names are untouched.** `check()` and `table()` uniquify before they reach `test()`, so
their suffix still lands and never trips the refusal. The two rules divide cleanly: a name the
engine invented gets made unique, a name the user gave gets refused.

**Not re-benchmarked, deliberately.** `spatie/schema-org` passes 1926/1926 under real Pest, and real
Pest refuses duplicates — so that suite provably declares none, and the new refusal cannot fire
there. Re-running it to be told so would cost 25 minutes and settle nothing that is not already
settled.

## 1.0.1 — what the first migration off Packagist found

Two packages moved from PHPUnit to Crucible 1.0.0 as installed from Packagist, a Laravel array-store
driver and its Wire bridge, and hit three defects. None was a named `Quirk` (D-110: there are
none), so each was a divergence nobody had chosen. Each record below comes with a check that
fails on 1.0.0 and passes now.

### D-121: an attribute hook runs before setUp(), because setUp() is a hook at priority 0

`invokeTest()` ran `setUp → #[Before] → #[PreCondition] → test → #[PostCondition] → #[After] →
tearDown`. The incumbent builds each phase as one list: the template method (`setUp`,
`assertPreConditions`, `assertPostConditions`, `tearDown`) starts it at priority 0, each attribute
hook is **prepended** in the before phases and **appended** in the after phases, and one stable sort
puts the highest priority first. So at the default priority a `#[Before]` runs *before* `setUp()` and
an `#[After]` runs *after* `tearDown()`. Crucible had both the wrong way round.

**Why it is not cosmetic.** A trait with a `#[Before]` reset and a class that wires something in
`setUp()` is an ordinary shape. Under the incumbent the reset runs first and the wiring survives;
under 1.0.0 the reset ran second and wiped it. The array-store driver's `RefreshesArrayStore` flush
replaced the connection, so the event dispatcher and transaction manager set in `setUp()` were gone:
three tests failed, one with "Transactions Manager has not been set". `compat-check` did flag the
three, and blamed coupling to PHPUnit internals, when the tests assert Laravel events.

**Three more divergences, found by reading the same list.**

1. **The after phases sort by descending priority too.** `HookPlanner` sorted `#[After]` and
   `#[PostCondition]` ascending, so `#[After(priority: 5)]` ran after `#[After(priority: 0)]`.
2. **Ties.** Prepending reverses discovery order in the before phases: of two tied `#[Before]` hooks,
   the one discovered later runs first. Discovery is the class's own methods, then its traits'.
3. **`assertPreConditions()` and `assertPostConditions()` did not exist.** A test that overrode
   either was never called, and one that marked it `#[\Override]` would not load.

An attribute on a template method is ignored, as the incumbent ignores it, rather than running the
method twice.

**The shape of the fix.** `HookPlan`'s lists now *are* the run order, template methods included,
built by `HookPlanner::merge()` in the incumbent's way. `invokeTest()` walks them and names nothing.
The Pest dialect splits the before list at `setUp`: hooks ahead of it run first, `beforeEach` runs
right after it (real Pest's `setUp()` is what calls `beforeEach`), and `afterEach` runs right before
`tearDown()` for the same reason.

**What did not change.** A throw in the before phase still ends the test without the after phase, as
a throwing `setUp()` always has. The incumbent does run the after phase there; changing that is a
separate decision, because an after hook meeting half-built state can throw again and hide the first
error.

✓ `conformance/fixtures/09-lifecycle/tests/HookOrderTest.php` records one whole lifecycle (priorities
5, 0 and -1, a trait hook, all four template methods) and asserts the sequence. The real `phpunit`
13.3.1 passes it; Crucible 1.0.0 drifts; 1.0.1 conforms. ✓ The driver's three tests, restored to
wiring in `setUp()`, pass.

### D-122: transformException() exists to be overridden

The incumbent's `TestCase` has `protected function transformException(Throwable $t): Throwable`,
and every error it reports passes through it once. Crucible had no such method. Orchestra Testbench
overrides it with `#[\Override]`, so under 1.0.0 **every Testbench test was a fatal error during
discovery**, and the run ended with no verdict.

It now exists, returns its argument, and is applied where the incumbent applies it: to a throwable
from the before phase or the test body that is not a failure, a skip or an incomplete, and not an
exception the test expected. An exception from the after phase is not transformed, matching the
incumbent.

✓ Measured with `orchestra/testbench-core` 11.5 (the package without the meta package's hard
requirement on `phpunit/phpunit`): 1.0.0 fatals on the first file, and 1.0.1 passes all 14 of the
Wire bridge's Testbench tests.

**Found beside it, not fixed here.** With the `orchestra/testbench` meta package, `phpunit/phpunit`
is installed, and with `->phpunitCompatibility()` on, Laravel's `HandleExceptions::flushHandlersState()`
finds the real `PHPUnit\Runner\ErrorHandler` and calls into a configuration registry only the real
runner fills. Each test then errors in `tearDown()`. That is migration mode meeting a framework that
reaches for the real runner's singletons; the drop-in route is to require `testbench-core`.

### D-123: the incumbent's LogicalNot is aliased, and negates a framework's own description

`PhpUnitCompatibility` aliased the constraint base, which covers every framework constraint that
*extends* it. Laravel's `assertDatabaseMissing()` also *constructs* one by name,
`new PHPUnit\Framework\Constraint\LogicalNot(new HasInDatabase(...))`, and failed with "class not
found". It is the only concrete constraint Laravel or Testbench builds by the incumbent's name, so it
is the only alias added: Crucible's `LogicalNot` already implemented it.

**The alias alone was not enough; the message was wrong too.** A framework constraint follows the
incumbent's contract: `failureDescription()` returns the fragment after "Failed asserting that", and
the runner builds the sentence. Crucible's own constraints return the whole sentence. So under 1.0.0
Laravel's own `assertDatabaseHas()` failure lost its "Failed asserting that", and the negation
printed `'widgets' {"name":"this not is not data"}`: the base's toString negation reached into the
JSON.

`Constraint::failureSentence()` now builds the sentence around a description whose method is not
declared in Crucible's namespace. For the same kind of constraint, `LogicalNot` negates that
description's wording and leaves every quoted value alone. Laravel's message is now the incumbent's:
"Failed asserting that a row in the table [widgets] does not match the attributes {...}". Crucible's
own constraints are unchanged.

✓ `tests/unit/Framework/LifecycleCompatibilityTest.php`: the alias and both messages. ✓ The Wire
bridge's `assertDatabaseMissing()`, restored, passes.

### D-124: an isolated test's STDERR is the test's, not the worker's

A test that runs in its own process (`#[RunInSeparateProcess]`, `#[RunTestsInSeparateProcesses]`,
`#[RunClassInSeparateProcess]`, `--process-isolation`) and wrote to STDERR lost the output: the
worker's STDERR was drained into a 2 KB tail kept only for the crash message, and a clean exit threw
it away. Nothing said so. It surfaced in the server panel, where a debugging probe written from an
isolated MySQL test printed nowhere and had to be written to a file instead.

The incumbent errors such a test with the output as its message. Checked black-box:
`StderrIsolationTest` in fixture `12-separate-process` writes one line to STDERR from an isolated
test; the real `phpunit` 13.3 reports it errored and exits 2, and 1.0.1 reported a pass and exit 0.

**The rule now.** The supervisor keeps what a worker writes to STDERR as pending text, and reads it at
two points on the worker's stream. At a `test:start` it hands anything pending to the console: that is
the bootstrap or a startup notice, and it belongs to no test. At a `test:finish` from an isolated
unit, what arrived since the start is that test's. If it is not empty, the test errors with the text as
its message, and its own failure, if any, is kept beneath. A pipe is written in order, so everything
the test wrote is in the pipe before its `test:finish` line is. Output before the first test is
forwarded rather than charged to it, because a coverage driver's "already loaded" notice would
otherwise error every isolated test of a coverage run.

A `--parallel` worker is only a share of the run, not a request for a separate process, so its tests
keep the in-process rule: their STDERR reaches the console verbatim, and no verdict changes. A crashed
worker's tail still rides in the reason of the tests it lost.

✓ Fixture `12-separate-process` conforms again, 7 tests, exit 2.

### D-125: the folded JS suite is a suite, and selections say which language they mean

`--filter` did not reach the `->vitest()` suite (D-079). A PHP name filter narrowed the PHP tests and
left all the JS tests running, so arming one PHP test waited on the whole JS suite (the server panel:
`--filter FailureCode` ran 8 PHP tests and 290 JS ones). And there was no way to run the PHP tests
alone, since `--testsuite` and `--exclude-testsuite` did not know the JS suite existed.

Two ways were proposed: pass the filter through as `vitest -t`, or leave the JS suite out under a PHP
selection and let `--testsuite` name it. Pass-through alone still starts Vitest, which collects every
test file to match names against, and that start is most of the cost. Leaving the suite out alone
loses a way to filter JS tests. Both are taken, split by whether the JS suite was named:

- `VitestSuite` carries a name, `vitest` unless `->vitest(..., name:)` says otherwise.
  `--testsuite` accepts it, `--exclude-testsuite` drops it, and `--list-suites` lists it.
- Without `--testsuite`, an option that selects PHP tests only (`--filter`, `--group`, `--covers`,
  `--uses`, `--requires-ext`, the id and file lists, `--todos`) leaves the JS suite out and prints a line
  naming the suite and the option. Exclusions (`--exclude-group`, `--exclude-filter`, `--no-wip`) do
  not, because they select nothing.
- With the suite named, `--filter` goes through as `--testNamePattern`. A substring filter keeps its
  meaning exactly: escaped, with `*` still a wildcard, and each letter matched in either case, since a
  pattern on Vitest's command line takes no flags. A regular expression passes its body. Vitest reports
  the tests the pattern left out as skipped, so a skip the filter does not match is dropped; an
  `it.skip` the filter names stays skipped.

Impact selection narrows only the suites the selection kept. A run whose only selected suite is JS runs
it alone, and no longer answers "No tests found".

✓ `tests/unit/Vitest/VitestRunnerTest.php` runs a stand-in binary that records its argv. ✓ Measured on a
mixed project with a real Vitest: 5 tests unfiltered; `--filter Adds` 1 test with the note;
`--testsuite vitest` 3; `--testsuite vitest --filter cart` 2; `--exclude-testsuite vitest` 2.

### D-126: the console does not print OK over a failed check

The console reporter printed OK when no test was a problem, whatever the run-scoped checks said: a
failing `CommandGate` or `Check` showed "OK" and then "Checks: 1 run, 1 failed", and the run exited 1.
The reporter now counts the `CheckFinished` events that did not pass (they land inside the run bracket,
before `run:finish`) and prints OK only when there are none. The summary line and the exit code are
unchanged.

### D-127: the extension is declared to phpstan/extension-installer

The PHPStan extension (D-049) needed an include line, written by hand or by `crucible phpstan-init`.
PHPStan's ecosystem wires extensions through `phpstan/extension-installer`, which reads a package's
`extra.phpstan.includes`. Crucible now declares `phpstan/extension.neon` there, and a project using the
installer gets the extension with nothing written. That is also what PHPStan's own list of extensions
expects of an entry.

Read from the installer's source (1.4.3): a package counts when its type is `phpstan-extension` **or**
it has `extra.phpstan`, so Crucible stays a `library`. A project can opt out through the installer's
`ignore` list.

**The one hazard, and the answer to it.** PHPStan refuses a file included twice ("This file is
included multiple times"), measured. A project with the installer that also has the include line
therefore stops analysing on upgrade. `phpstan-init` checks for the installer (and its `ignore` list).
With it installed, it writes no include into a new `phpstan.neon`, answers "nothing to add" for an
existing one, and when the line is already there it prints the line to remove and exits 1. Projects
without the installer are unaffected.

✓ `tests/unit/CLI/PhpstanNeonTest.php`: the declaration, and a fresh file without the include.

### Declined: the acknowledgment ledger through DuplicationCheck

Proposed in the server panel's doc 14: pass phpcpd-next's acknowledgment ledger through `DuplicationCheck`, so the
server panel could gate on `maxClones: 0` and keep the clones its item 24 chose to leave. Researched
before deciding, because a ledger that changes a verdict is a policy, not plumbing.

**What each side already says.**

- phpcpd-next's README states the ledger "is not a baseline in the usual sense": an acknowledged
  finding "is still reported, still counted, and still gates the exit code", because a mechanism that
  made findings disappear would, over a few quarters, turn the report into a record of what nobody had
  got round to acknowledging yet. Its CLI keeps that (`count($clones) > 0 ? 1 : 0`).
- The same README assigns the two tools to two cases: markers "when the duplication is deliberate
  design and the explanation belongs beside the code; the ledger when the honest statement is 'we know,
  not this quarter'". The panel's item 24 says of what it leaves: "The repetition is the design." That
  is the marker case.
- Markers act inside detection, so `Phpcpd::detect()` never returns a marked clone. Measured on the
  released 2.0.0: two copies of a 13-line method are 1 clone; `@phpcpd-ignore-clone` on one side makes it
  0. `DuplicationCheck` with `maxClones: 0` already holds the panel's line, with no change here.

**The SARIF reading.** SARIF 2.1.0 §3.27.23 defines a suppression as a request to exclude a result
"from result lists, bug counts, etc.", with `kind` `inSource` (a marker) or `external` (a store), and
`status` `accepted`, `underReview` or `rejected`. A phpcpd-next marker is an `inSource` suppression. An
acknowledgment is *not* an `external` one: the ledger refuses exactly the exclusion from counts that
defines a suppression. What it matches is §3.27.24's `baselineState`, a result's state against an
earlier run, found by fingerprint: an acknowledged clone is `unchanged`, an unlisted one `new`, a stale
entry `absent`. The ledger's content hash is such a fingerprint, and editing a copy makes the clone
`new` again, which is the ledger's self-expiry.

**Why the answer is still no, and what would change it.** The other ecosystems gate differently.
PHPStan's baseline removes the errors from the report and the exit code (measured: a baselined project
reports no files and exits 0) and reports an entry nothing matches. ESLint's bulk suppressions leave the
exit code to the violations not suppressed, and fail on unused ones. Both are ratchets: no new debt.
phpcpd-next chose the other way, on purpose. Crucible wraps each tool with the tool's own notion of what
counts. A check that
gated on `baselineState` `new` alone would be a ratchet phpcpd-next itself refuses, so the decision
belongs to phpcpd-next (for instance a gate on new findings, and a headless way to pass the ledger,
announced through `Phpcpd::supports()`). The check follows it when it exists; until then it reads only
`detect()`, the one surface phpcpd-next offers an embedder.

### D-128: the extension, held against the incumbents' — probe 30, and parity

A migrated suite brings its analysis with it. A PHPUnit suite had phpstan-phpunit; a Pest suite had
pest-plugin-phpstan (5.2.1, 2026-09-06), which types `expect()` generically, narrows the chain,
types `$this`, and ships fourteen rules. Both are written against their own framework's classes, so
on Crucible a suite loses them. Crucible's extension had no oracle: the engine is proven against the
real PHPUnit (conformance), the extension against nothing.

**Probe 30** is probe 28's method applied to types. One fixture, written in PHPUnit's namespace and
Pest's globals — the suite a migration brings — is analysed twice: with the incumbent stack
(`pest-oracle`, now also holding phpstan, phpstan-phpunit and pest-plugin-phpstan) and with
Crucible's. Each `dumpType(...); // label` line is a row of a recorded table (`record.php`,
written by `regenerate.php` from the live incumbent), keyed by label so an edit never reshuffles it.
Check 1, Crucible against the record, runs in the suite (`ExtensionParityTest`); check 2, the record
against the live incumbent, runs when the oracle is installed. Every difference is absent or named
in `divergences.php`. Both forms are swept: every assertion beside its negation, every matcher
beside `->not`.

The first run measured the backlog: 143 lines, 31 differing — 22 the Pest chain (Crucible's
`->value` was `mixed` everywhere), 9 three PHPUnit assertions in three spellings.

- **The three assertions**: `assertArrayHasKey` (array_key_exists, or an ArrayAccess),
  `assertObjectHasProperty` (property_exists), `assertContainsOnlyInstancesOf` (the haystack is its
  own filter by `instanceof`) — translations into conditions PHPStan already reads, D-049's rule,
  with the negations beside them.
- **The chain is generic**: `Expectation<TValue>`; `expect()` is typed by
  `ExpectFunctionReturnTypeExtension`, not `@template` — an argument that is already an error (an
  undefined method's result) left the template unresolved and reported a second error on the same
  line, 53 of them on spatie/laravel-data's suite. `ExpectationChainReturnTypeExtension` carries the
  type through every step that returns the chain (helpers returning a constraint or the items keep
  their declared types), narrows it for the type matchers, and narrows nothing after `->not`, as the
  incumbent does. `toBeList()` gives a list of the value's elements rather than intersecting: on a
  string-keyed array the intersection is never, though the empty array is a list — the incumbents
  disagree there (phpstan-phpunit answers never, pest-plugin-phpstan `list`), and the sound answer
  is the Pest plugin's.
- **The dialect's own globals are opt-in**: `check()`, `property()` and `table()` — the three names
  real Pest does not define — moved to `crucible-functions.php`, scanned by
  `phpstan/crucible-dialect.neon`, not by `extension.neon`. A project's own global `check()`
  resolved to Crucible's signature depending on which files were in the run: 373 false
  `arguments.count` errors on sashimi's tests with the full extension, 0 without the scan, 0 after
  the split. Now that the installer loads the extension for every project (D-127), a common word
  scanned unconditionally could not stay. `phpstan-init` adds the dialect include when a suite holds
  `*.crucible.php` files; `lint-inline` skips its include when the installer loads the extension.

**Gate, measured before it shipped**: the generic adds no error on real suites — Crucible's own
analysis at max is clean, spatie/laravel-data's tests at level 6 report 857 errors before and after,
none new, none gone. ✓ Probe 30: 143 of 143, the live incumbent unmoved.

### D-129: the subject narrows, and data rows are typed

**The subject.** After the statement `expect($x)->toBeString();` the analyser knows `$x` is a string,
as after `assertIsString($x)`. The incumbent narrows only the chain. Sound because an expectation
that fails throws (no soft mode exists); the negated form narrows too (`->not->toBeNull()` removes
null), which the incumbent does not do even for the chain. The chain is read back from the
statement's last call to `expect(...)`; any step that changes the subject (`and()`, `json()`,
`->each`, a higher-order member, a method forwarded to the value) narrows nothing. The subject gets
exactly the chain's computed type — one service, `ExpectationNarrowing`, for both — overwritten
rather than intersected, so the two cannot disagree. Probe 30 names the 20 lines where Crucible now
knows more than the incumbent; the chain lines all still agree. Gate: laravel-data 857 → 857.

**Data rows.** A row the test cannot take is wrong at the keyboard, not at the first run — D-067's
principle for generators, applied to every form a row is written in: inline Pest `->with([...])`,
`#[TestWith]`, `#[TestWithJson]`, `#[Check(args:, returns:)]` (the claimed return against the
declared return type too), and `table()` (its subject's signature, as PHPStan resolves it). One
helper, `DataRows`, reports only what fails at run time: a missing required argument, a value its
parameter definitely cannot accept. A value it might accept, an extra value, a closure value (Pest
calls it) are left alone; several `->with()` calls (a Cartesian product), named datasets and
`#[DataProvider]` are not read yet. The Pest rule reads the whole statement: one `->with()` cannot
see the one it sits inside, which is how the first cut reported the Cartesian test in Crucible's
own suite.

✓ Seeded fixtures, one mistake of every kind beside rows that are right: every flagged row errors
when run, every unflagged row passes (`DataRowRulesTest`). ✓ Zero findings on laravel-data's suite
and Crucible's own — both green, so any finding would have been false.

### D-130: type tests

Vitest's typecheck mode, for PHP: files named `*.types.php` under `->typeTests('tests/Types')` are
analysed by PHPStan once — through the project's own phpstan.neon when there is one — and every
assertion in them is a test in the run:

- `assertType('list<int>', $value)` passes when PHPStan reports nothing on its line, fails on
  `phpstan.type`, errors on anything else there;
- a line ending `// crucible-type-error <identifier>` passes when PHPStan reports that identifier on
  it, and fails when the line analyses clean or reports something else — the negative form, for
  library and extension authors;
- errors on no assertion's line are the file's, one errored entry, never lost.

A folded suite, like Vitest's (D-079): named `types`, selected by `--testsuite`/`--exclude-testsuite`
(D-125's selection, now generic over both kinds), left out with a note under a PHP-only selection,
and under `--changed` run only when a changed file is PHP or a `.neon`. With no project
configuration, a written one at a stable path in the cache directory keeps PHPStan's result cache
useful. The analysis's time is shared across its tests so the tree adds up. Measured: a PHPStan
start costs seconds on this machine (about 7.7 s for one small file, xdebug loaded), which is the
floor of a run that includes the suite — `--exclude-testsuite types` keeps an edit loop fast.

✓ `TypeTestRunnerTest` through the real phpstan; ✓ five command-line snapshots (D-135).

### D-131: one shape, two meanings

`expect($data)->toMatchShape('array{id: positive-int, tags: list<string>}')` and
`assertMatchesShape($shape, $value)` state a type once for two readers. At run time the value is
checked, and a failure names the first place it does not fit — `$.tags[1]: expected string, got 7`.
For the analyser the value is narrowed to that type afterwards: the subject, the chain's value, and
the asserted variable, through PHPStan's own `TypeStringResolver` (`@api`).

The run-time reading is Crucible's own (`TypeParser`, `TypeExpression`), not
`phpstan/phpdoc-parser`: the assertion runs in any test run, and a run-time check cannot depend on a
development tool being installed. The subset is documented on `TypeExpression`; a string it cannot
read is refused with its position. Class names are read as fully qualified on both sides, as a
string cannot see `use` statements.

Where PHPStan's reading was measured it is followed: **shapes are open** — PHPStan 2.2 at level max
accepts `['id' => 1, 'extra' => 2]` for `array{id: int}` and an extra element for `array{int,
string}` — and set membership is the semantics, not call-site acceptance (`1` is not a float,
though PHP widens it at a call). ✓ `TypeExpressionAgreementTest`: 81 values over the grammar, each
narrowed and dumped by the real phpstan, the run-time reading agreeing on all 81. The oracle's own
reading needed two corrections, both recorded in the test: a generic like `ArrayObject<*NEVER*,
*NEVER*>` is an empty object, not no room; a never kept on one element of a constant array
(`list{1, *NEVER*}`) is no room.

**Gate, open.** The claim was that real JSON/DTO tests read better and catch more. laravel-data's
toArray() tests assert exact values, which toMatchArray already does well; a shape assertion earns
its place where the structure is fixed and the values vary (identifiers, timestamps, API payloads)
— the server panel's RFC 9457 responses are the sample to measure, and it is not on this machine.

### D-132: data from types

`Gen::of('array{id: positive-int, email: non-empty-string, tags: list<string>, nick?: string}')`
draws from the same grammar toMatchShape() reads, compiled onto Gen's existing combinators:
ranges are `int(min, max)`, nullable is `oneOf`, `list<T>` is `listOf`. New: `Gen::shape()` (an
optional key's presence is a choice drawn before its value, 0 for absent, so shrinking drops
optional keys first) and a minimum length on `Gen::string()` and `Gen::listOf()` for the non-empty
types, drawn directly rather than filtered. A type there is no sound way to draw — a class, a
callable, a resource, an iterable — is refused naming it. For the analyser, `Gen::of('<type>')` is
a `Gen<type>`, so a property closure receives the type, and D-067's parameter rule checks it.

One PHP fact the generator had to learn: `array<string, V>` cannot hold the key `'1'` — PHP makes it
the integer 1. The first cut drew such keys; 30 of 300 draws did not fit their own type. A pair
whose key would change type is skipped.

✓ `GenOfTest`: 200 draws for each of seven types, every one fitting its type by D-131's reading;
a failing property over a shape shrinks to `['id' => 50]` — the boundary, and the optional key gone.

### D-133: a Pest file is a class — its lifecycle, and discovery that finishes

Found running spatie/laravel-data (the benchmark's Pest case) on 1.0.1, whose discovery did not
finish.

- **Discovery.** With the real PHPUnit installed — here brought by spatie/phpunit-snapshot-
  assertions — `RealPhpUnitAttributes::candidates()` globbed `src/Attributes/*.php` once per method
  it was asked about, and `HookPlanner::forClass()` asked about every method of a test class once
  per test: for Pest tests over Laravel's TestCase, hundreds of thousands of globs. Both answers
  are fixed for the run; both are memoised (the plan per class name). ✓ Discovery of the 1,338
  tests: killed unfinished after 60 s and 14 CPU-minutes before; 0.82 s after.
- **The lifecycle.** Real Pest generates a class per file extending its `uses()` class, and the
  runner calls its `setUpBeforeClass()` and `tearDownAfterClass()` around the file. Crucible called
  neither for Pest files. Orchestra Testbench — the base class of every Laravel package's suite —
  gathers per-test state in a static array and clears it only in `tearDownAfterClass()`; never
  cleared, each test's tearDown grouped an ever longer array, and the run slowed quadratically. The
  order, measured on the real Pest 5: `setUpBeforeClass`, `beforeAll`, the tests, `afterAll`,
  `tearDownAfterClass` — Crucible now calls the same. ✓ `PestClassLifecycleTest` (failed before);
  ✓ laravel-data on Crucible: 1,338 tests, 1,324 passed, 11 skipped, 3 incomplete, exit 0 in 226 s —
  the benchmark's recorded result, on a checkout with its snapshot files pristine (two runs that
  left snapshots behind showed 1,326: spatie's snapshot assertions mark a test incomplete when they
  create its snapshot).
- **A dataset that throws** while it is built now fails its own test, as in real Pest (measured:
  the test fails, the suite continues, exit 2), where it was an uncaught fatal for the whole run.
  Found by TypePHP (D-136).

### D-134: declared equivalent mutants, and a mutator for shapes

**Equivalent mutants.** A mutant no test can kill — an operator whose change cannot be observed —
had no way to be declared, so it stayed in the score as noise. The notation is harvested from
phpcpd-next, which uses it to say a clone is deliberate: `@crucible-equivalent <reason>` on the
declaration that follows, a `// crucible-equivalent-start <reason>` … `-end` region, a
`// crucible-equivalent-line <reason>`. A reason is required; a marker without one declares nothing
and is reported (`STALE`), as is a marker that covers no mutant. A declared mutant is generated,
never run, listed with its reason under its own heading and counted — phpcpd-next's "demote, never
hide" — and it leaves the score's denominator, because no test could ever kill it.

**The shape mutator.** `ShapeMutator` drops one string-keyed element from a returned array literal
— `return ['id' => …, 'email' => …]` without 'email' — so a survivor says no test checks that key is
there: the gap a DTO export or an API payload keeps quietly. toMatchShape() is what kills it. A
mutation may now stand for a span of tokens, not only one. In the default catalog.

Not built, with the reason: value-swap mutators (a value replaced by another of its declared type)
need types the token stream does not have; discarding mutants PHPStan rejects before running them
(the Trivial Compiler Equivalence idea) waits for mutators that produce type-invalid code — the
operator mutators almost never do.

✓ `EquivalentMarkersTest`, `ShapeMutatorTest` (every mutant still parses, `TOKEN_PARSE`).

### D-135: the command line, held still

Conformance proves Crucible's outcomes against PHPUnit; nothing held Crucible's own command line —
its notes, its early exits, what it prints and in which order. `conformance/cli/` runs 40 cases
through the real binary, each on a fresh copy of a fixture project, and compares stdout, stderr and
the exit code with a recorded snapshot (`composer conformance:cli`, `--update` to re-record, the
diff read). Normalised: durations, timing bars, the paths of the project copy and of Crucible, and
the run tree's sibling order — FoldingTree orders siblings by time, and a fixture's tests all take
next to nothing, so the order is noise; each node's children are compared as a set, each subtree
kept under its parent. ✓ Snapshots unchanged with a test made slower on purpose, which flips the
real order.

What it found first: a `crucible.php` that throws ended the run as an uncaught fatal with a stack
trace, from every command that loads it. The Loader now reports it — `crucible.php could not be
loaded: RuntimeException: … (line 5)`, exit 1. ✓ `LoaderTest` (failed before).

### D-136: PHPDoc at run time — measured, and nothing built

The research note's largest idea was checking a project's PHPDoc types against the real values its
tests pass (typeguard's pytest plugin, for PHP). TypePHP already does it — Composer-autoloader
instrumentation of parameter, return and property PHPDoc types, throwing on a violation — so the
plan was to orchestrate it, and to measure before deciding what, if anything, Crucible adds.

Measured on spatie/laravel-data (a DTO library, PHPDoc-typed throughout) with TypePHP 0.10.9
(released 2026-09-24):

- **Calls from the tests checked** (its default `include`): the run stopped at discovery. TypePHP
  flagged `Exists::__construct(where: ['fake'])` in the suite's own dataset — a negative test that
  passes an invalid value on purpose, to assert the library's `InvalidArgumentException` later. The
  PHPDoc is right and the test is right; enforcing contracts on calls a test makes breaks exactly the
  tests that exercise error paths.
- **Only the library's own calls checked** (`include: ['src/**']`): the run could not start.
  TypePHP's rewrite of `src/Support/DataContainer.php` does not compile ("Cannot use isset() on the
  result of an expression") — the original line, `isset(static::$instance)`, is valid.

So Crucible adds nothing now: no switch, no recipe. Two lessons are kept: a run-time contract check
belongs on the code under test, never on the calls a test makes; and the value of the idea stands —
the first run found a real boundary where a declared type and a real value disagree.

What the measurement did change: the dataset that threw at discovery was an uncaught fatal for the
whole run, and is now its own test's failure (D-133).


### D-137: one run, one reading — the revision that removed the second copies

D-124..D-136 each added a feature; read together, several did the same thing in more than one
place, and some of those copies disagreed. This revision took each concept to one implementation.
Where one of the copies was better, the others were raised to it.

**Running PHPStan.** Four places located the binary, decided the extension include, spawned the
process and read the JSON. One of them was lint-inline, and it read stdout then stderr from two
pipes: the order that blocks once a child fills the pipe nobody is reading. The five tests that
prove the extension had copies too, with the same two-pipe read. Now there is one path.
`Phpstan::analyse()` writes output to files and reads the report once. `PhpstanNeon::analysis()`
renders the configuration Crucible writes for itself. The tests reach both through one helper. The
same measure removed a second divergence. PHPStan can report an error in no file: a broken include,
an unreadable path. lint-inline dropped such errors and printed "clean"; type tests made them an
error. Both now report them.

**Narrowing.** The Pest matchers carried their own type table beside `AssertConditions` (D-049), so
`toBeString()` and `assertIsString()` could answer differently about the same guarantee. Each type
matcher is now its assertion, translated by `AssertConditions`. The chain's value and the variable
passed to `expect()` go through the same function. `toBeList()` was the better copy: a list of the
value's elements, sound on an array type that can be empty. So `assertIsList()` now reads that way
too. phpstan-phpunit's answer there is `*NEVER*`, which is unsound because the empty array is both,
and probe 30 records it as a named divergence. `toBeArray()` now answers PHPStan's own `is_array()`
reading, `array<mixed, mixed>`, the same as phpstan-phpunit's `assertIsArray()`. pest-plugin-phpstan's
`array<int|string, mixed>` is recorded beside it. Where the two incumbents disagree with each
other, Crucible gives both dialects one answer. The two assert extensions had identical bodies and
now call one method. `assertMatchesShape()` intersects with the value, as `toMatchShape()` does,
instead of replacing it.

**Folding results in.** The Vitest runner and the type-test runner each emitted, counted, and
reported a suite that could not run on their own. `FoldIn` does it once, and `RunSummary::of()`
turns an outcome into a tally.

**Harvested from PHPStan's own test harness** (`TypeInferenceTestCase`, `FileAssertRule`). Type
tests read the whole assertion family: `assertNativeType()`, `assertSuperType()` and
`assertVariableCertainty()` were not tests, and a mismatch on one landed in the file's catch-all
entry. A `*.types.php` file with no assertion is now the file's own error, because a type test that
tests nothing is not a pass. Taken from the harness's rules, not its code: it runs PHPStan inside a
PHPUnit process, and type tests run it as a child.

**Smaller copies.** The conformance scripts share `run_process()`, which moved out of `run.php`, so
the CLI harness no longer reads two pipes either. Probe 30 analyses through `Phpstan::analyse()`.
TypeTestRunner skips tokens with `PhpToken::isIgnorable()`, as ShapeMutator does. `TypeExpression`
types each part by kind (`$name`, `$literal`, `$min`/`$max`) instead of one `mixed` value, built
through named constructors.

**Imports, read as PHP reads them.** The same revision measured `UsesResolver` (D-050, D-067): a
group import (`use Tests\{TestCase, Other};`) or a comma list (`use A, B;`) fell through to the
namespace prefix, so a Pest file that imported its base class that way gave every closure a `$this`
of a class that does not exist. Its import reader now follows the grammar: groups, lists, aliases
inside groups, and `function`/`const` entries skipped inside a group as they are outside one.
PHP-Parser's name resolver was the first choice and was measured out. PHPStan exposes only its own
namespace to other processes, so the resolver could then be tested only by spawning PHPStan.

**One vocabulary for the chain's steps.** The chain's value and the variable each kept a list of the
steps that change the subject, and the lists had drifted: one said `each`, the other did not. Under
that split, `expect($list)->each()->toBeString()` typed the chain `string` and a second matcher
after `->each` typed it `*NEVER*`, while the variable threw away what `toBeArray()` had proved
before the spread. `ExpectationSteps` now says what each step does to the matchers after it:
`and()` and `json()` start another subject; `->each` and `each()` without a callback spread over the
items, to the end of the statement; `each($callback)`, `when()`, `unless()` and `sequence()` keep
the value. Both extensions read it, and a step that ends the reading keeps what came before it.
Probe 30 records the one row where that says more than the incumbent (`pest.andAborts.subject`).

**Measured with Crucible's own mutation testing.** The new code that runs in process was
mutation-tested: 74.4% of 371 covered mutants were killed. The survivors were tests that ran a line
without checking it, and three were defects:
- A colon before a marker's reason lost the reason in the declaration form and hid the marker in
  the line form, although `reason()` was written to strip that colon.
- `Gen::of('non-empty-array<numeric-string, int>')` drew `[]`: every key it drew was one PHP
  rewrites to an int, so it was dropped. A non-empty type now draws again, and is refused when no
  array can fill it.
- A one-member union branch in `TypeExpression` was dead code, because the parser never builds one.
  The branch is gone.

The re-run raised the score to 84.5%, and its survivors found a fourth defect, in the mutation
runner itself. `EquivalentMarkers` still had 26 survivors that the exact-ranges test plainly kills:
applied by hand, the test fails; run by `crucible mutate`, it passed. The cold worker arms the
mutated class's autoloader before discovery, and the loader was never asked for the class. Discovery
had already `require`d the file, because the inline dialect's pre-filter took any `@crucible`
substring for a doctest, and the file's own docblock says `@crucible-equivalent`. A loaded class
cannot be replaced, so every mutant of any file that declares an equivalent mutant read as escaped:
the feature defeated the score it was built to clean. The pre-filter now uses the doctest grammar,
one pattern (`InlineBuilder::DOCTEST`) that the builder and the PHPStan shadow also read, where
there had been three copies.

The rest became tests of the edges the survivors named: range bounds, quoted and integer shape keys,
the exact span `ShapeMutator` drops, and the refusal position.

**The gates finish.** `composer lint` passed its 300-second Composer limit on the full tree:
Pint costs about 0.8 s a file here. It now runs in parallel against a cache (`.pint.cache`),
without the limit: 40 s cold, 7 s warm. The fixture trees whose line numbers are the expected
output (probe 30's fixture, the CLI projects) are excluded, like the arch fixture. Rector now covers
the conformance code that phpstan.neon analyses. Its one wrong suggestion there, `isset` to
`property_exists` on a `SimpleXMLElement`, never applied, and one rewrite it produced did not parse
(`'\is_string'` became `\\is_string(...)`); both were caught by running the scripts.
`composer analyse:matchers` had the same limit and a 656-second run, so it could not finish through
Composer either; it and `analyse:arch` now run without the limit. Its first complete run found a
test that depends on speed: `MapViewTest` read "the last write" as the turned page. That is true
only while the redraw throttle swallows every frame after the turn, and under coverage it does not.
The test now asserts the latest range drawn, which holds at any speed.

**The reports people read, and Crucible's own.** The logo comes from one place, `Version::logo()`
(`assets/crucible.svg`, which ships), and appears only where a person reads the report: the HTML
coverage pages and the testdox page, embedded so each stays one file, and the PDF, beside the title.
The machine formats stay exactly as the incumbents' writers make them. `PdfRenderer` draws a
masthead only when it is given one, so its layout tests still test layout. The PDF's title line holds
the logo, the title and the verdict badge, centred on one height, with the byline beneath it as a
quiet paragraph. The testdox page shows the same verdict, decided by the same `Badge::forRun()`.
Rendering them found two defects:
- `PdfPrimitives::line()`, documented as writing "one flowing line", never flowed: a long
  paragraph ran off the page. It now wraps through the `flow()` the report already had.
- `--log-pdf` and `--report pdf:…` wrote into one map keyed by format, so one path was dropped
  without a word. Two paths for one format are now refused, naming both.

Crucible's own suite is shown on the site in every format, as a sample: `.github/scripts/reports.php`
runs it once, writes each format into one directory and an index from what the run actually
produced, and the maintainer copies it to the site's `reports/latest/`. Built offline on purpose — a
sample needs no deploy key and no release job. The six formats that record absolute file paths
(Clover, OpenClover, Cobertura, Crap4J, coverage XML, coverage PHP) stay out of it — their paths
would be the maintainer's machine — and the index names them and says why.

**Declined, with the reason.**
- EquivalentMarkers keeps its own scan. `SourceAnalysis` knows class methods, and a marker covers
  any declaration, so a split would make two branches.
- Discovery was not taught to exclude `*.types.php`. It already routes by content, and a types file
  is neither a class nor Pest; the test written for that change passed on the old code, so the
  change had no case.
- The agreement test's run-time corpus now travels by `require` (D-107), not `eval()`. The one eval
  point stays `GeneratedCode`.

**Gates.** Full PHPStan went from 21 errors to none. One Rector rewrite was refused: handing a
cause's code on in `Loader`, which catches any `Throwable`. A `PDOException`'s code is a string, and
the rewrite turned a configuration that cannot reach its database into a fatal `TypeError`. The code
is handed on only when it is an int. The test that proves it fails on the rewrite.

---

## Closing the planned record

Every phase scoped at project start — core 0–9 and growth G1–G5 — has landed with its
decision entry above (D-001 through D-039). What shipped is summarized in `RELEASE.md`;
nothing remains deferred — the backlog is empty, and the two items that were **declined**
rather than deferred (the Codeception Cest dialect, RESEARCH.md §5; and the asset→source
mapping the retrigger endpoint replaced, D-083) are recorded where they were decided.
New entries continue as new work lands.
