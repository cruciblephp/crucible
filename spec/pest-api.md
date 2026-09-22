# Spec: Pest Public API (dialect target for G2)

> Behavioral specification for Crucible's `pest` dialect frontend, inventoried from Pest's
> official documentation only (pestphp.com/docs) — clean-room rule: the API surface and
> semantics are the spec; Pest's implementation is never read.
>
> Verified 2026-07-14: current Pest is **v4** (latest v4.7.5, 2026-07-06), MIT-licensed,
> PHP 8.3+, implemented as a wrapper over PHPUnit (its CLI inherits most PHPUnit options —
> which Crucible's phpunit-spec CLI already covers).

## Scope split for Crucible

- **G2 core dialect** (the `*.pest.php` frontend): §1 test definition, §2 expect(),
  §3 hooks/Pest.php, §4 datasets, §7 Pest-specific CLI where engine-relevant.
- **G2+ extended surfaces**, each its own growth decision: §5 arch testing, §6 mutation /
  stress / snapshot / type-coverage / browser. Some overlap with capabilities Crucible plans
  natively (snapshots, sharding, flaky retries) — those are implemented once in the engine
  and exposed through every dialect.

## 1. Test definition

- `test(string $description, Closure $test)` — closure bound to the test-case instance.
- `it(...)` — same, description prefixed "it ".
- `describe(string, Closure)` — nestable grouping; chained `->group()/->skip()/->with()`
  apply to all contained tests.
- `todo()` / `->todo(assignee:, issue:, note:)` — placeholder tests (`pr:` arg unverified).
- `->wip()` / `->done()` — status markers.
- Skipping: `->skip([$bool|Closure, reason])` (closure evaluated after `beforeEach`),
  `->skipLocally()`, `->skipOnCi()`, `->skipOnWindows/Mac/Linux()`,
  `->onlyOnWindows/Mac/Linux()`, `->skipOnPhp('>=8.0.0')`; `beforeEach()->skip()` skips
  the file.
- `->only()` — focus mode (`--ci` ignores focused tests). Exact signature unverified.
- `->group(string ...$groups)`; file/dir-level via `pest()->group(...)->in(...)`.
- `->depends(string ...$tests)` — parents' return values injected as args, in order;
  `it()` parents referenced with the literal `"it "` prefix; failed parent → skip.
- `->covers(Class|function ...)` / top-level `covers()`; `mutates()` for mutation targets.
- Exceptions: `->throws(Class[, $message])`, `->throws($message)`,
  `->throwsIf($cond, ...)`, `->throwsUnless($cond, ...)`, `->throwsNoExceptions()`,
  `->fails(?string)`.
- `->repeat(int $times)`.
- Higher-order tests: omit the closure, chain test-case methods —
  `it('works')->get('/')->assertStatus(200)`; `->expect(fn() => ...)` lazy expectation
  chain; `->defer(fn() => ...)`; composes with `->with()` datasets and hooks
  (`beforeEach()->withoutMiddleware()`).

## 2. expect()

`expect($value)` returns a chainable expectation.

**Matchers** (complete documented list): `toBe`, `toBeAlpha`, `toBeAlphaNumeric`,
`toBeArray`, `toBeBetween`, `toBeBool`, `toBeCallable`, `toBeCamelCase`, `toBeDigits`,
`toBeDirectory`, `toBeEmpty`, `toBeEqual`, `toBeEqualCanonicalizing`, `toBeEqualWithDelta`,
`toBeFalse`, `toBeFalsy`, `toBeFile`, `toBeFloat`, `toBeGreaterThan`,
`toBeGreaterThanOrEqual`, `toBeIn`, `toBeInfinite`, `toBeInstanceOf`, `toBeInt`,
`toBeIterable`, `toBeJson`, `toBeKebabCase`, `toBeLessThan`, `toBeLessThanOrEqual`,
`toBeLowercase`, `toBeNan`, `toBeNull`, `toBeNumeric`, `toBeObject`,
`toBeReadableDirectory`, `toBeReadableFile`, `toBeResource`, `toBeScalar`,
`toBeSnakeCase`, `toBeString`, `toBeStudlyCase`, `toBeTrue`, `toBeTruthy`,
`toBeUppercase`, `toBeUrl`, `toBeUuid`, `toBeWritableDirectory`, `toBeWritableFile`,
`toContain`, `toContainEqual`, `toContainOnlyInstancesOf`, `toEndWith`, `toEqual`,
`toHaveCamelCaseKeys`, `toHaveCount`, `toHaveKey` (dot notation), `toHaveKeys`,
`toHaveKebabCaseKeys`, `toHaveLength`, `toHaveProperties`, `toHaveProperty`,
`toHaveSameSize`, `toHaveSnakeCaseKeys`, `toHaveStudlyCaseKeys`, `toMatch`,
`toMatchArray`, `toMatchConstraint` (accepts a PHPUnit constraint — in Crucible: the shared
constraint engine), `toMatchObject`, `toMatchSnapshot`, `toStartWith`, `toThrow`.

**Pest 5 value matchers**, verified by executing Pest 5.1.1 rather than reading it: over 63
inputs spanning all eight, Crucible and the incumbent agree on 62 and differ on one, below.

- Format, over the value: `toBeEmail`, `toBeDomain`, `toBeHostname`, `toBeIpAddress`,
  `toBeMacAddress`, `toBeSlug`, `toBeUlid`, `toBeHexadecimal`.
- Class shape, over a class-string subject (D-066): `toBeClass`, `toBeIntBackedEnum`,
  `toBeStringBackedEnum`, `toBeInvokable`, `toHaveAttribute`, `toHaveConstructor`,
  `toHaveDestructor`, `toHaveMethods(array $methods)`.

Three names do not mean what they look like, and the readings below are measured, not
inferred:

- **`toBeSlug` is "can be converted to a slug", not "is a slug".** `'run tests'`, `'a@b'`
  and `'---a---'` all pass in the incumbent; `'ß'` and `'日本'` fail, because nothing is
  transliterated and they reduce to nothing. The strict already-in-slug-form reading is
  `toBeKebabCase`, and giving two names one meaning would fail ported suites for no reason.
- **`toBeDomain` is `toBeHostname` plus a dot.** Measured across ten inputs the two differ
  on exactly one, `localhost`. `127.0.0.1` and `example.` are domains to the incumbent, so
  nothing here asks for a plausible top-level domain.
- **`toBeHexadecimal` rejects the `0x` prefix.** `'ff'` and `'FF'` pass, `'0xff'` does not.

**No divergences, and no quirks.** Over 3,344 grid cells — 44 zero-argument matchers across 76
corpus values, in both the positive and the negated form — Crucible answers exactly as Pest 5.1.1
does, with nothing to opt into. `conformance/probes/28-pest-matchers/compare.php` re-proves that
against the installed incumbent on every run and fails the moment a cell moves.

`toBeSlug('0')` **fails**, as it does in the incumbent, and it is worth knowing why rather than
memorising it: the question is whether the REDUCED string is empty, and `empty('0')` is true in
PHP. So `'0'` fails for exactly the reason `'!!!'`, `'---'` and `'   '` fail — all four reduce to
something empty — while `'00'` passes, which is what shows the rule is emptiness and not
zero-ness. `toBeHostname('0')` is the same rule one layer down: `filter_var()` validates `'0'` and
hands it back, and that return is tested for truth.

Crucible once corrected both of those and offered `quirk_falsy_slug` / `quirk_falsy_hostname` to
restore them. That was a mistake on our side, not the incumbent's: those two matchers had their
predicate written out by hand where PHPUnit's `IsEmpty` already existed. Composing the primitive
made the incumbent's answer fall out, and the quirks had nothing left to bridge.

The `Quirk` mechanism remains, with no cases. It is the bar a future divergence has to clear: one
must NAME the quirk that restores the incumbent, and the probe checks the quirk actually does. A
correction nobody can opt out of is a fork, not a fix. Quirks are never a mode — a single
"compatibility" switch becomes a bucket whose contents nobody can audit, and flipping it to
silence one failure silently accepts every other bug inside it.

**`toBeUlid`** takes 26 characters of Crockford base32, uppercase only. No range check on the
first character: the ULID spec restricts it while the timestamp fits 48 bits, and the
incumbent does not enforce that.

**Arch, not value — deferred with the `arch()` entry point.** Executed against a real
namespace, `toBeClasses`, `toBeEnums`, `toBeInterfaces`, `toBeTraits`, `toBeCasedCorrectly`,
`toHaveLineCountLessThan` and `toHaveFileSystemPermissions` all sweep a namespace's source
files and report per file (`Expecting 'src/Sneaky.php' to be enum`). Handed anything else
they match nothing and pass vacuously — `expect('NoSuchThing')->toBeClass()` passes there,
and so does `expect(Plain::class)->toHaveMethods(['imaginary'])`. Crucible's D-066 presets
answer the same questions one class-string at a time and therefore *do* bite on those
inputs; whether Crucible should also offer the namespace-sweeping spelling is the `arch()`
entry-point question.

**`toHaveSuspiciousCharacters` does not exist in Pest 5.1.1** — the expectation is
unregistered and calling it raises "The expectation [toHaveSuspiciousCharacters] does not
exist." It is listed in the Pest 5 notes in error, and nothing implements it here.

**Modifiers**: `->not`, `->and($value)`, `->each()`, `->sequence(...)`,
`->when($cond, $cb)` / `->unless($cond, $cb)`, `->json()`, `->match($subject, $cases)`;
debug: `->dd()`, `->ddWhen()`, `->ddUnless()`, `->ray()`.

**Higher-order expectations**: chain property/key/method access —
`expect($user)->name->toBe('Nuno')`; `->scoped(fn($e) => ...)` for nested objects.

**Extensibility**: `expect()->extend('name', fn(...$args) => /* $this->value */)`;
`expect()->intercept('toBe', Type|Closure, $handler)`;
`expect()->pipe('toBe', fn(Closure $next, ...$args) => ...)`.

## 3. Hooks & Pest.php

- Per-file: `beforeEach`/`afterEach` (every test, `$this` bound), `beforeAll`/`afterAll`
  (once per file, no `$this`); nest inside `describe()`; per-test `->after()`.
- `tests/Pest.php`: `pest()->extend(TestCase::class)`, `pest()->use(Trait::class)`,
  scoped by `->in('Feature', 'Unit/*Glob*.php')`; bare (no `in()`) = file-local;
  `->group(...)` for directories. (`uses()` is the legacy v2 spelling.)
- Global hooks: `pest()->beforeEach/afterEach/beforeAll/afterAll(...)`, chainable with
  `->group()->in()`; global before* run before file-level, global after* run after.
- `pest()->printer()->compact()`.

## 4. Datasets

- Inline `->with([...])`; array-of-arrays for multi-arg; string keys name cases
  (`:dataset` interpolation); associative rows bind to closure params **by name**.
- Shared: `dataset('name', $values)` in `tests/Datasets/*.php`; per-folder scoping.
- Bound: rows as closures, resolved after `beforeEach`; type-hint required.
- Lazy: generator closures.
- Multiple `->with()` = **Cartesian product**.
- `describe()->with([...])`; `->repeat(n)`.

## 5. Architecture testing (extended surface)

`arch()->expect('Namespace')` with `\*`/`\**` wildcards. Presets:
`->preset()->php() / ->security() / ->laravel() / ->strict() / ->relaxed() / ->custom()`;
plugin-registrable via `pest()->preset()`. Expectation families: type
(`toBeClasses/Enums/Interfaces/Traits`, `toBeInt|StringBackedEnums`), inheritance
(`toExtend`, `toExtendNothing`, `toImplement`, `toOnlyImplement`, `toImplementNothing`),
modifiers (`toBeAbstract/Final/Readonly/Invokable/CasedCorrectly`), dependencies
(`toUse`, `toOnlyUse`, `toUseNothing`, `toUseStrictTypes`, `toUseTrait(s)`, `toBeUsed`,
`toBeUsedIn`, `toOnlyBeUsedIn`), quality (`toUseStrictEquality`,
`toHaveLineCountLessThan`, `toHaveMethodsDocumented`, `toHavePropertiesDocumented`,
`toHaveSuspiciousCharacters`), structure (`toHaveMethod(s)`, `toHaveConstructor`,
`toHaveDestructor`, `toHavePublic/Protected/PrivateMethods(Besides)`), naming
(`toHavePrefix/Suffix`), misc (`toHaveAttribute`, `toHaveFileSystemPermissions`).
Scoping: `->ignoring(...)`, `->classes()/enums()/interfaces()/traits()/abstracts()`,
`->extending()`, `->implementing()`, `->using()`; global ignore baseline in a global
`beforeEach`.

## 6. Extended feature surfaces (v3/v4)

- **Mutation**: `--mutate` (+`--parallel`), requires `covers()`/`mutates()`; options
  `--covered-only --class= --ignore= --min= --id= --everything --profile --bail --retry
  --stop-on-uncovered --stop-on-untested --clear-cache --no-cache
  --ignore-min-score-on-zero-mutations`; `@pest-mutate-ignore` line opt-out.
- **Stress** (k6-based plugin): `pest stress <url> --duration= --concurrency=` and a
  programmatic `stress()` chain with latency percentiles/TTFB/etc.
- **Snapshots**: `->toMatchSnapshot()`, stored `tests/.pest/snapshots`,
  `--update-snapshots`, normalize via `pipe()`.
- **Type coverage** (plugin): `--type-coverage --min= --compact --type-coverage-json=`;
  `@pest-ignore-type`.
- **Browser testing** (v4, plugin `pestphp/pest-plugin-browser` + `npm playwright`;
  full page inventoried 2026-07-16 from pestphp.com/docs/browser-testing):
  - **Entry**: `visit('/')` returns a page object; `visit(['/', '/about'])` opens
    several pages at once (array-destructurable, batch assertions on the set);
    `navigate('/other')` moves within the same browser context. Server binds
    `127.0.0.1`; `tests/Browser/Screenshots` is the artifact dir.
  - **Config** (`Pest.php`): `pest()->browser()->inFirefox()/inSafari()` (default
    Chrome), `->timeout(ms)` (element-wait default 5s), `->userAgent(...)`,
    `->withHost('sub.localhost')`. CLI: `--browser firefox|safari`, `--debug`
    (headed + pause at failed test), `--parallel` recommended.
  - **Selector grammar** for every interaction: visible text, CSS (`.btn`, `#id`),
    `@name` = `data-test` attribute.
  - **Environment simulation**: `on()->mobile()/iPhone14Pro()/macbook14()...`,
    `inDarkMode()` (light enforced by default), `geolocation(lat, lng)` (sets the
    browser permission + getCurrentPosition), city presets `from()->losAngeles()`
    (geo+timezone+locale at once), `withLocale('fr-FR')`, `withTimezone(...)`,
    `withUserAgent(...)`, `withHost(...)` per page.
  - **Interactions** (27): `click text attribute keys withKeyDown type typeSlowly
    select append clear radio check uncheck attach press pressAndWaitFor drag hover
    submit value withinFrame resize script content url wait waitForKey`.
  - **Assertions** (~60): title (`assertTitle/Contains`), text (`assertSee/DontSee/
    SeeIn/DontSeeIn/SeeAnythingIn/SeeNothingIn/assertCount`), source/links
    (`assertScript/SourceHas/SourceMissing/SeeLink/DontSeeLink`), form state
    (`assertChecked/NotChecked/Indeterminate/RadioSelected/RadioNotSelected/Selected/
    NotSelected/Value/ValueIsNot`), attributes (`assertAttribute/AttributeMissing/
    AttributeContains/AttributeDoesntContain/AriaAttribute/DataAttribute`), presence
    (`assertVisible/Present/NotPresent/Missing/Enabled/Disabled/ButtonEnabled/
    ButtonDisabled`), URL parts (`assertUrlIs/SchemeIs(Not)/HostIs(Not)/PortIs(Not)/
    PathBeginsWith/PathEndsWith/PathContains/PathIs(Not)/QueryStringHas/Missing/
    FragmentIs/FragmentBeginsWith/FragmentIsNot`), health checks (`assertNoSmoke/
    NoConsoleLogs/NoJavaScriptErrors/NoAccessibilityIssues/assertScreenshotMatches`).
  - **Debugging**: `debug screenshot screenshotElement tinker headed`.
  - **Laravel integration is part of the observable contract**: the docs' example
    composes `Event::fake()`, `RefreshDatabase`, factories, `$this->assertAuthenticated()`
    and `Event::assertDispatched()` around `visit()` — app-side fakes and DB traits
    demonstrably reach the served app ("leveraging the full power of Laravel's
    testing capabilities … while also actually doing browser testing"). Whenever
    Crucible builds this tier, shared app state between test and served requests is a
    spec requirement, not an optional nicety. No mocking-library (Mockery-style)
    doubles appear anywhere on the page; only Laravel's own fakes.
  - **B1 oracle pins** (probed 2026-07-16, gitignored `browser-oracle/`: pest 4.7 +
    pest-plugin-browser 4.3 + playwright npm 1.61.1, Chromium):
    - **Plain (non-Laravel) project**: `visit('/')` starts **no server** — relative
      URLs fail with "Protocol error (Page.navigate): Cannot navigate to invalid
      URL"; `APP_URL` env does not resolve them. Absolute URLs work end-to-end
      (assertSee/assertTitle green against a local `php -S`). The "server binds
      127.0.0.1" behavior is the Laravel driver's.
    - **Architecture (public class inventory, names only)**: an `HttpServer`
      contract with `LaravelHttpServer`/`NullableHttpServer` drivers (shared-state
      serving is a per-framework driver; plain = null); a `PlaywrightServer`
      contract with `PlaywrightNpmServer`/`AlreadyStartedPlaywrightServer` plus a
      PHP-side protocol `Client` with `Page/Locator/Element/JSHandle` — i.e. the
      incumbent itself is a PHP Playwright-protocol client against the npm server,
      validating Crucible's D-A bridge choice. Version discipline is explicit:
      `PlaywrightNotInstalledException`/`PlaywrightOutdatedException`.
    - **Config object** (`pest()->browser()`, reflected): `debug diff headed
      inChrome inFirefox inSafari inDarkMode inLightMode timeout userAgent
      withHost` — no baseUrl; URL resolution is `Support\ComputeUrl` + the server
      driver.
    - **Enums**: BrowserType {CHROME, FIREFOX, SAFARI}; Device — 24 presets
      (DESKTOP, MOBILE, MACBOOK_16/14/AIR, IPHONE_15_PRO/15/14_PRO/SE,
      IPAD_PRO/MINI, PIXEL_8/7/6A, GALAXY_S24_ULTRA/S23/S22/NOTE_20/TAB_S8,
      SURFACE_PRO_9/LAPTOP_5, ONEPLUS_11, XIAOMI_13, HUAWEI_P50); City — 13
      presets (AMSTERDAM BERLIN CHICAGO HOUSTON LONDON LOS_ANGELES MIAMI NEW_YORK
      PARIS TOKYO TORONTO SAN_FRANCISCO SYDNEY); ColorScheme {LIGHT, DARK};
      AccessibilityIssueLevel {Zero..Three}.
    - **Undocumented-on-the-page surfaces to track**: Livewire browser testing
      (`Api\Livewire`, `TestableLivewire`), Laravel-ecosystem cleanup hooks
      (Cleanables: Livewire/Inertia/Ziggy), `OptionNotSupportedInParallelException`
      (some options refuse under `--parallel`).
    - **Crucible design constraint (decided 2026-07-16): default OFF, explicit
      opt-in, zero cost when off.** The browser tier is never active by default —
      not even when the suite contains `visit()` calls. Three deterministic states
      in the typed config:
      1. **Not enabled (the default)**: no npm, no Playwright, no ~150MB browser
         downloads, no startup probe, Node never touched. A browser test in this
         state is a named error pointing at the config line that enables the tier
         — never a silent skip (a test that never runs and never tells is worse
         than an error).
      2. **Enabled** (explicit, e.g. `->browser()` block present/`->enabled(true)`):
         browser tests run; Playwright missing at that point is a named, actionable
         install error (the incumbent's `PlaywrightNotInstalledException` shape:
         exact commands, nothing auto-installed).
      3. **Explicitly disabled** (`->enabled(false)`, e.g. a CI lane without
         browsers): browser tests report as **skipped**, deterministically —
         the intentional counterpart to state 1's error.
      Mirrors the incumbent's plugin split (Pest core doesn't ship the browser
      plugin; installing it is Pest's opt-in) without a second package —
      in-package, gated by explicit config, per the project's one-package decision.
    - **Dialect exposure (decided 2026-07-16): the Pest-4 mapping is the only
      documented grammar.** `visit()` et al. are pest-dialect surface — global
      functions behind the same `function_exists`/dialect-active guards as
      `test()`/`it()` (D-019). No bespoke native browser DSL is designed: a second
      grammar would have no spec to conform against and would parallel-implement
      the same capability. The engine pieces (driver client, page object, server)
      are plain classes, so native TestCases are not locked out — but no native
      spelling is documented or pinned unless a real need surfaces. Mockery needs
      no such decision: its surface is class-based (`Mockery::mock()`) and works
      identically from any frontend by construction.
- **Parallel & sharding**: `--parallel/-p`, `--processes=N`, `--profile`,
  `--shard=1/4` + `--update-shards` (time-balanced via `tests/.pest/shards.json`).
- **Plugins**: traits via `Pest\Plugin::uses()`, namespaced functions, custom
  expectations, arch presets. Internal plugin interfaces undocumented → out of spec.
- **Mocking**: none shipped; docs recommend Mockery.

## 7. Pest-specific CLI

`--init`, `--bail`, `--ci`, `--todos` (+`--assignee=`, `--issue=`), `--notes`,
`--flaky`, `--retry` (failed-first), `--dirty` (uncommitted-git-changes tests only),
`--exclude-filter`, `--compact`, `--profile`, `--parallel`/`--processes`,
`--shard`/`--update-shards`, `--update-snapshots`, `--type-coverage`(+json),
`--mutate` family, `--coverage --min/--exactly/--only-covered`, `--test-directory`,
`pest stress`, `--browser`, `--debug`. Everything else inherited from PHPUnit's CLI.

## Unverified (re-check before implementing)

`todo(pr:)`; `coversNothing()`; exact `->only()` signature; `pest-plugin-laravel`
helper list; the "Profanity" docs page.
