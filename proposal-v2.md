# Crucible v2 — proposal

**Status:** proposal, not a decision. No v2 code lands until a `D-xxx` records the
contract. This document is input for that decision; it is not a roadmap.

## The idea

Crucible is one engine with several dialects (D-008). PHPUnit, Pest, the crucible
dialect and the inline dialect are not compatibility layers. They are different ways to
write the same test: each compiles to the same `TestDefinition`, and they mix in one
run, with one report, one cache and one schedule.

v2 adds what was not there when that design was written:

- **Tests in Markdown.** A code sample in a doc page is a test, in PHP, JavaScript,
  TypeScript or Vue, and the output block under it is its assertion. The docs cannot drift from the code.
- **Markers.** A comment that names a test, in a page or in PHP code. A marker proves
  the test exists and lets it be run; in a page it also shows the result. It is a
  comment, so it costs nothing in production.
- **Binding in `crucible.php`.** Which pages and which code belong together is declared
  in the configuration, never inferred.
- **The stamped page.** A page is its own report: the samples, their results, a
  changelog and the stamp of the last run, in one self-contained file.

- **Orchestrated suites.** A suite that needs real PHPUnit or Pest runs on the real
  binary, and its results join the same run, as Vitest's already do.

v1 is in maintenance and takes fixes only (README). v2 is the next package line, and it
is a major because of one deliberate break, below.

## Own namespace only

v1's drop-in mode stands in for PHPUnit and Mockery: it aliases their class names
(`src/Compat/PhpUnitCompatibility.php`, `MockeryCompatibility.php`) and declares
stand-ins for their classes (`src/Compat/Stubs/`) when the real package is absent (D-019).
That was the proof that one engine runs real suites unchanged: Monolog, phpcpd, a Laravel
app. It is not the direction.

**v2 rule: Crucible defines only its own namespace.**

- No `class_alias` into `PHPUnit\`, `Pest\` or `Mockery\`, and no stand-in classes.
- No global functions standing in for Pest's (`test()`, `it()`, `expect()`): the pest
  dialect's functions live in Crucible's namespace too.
- Nothing written to, or claimed at, a vendor path; no Composer `replace` or `provide`.
- When a suite needs a real package, the real package runs, openly.

A PHPUnit- or Pest-style suite then has two honest paths:

- **Stay on the real runner.** The suite is orchestrated (below). Its tests do not change.
- **Move to the engine.** The phpunit and pest dialects keep their way of writing a test,
  but the test imports Crucible's own names, such as
  `use LucianoPereira\Crucible\PhpUnit\TestCase;` or
  `use function LucianoPereira\Crucible\Pest\{test, expect};`. That is one import per
  file, a mechanical rewrite a migration command can do, in the way
  `crucible compat-check --auto-fix` already rewrites known coupling patterns. The
  dialect stays the same test written PHPUnit's or Pest's way; it no longer carries
  their names.

Mockery is a library, not a runner. It works unchanged in an orchestrated suite; on the
engine, Crucible calls Mockery's public teardown from its own lifecycle hook instead of
aliasing its classes.

1.x keeps drop-in mode, with fixes only.

## Prior art: Hallmark

When Crucible's dialect model was designed, [Hallmark](https://github.com/cruciblephp/hallmark)
did not exist. It now does this by hand, in one PHPUnit test
(`tests/Docs/DocsSamplesTest.php`):

- 55 `php` blocks in the README and `docs/*.md`. Each runs as a plain script in its own
  process.
- 40 `text` blocks. The one under a sample is the expected output; dates are normalized,
  nothing else may move.
- 17 `<!-- not run: ... -->` comments, some naming the test that holds the sample
  instead. That is already a marker, but free text that nothing checks.

v2 makes that pattern a dialect: the same rules, discovered by Crucible, with stable
ids, checked markers and a stamped report.

## What v1 already has

In-file tests already ship as the inline dialect (`MANUAL.md` §3, D-014/D-035):

- `#[Check(args, returns:, throws:)]` — constant input/output rows on a function or
  method, private methods included.
- `@crucible expect(...)` — one-expression doctests in a docblock. They execute.
- `crucible lint-inline` — PHPStan over the doctests through shadow files.

D-014 declined test methods inside production classes, because PHP has no dead-code
elimination. v2 keeps that decision: markers give code a link to its tests without
shipping test code.

v1's drop-in mode does not carry over: see "Own namespace only".

## The pieces

| Piece | Where | What it is | Written by |
|---|---|---|---|
| Inline test | docblock / attribute | `#[Check]` row or `@crucible` doctest (v1) | a person |
| Markdown test | `README.md`, `docs/*.md` | a code block and the `text` block under it | a person |
| Test file | `tests/` | any dialect | a person |
| Orchestrated suite | `tests/` | a suite run by the real PHPUnit, Pest or Vitest binary | a person |
| Marker | a page or a PHP comment | a reference to a test by id | a person; Crucible fills it in a page |
| Binding | `crucible.php` | which pages and code belong together | a person |
| Stamp and changelog | the page | the last run and the history of its outcomes | Crucible |

Crucible writes in exactly one place: marker bodies in a page (and a marker's missing
closing `<!--/-->`, as sigilmd does). It never writes a sample, an expected output, a
PHP file or the configuration.

## Markdown tests

What a person writes, in `docs/handlers.md`:

````md
`FingersCrossedHandler` holds a request's records and writes them only if one reaches
its activation level:

```php test=fingers-crossed
$stream = new StreamHandler('php://stdout');
$stream->setFormatter(new LineFormatter("%level_name%: %message%\n"));
$log = new Logger('app', [new FingersCrossedHandler($stream, activationLevel: Level::Error)]);

$log->debug('cart loaded');
$log->error('card declined');
```

```text
DEBUG: cart loaded
ERROR: card declined
```
````

The rules, taken from what Hallmark already proves:

- **A fenced code block is a test.** It runs through the runner for its language (below).
  No test vocabulary appears in the docs: a reader can copy the sample as it is.
- **Each sample stands alone.** There is no shared setup between samples, as in
  Hallmark: a sample a reader copies must work on its own.
- **The `text` block under it is the assertion.** The sample's output must match it.
  It is written by a person and never generated, so it is a real claim, not a snapshot.
- **Without a `text` block, the claim is that it runs.** No error and nothing on stderr,
  the same smoke claim a bare `#[Check]` makes.
- **Volatile values are normalized.** Dates and times by default. A project declares
  more in `crucible.php` with `->normalize(pattern, placeholder)`. Nothing else may move
  between runs.
- **`<!-- not run: reason -->` skips a block** that is not a program (a signature, a
  config excerpt). The reason is required.
- **Ids.** A block is `docs/handlers.md::line 12` by its line, in the same
  `path::name` shape as every other test id (D-010). A named block,
  ` ```php test=fingers-crossed `, is `docs/handlers.md::fingers-crossed` and keeps its
  id when lines move. Only a named block can be the target of a marker or have its own
  changelog line.
- **PHP samples are type-checked.** `crucible lint-inline` analyses `php` blocks through
  the same shadow files it already uses for doctests.

### Runners by language

The shape is the same in every language: the block, the output block under it, ids,
markers and the stamp. Only what runs the block depends on its fence, the D-008 model of
one engine with many frontends:

| Fence | Runs through |
|---|---|
| `php` | a plain PHP script in its own process, with the project's bootstrap |
| `js`, `ts`, `vue` | the project's Vitest suite, `->vitest(...)` (D-079) |
| any other language | a runner a plugin registers for that language (D-098) |

- **JavaScript is orchestrated, not reimplemented** (D-079). The sample runs inside the
  project's own Vitest setup, so its imports, aliases, `.vue` transforms and Vite config
  work as they do in the app. Its result lands on the same event stream, tally and stamp
  as the PHP samples.
- **Markers cross languages.** A docblock in a `.ts` file can name a PHP sample, and a PHP
  docblock can name a Vue one.
- **Never a silent pass.** A block whose runner is configured but cannot start (Vitest not
  installed, say) is an errored test, never a skip (D-079, D-183). A block in a language
  with no runner is prose, and the stamp counts it on its own (`3 not runnable`), so it
  is visible rather than dropped.

## Orchestrated suites

A suite can run on the engine, in any dialect, or on the real binary that owns it:

```php
->testSuite('unit', 'tests/Unit')                                // the engine, any dialect
->testSuite('feature', 'tests/Feature', runner: Runner::PhpUnit) // the real vendor/bin/phpunit
->testSuite('legacy', 'tests/Legacy', runner: Runner::Pest)      // the real vendor/bin/pest
->vitest('resources/js')                                         // already orchestrated (D-079)
```

This is D-079's rule applied to PHP: **orchestrate, not reimplement**. Crucible spawns the
real binary, reads its TeamCity output, and translates each test onto the one event
stream: the same tally, report, exit code, markers and stamps. TeamCity output is
line-based, streamable and already what IDEs consume, so it is the stable contract, as
Vitest's JSON is; reading it keeps "No XML". Its location hints carry the test's file, so
an orchestrated test gets a normal `path::name` id and a marker can name it.

- **Laravel bound to PHPUnit.** The framework's `TestCase` extends the real PHPUnit one,
  so that suite is orchestrated. Crucible integrates through what it owns, its bootstrap,
  its configuration and `php artisan test` (D-029), never by changing vendor code.
- **What an orchestrated suite gets:** results, the tally, file-level impact selection
  (Crucible passes the selected files), markers and stamps.
- **What it does not get:** the engine's own features, such as global-state isolation,
  flaky classification, per-test coverage edges and Crucible's scheduling. The choice is
  made per suite in the configuration, so nothing degrades silently.
- **Never a silent pass** (D-079, D-183). A configured binary that is missing, or that
  reports no tests, is an errored test, not a green run.

The engine stays the product. D-008 contrasts Crucible with wrapping PHPUnit, and that
still holds: orchestration is how Crucible works with a real runner a suite depends on,
not a replacement for the engine.

## Markers

A marker names a test by its id. The syntax is sigilmd's own `name: key=value` form
(`<!--[[ badge: id=... ]]-->`), not a new one:

```md
<!--[[ test: id=tests/PHPUnit/AssertLogTest.php::testItWarnsOnRetry ]]-->
```

```php
/**
 * Writes the held records once one reaches the activation level.
 *
 * [[ test: id=docs/handlers.md::fingers-crossed ]]
 */
final class FingersCrossedHandler
```

- **It proves the test exists.** A marker naming an id that does not exist is a hard
  error, so a renamed or deleted test cannot leave a dangling claim. Hallmark's
  "`tests/PHPUnit/AssertLogTest.php` holds it" becomes a checked reference.
- **It is checked on every run.** Source discovery already pre-filters `->source()`
  files for `#[Check]` and `@crucible`; it also looks for `[[ test:`, so a PHP marker is
  validated without a separate step.
- **It runs the test.** `crucible --marker src/Handler/FingersCrossedHandler.php` runs
  every test marked in that file, through the normal runner.
- **In a page, it shows the result.** Crucible fills a page marker with the test's
  outcome. A marker in PHP is a reference only and is never written: Crucible does not
  write into source files.

## Binding in `crucible.php`

Neither the page nor the marker names the source it belongs to. The binding is declared
in the configuration, the same "declared, not inferred" rule as `->environmentFile()`
(D-199):

```php
return Crucible::configure()
    ->bootstrap('vendor/autoload.php')
    ->source(include: ['src'])
    ->docs('README.md', 'docs/')
    ->bind('docs/handlers.md', 'src/Handler/')
    ->bind('docs/formatters.md', 'src/Formatter/')
    ->changelogKeeps(Keep::sinceRelease()->atLeast(5));
```

- `->docs()` names the pages that are discovered as Markdown tests and stamped.
- `->bind(page, source)` declares the code a page documents. Both sides must exist.
- A page without a binding is bound to everything under `->source()`.
- **A binding is per file.** A directory is allowed because it is a set of files; a
  class or a method is not. File level is the granularity D-036/D-041 chose, and the
  fingerprint is defined over file contents, so a method binding would need hashing
  below the file and could no longer be recomputed from files alone. A page about one
  class binds to that class's file.

### Impact selection

Markdown pages are a new dialect, so their selection is new; no existing selection
changes.

- **A sample is scanned like any test file.** It runs as a script, so the reference
  scanner reads it the same way and finds the classes it uses (the static half of
  D-036).
- **A binding adds edges, never removes them.** Selection is the union of the scanned
  edges and the bindings, so a change in `src/Handler/` selects `docs/handlers.md`
  even when no sample names the changed class. When uncertain, run more, never select
  less.
- **JavaScript samples narrow the Vitest way.** A changed `.vue` file selects through
  `vitest related` (D-080), and the page's binding adds its edge as for PHP.
- **An unbound page is bound to all of `->source()`,** so any source change selects
  it. That over-selects; it never false-greens.
- **`--docs --check` checks every page's fingerprint** whatever `--changed` selected,
  so a page selection skipped still cannot go stale on `main`.

The configuration is read when a page is written. Everything a reader needs is then in
the page itself, including its binding.

## The stamped page

A page is self-contained: it carries its samples, their results, its history and the
stamp of the last run. It can be read and trusted without the configuration, a log or
CI.

The same page after `crucible --docs`:

````md
<!--[[ run: changelog ]]-->
- 2026-11-27 · 3f9a1c2b7d40 · fingers-crossed: failed → passed
- 2026-11-20 · 8e21f0a9c3b5 · release 2.0.0
- 2026-11-20 · 8e21f0a9c3b5 · fingers-crossed: added, passed
<!--/-->

...the samples and their output blocks, unchanged...

Holds the test: <!--[[ test: id=tests/PHPUnit/AssertLogTest.php::testItWarnsOnRetry ]]-->passed<!--/-->

<!--[[ run: stamp ]]-->Crucible 2.0.0 · PHP 8.4 · sources 3f9a1c2b7d40 · binds src/Handler/ · 12 passed, 1 not run<!--/-->
````

### The stamp

Every page under `->docs()` must contain a `run: stamp` marker; a page without one is a
hard error, because without it the page cannot carry its own proof. The `run: changelog`
marker is optional.

The stamp states the last run of the page: Crucible and PHP versions, the **source
fingerprint**, the binding and the totals.

The fingerprint is a SHA-256 over the sorted paths and contents of everything the
page's results depend on, shown as 12 hex characters:

- the page's samples and expected outputs;
- the bound source files;
- every test file a marker in the page names;
- the environment, as the existing rules define it: `crucible.php`, the bootstrap,
  `composer.lock` and every `->environmentFile()` (D-036), and for JavaScript samples the
  lockfile and the Vite and Vitest workspace files (D-192).

It is computed from files, not from git, so anyone can recompute it from a checkout.

### The changelog

The changelog records outcomes, not runs. A line is added only when something changes:
a named test's outcome, a test added or removed, or the totals of unnamed samples. Each
line carries the date and the fingerprint of the run that saw the change.

`crucible --docs --release 2.0.0` writes a release line. Releases are defined by the
project, in the page, so retention needs neither git nor the network.

Retention is set once, in `crucible.php`:

| Rule | Keeps |
|---|---|
| `Keep::runs(n)` | the newest `n` change lines |
| `Keep::since('90 days')` | lines younger than 90 days |
| `Keep::sinceRelease()` | lines since the newest release line, and that line |
| `->atLeast(n)` / `->atMost(n)` | a floor or a ceiling on any rule |

Pruning only removes lines, and only when the page is written.

### Writing and verifying

- **`crucible --docs`** runs every page and every test its markers name, then writes the
  marker bodies. It is run locally, and the page goes into the same commit as the code.
- **A plain `crucible` run** executes the Markdown tests like any other test and never
  writes a page.
- **`crucible --docs --check`** is the CI command and never writes. It fails when:
  - any outcome differs from what the page states, or
  - the fingerprint differs from the current sources.

  It compares outcomes and the fingerprint, never the runtime, so a PHP matrix verifies
  one page. A sample whose output differs on one PHP version fails on that version:
  the page is wrong there.
- **A failing test is written too.** Its marker shows the failure, the changelog records
  `passed → failed`, and the run exits non-zero. A page never hides a red result.
- **A rerun is a no-op.** The same sources, outcomes and runtime write a byte-identical
  page, as sigilmd does.

### The promise

> Every page on `main`, and at every tag, carries the stamp of a run on exactly the
> sources it ships with, and CI proved its outcomes on that commit.

This holds with the `AGENTS.md` release checklist unchanged: the page is committed with
the code, CI runs on that exact commit, and only then is it tagged. CI never commits.

### The run log

The page is the record that persists. A project that also wants every run elsewhere
turns on one more reporter, a view of the event stream like the others (D-025):

```php
->runLog('build/runs.jsonl')
```

It writes one JSON line per run in Hallmark's record shape, so any tool that reads
Monolog's JSON reads it: `INFO` for a green run, `ERROR` for a red one, the totals and
changes in `context`. Crucible writes the shape and does not depend on Hallmark. The
page never reads the log.

## The grammar

The marker grammar is sigilmd's, reimplemented in PHP. sigilmd is its behavioral spec:
conformance runs the real Perl script against the same fixtures (`t/fixtures`) and
requires identical output. Perl is present on every CI runner, which is sigilmd's own
premise.

- Real markers have no space inside the comment: `<!--[[ test: id=... ]]-->`. In a
  docblock the bare form `[[ test: id=... ]]` is used.
- `test:` and `run:` are the only marker names Crucible adds.
- On the first run a marker's closing `<!--/-->` is written if it is missing, as sigilmd
  does. That is the only write outside a marker body.

## Git flow

- `main` — the current line; released work only; tags on `main`.
- `1.x` — created from the last 1.x tag when 2.0 ships; fixes only, tagged there.
- `feature/*` — one decision per branch, merged to `main` behind green CI.

Push, wait for CI on that exact commit, then tag. Never rewrite a published tag.

## Non-goals

- **Not a documentation site.** Pages stay Markdown in the repository. Crucible runs and
  stamps them; any site tool renders them.
- **Not snapshot testing.** No command records or refreshes expected output. A changed
  `text` block is a decision a person makes.
- **Not a notebook.** Samples do not share state or run in order. Each one is a program
  a reader can copy.
- **Not a coverage claim.** A binding says which code a page documents, for selection
  and the fingerprint. It does not say the page covers that code.
- **Not a history store.** A page keeps the changelog its retention rule allows. Full
  history belongs to git and the optional run log.
- **No test code in production classes.** The expectation Vitest's in-source tests
  create; D-014 stands, and markers are the link instead.
- **Not a wrapper.** Orchestration runs the real binary for a suite that depends on it;
  it does not replace the engine, and engine features are not emulated on top of it.
- **Not a replacement for test files.** Anything that boots an application or needs more
  than one program stays in `tests/`.

## Gate

1. Run Hallmark's README and `docs/` as Markdown tests and match what
   `DocsSamplesTest` reports today: the same samples, the same skips, the same failure
   when an output block is edited.
2. Turn two of Hallmark's `not run` comments into markers, one in a page and one in a
   docblock. Rename the target test and see the hard error.
3. Stamp a page through a failing and then a passing run; rerun and get a byte-identical
   page.
4. Edit a bound source file without restamping and see `--docs --check` fail.
5. Run one `vue` sample through a real Vitest project and fold it into the same stamp;
   remove Vitest and see the sample error, not skip.
6. Run a Laravel app's feature suite orchestrated on real PHPUnit beside an engine suite:
   one tally, one exit code, with no alias, stand-in class or vendor change. Remove the
   binary and see an errored test.
7. Run sigilmd's fixtures through the PHP grammar against the Perl script.

Then write the `D-xxx`; only then code.
