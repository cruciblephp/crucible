# Crucible PHP — command line manual

Every option `crucible` accepts, what it does, and when you'd reach for it.

This file is checked by the suite: a test reads the option list out of the parser and fails
if anything here is missing, and fails the other way too if this file documents an option
the parser would reject. It cannot quietly fall out of date.

```
crucible [options]
```

Unknown options are errors, never ignored. A CLI option always beats the same setting in
`crucible.php`.

---

## Commands

| Command | What it does |
|---|---|
| `crucible` | Run the suite |
| `crucible migrate-config` | Convert `phpunit.xml`(`.dist`) into a `crucible.php` and exit — the one sanctioned XML read |
| `crucible flakes [--rounds N]` | Hunt order-dependent tests with seeded random rounds |
| `crucible phpstan-init` | Wire Crucible's PHPStan extension into `phpstan.neon` |
| `crucible lint-inline` | Analyse `@crucible` doctests with PHPStan through shadow files |
| `crucible mutation-index` | Emit the per-line covering-tests index as JSON, for a mutation tool |
| `crucible mutate` | Mutation-test the covered source and report the score (run `--coverage` first) |
| `crucible compat-check` | Diagnose tests that pass on the real incumbent (pest, else phpunit) but fail on Crucible |
| `crucible extensions` | List registered report-format, subscriber, and progress-view plugins and their availability |

---

## Selecting what runs

| Option | What it does |
|---|---|
| `--filter <pattern>` | Only tests whose name matches |
| `--testsuite <name,...>` | Only the named suite(s) |
| `--group <name,...>` | Only tests in the given group(s) |
| `--exclude-group <name,...>` | Never run tests in the given group(s) |
| `--todos` | Only todo-marked tests — the open-work listing |
| `--assignee <name>` | Narrow `--todos` to one person |
| `--issue <id>` | Narrow `--todos` to one issue |
| `--no-wip` | Exclude work-in-progress (`->wip()`) tests |

### By what changed

These narrow the run to the tests a change puts in doubt. A test is selected when the
transitive closure of the file declaring it intersects the change set; anything that cannot
be reasoned about — a deletion, a changed `composer.json` — widens back to the full suite
rather than risking a test that silently did not run.

| Option | What it does |
|---|---|
| `--changed[=<ref>]` | Tests affected by changes against `<ref>` (default `HEAD`) |
| `--related <file,...>` | Tests affected by the given file(s) |
| `--dirty` | Tests affected by uncommitted work — staged, unstaged **and** untracked |

Files outside the dependency graph — assets, templates, translations — are reached with
declared impact rules in `crucible.php`:

```php
->impactRule('resources/js', ['browser'])
->impactRule('resources/lang', ['i18n'])
```

A rule is **additive**: it can only add groups to the selection, never remove them, so a
missing rule is never worse than having none. A rule naming a group no test carries is
reported rather than silently doing nothing.

### Narrowing further

| Option | What it does |
|---|---|
| `--exclude-filter <pattern>` | Never run tests whose name matches — the negated `--filter` |
| `--exclude-testsuite <name,...>` | Never run the named suite(s); exclusion wins over `--testsuite` |
| `--test-suffix <suffix,...>` | Discover by these suffixes instead of each suite's own |
| `--covers <name,...>` | Only tests declaring one of these targets in a `Covers*` attribute |
| `--uses <name,...>` | Only tests declaring one of these targets in a `Uses*` attribute |
| `--requires-ext <name,...>` | Only tests carrying a matching `#[RequiresPhpExtension]` |
| `--run-test-id <id,...>` | Only the tests with these ids |
| `--test-id-filter-file <file>` | Only the ids listed in the file, one per line (`#` comments allowed) |
| `--test-files-file <file>` | Only the files listed in the file, absolute or working-directory-relative |

**The folded JavaScript suite.** A `->vitest()` suite is named `vitest` (or what its `name:` says),
and `--testsuite` and `--exclude-testsuite` select it like any PHP suite: `--exclude-testsuite vitest`
runs the PHP tests alone, `--testsuite vitest` the JS tests alone. An option that only selects PHP
tests — `--filter`, `--group`, `--covers`, `--uses`, `--requires-ext`, the test-id and test-file lists,
`--todos` — leaves the JS suite out unless `--testsuite` names it, and prints a line saying so, so
arming one PHP test never waits on the whole JS suite. Named, the suite takes `--filter` through to
Vitest as its own test-name pattern: `--testsuite unit,vitest --filter cart` runs the matching tests of
both, and the JS tests the pattern leaves out are not reported at all.

**Type tests** (`->typeTests('tests/Types')`) are a folded suite too, named `types`, with the same
rules: `--exclude-testsuite types` leaves the PHPStan analysis out of a quick run, `--testsuite types`
runs only it, and a PHP-only selection leaves it out with a line saying so.

`--covers App\Cart` matches `#[CoversClass(Cart::class)]` and also
`#[CoversMethod(Cart::class, 'add')]`; naming the method as `App\Cart::add` matches only the
latter. `*.pest.php` and `*.crucible.php` files are always discovered whatever `--test-suffix`
says: the dialect is chosen per file, not per suite.

---

## Listing instead of running

| Option | Lists |
|---|---|
| `--list-tests` | The selected tests, one per line |
| `--list-test-ids` | Their ids, ready to feed back to `--run-test-id` |
| `--list-test-files` | The files they live in |
| `--list-groups` | The groups they belong to |
| `--list-suites` | The configured test suites |
| `--list-tests-xml` | The selected tests as XML |

Listing happens after selection, so `--list-tests --group slow` lists what
`--group slow` would run. Two listing options together are an error rather than a
precedence puzzle. A listing exits `0`.

---

## Running

| Option | What it does |
|---|---|
| `--parallel <N>` | Run test classes in N worker processes, longest-first scheduled |
| `--shard <M/N>` | Run the Mth of N hash-stable slices — adding tests never moves others between shards |
| `--order-by <order>` | `default`, `defects`, `duration`, `random`, `reverse`, `size` |
| `--random-order-seed <N>` | Seed for `--order-by random`, so an ordering can be replayed |
| `--resolve-dependencies` | Reorder so a `#[Depends]` prerequisite runs before its dependent — the default |
| `--ignore-dependencies` | Leave the scheduled positions alone; an unmet `#[Depends]` still skips |
| `--process-isolation` | Run every test in its own process, as if each carried `#[RunInSeparateProcess]` |
| `--retries <N>` | Re-run failing tests up to N times; a pass on retry is **flaky**, not a pass |
| `--retry <N>` | PHPUnit spelling: attempt each test up to N times **in total** (`--retry 3` == `--retries 2`) |
| `--repeat <N>` | Run every selected test N times, pass or fail |
| `--watch` | Re-run affected tests on every change (`q` quits, `a` runs all, `f` runs failures) |
| `--debug` | Print each test's name as it starts — for locating a hang |

`--repeat` and the retry options answer opposite questions — run it again regardless, against run
it again only if it failed — so passing both is an error rather than a precedence puzzle. The
repetitions are ordinary members of the plan: ordered, counted, and dispatched like any other test,
and they ride the worker manifest so a `--parallel` run repeats the same way.

Dependency resolution is a *reordering* pass and nothing else, exactly as in the spec: it
defers a dependent whose prerequisite has not run yet, so reordering can never turn a passing
suite into skips. `--ignore-dependencies` drops the pass, which matters only when some other
ordering (`--order-by reverse`, `random`, `defects`) put a dependent first. Either way an
unmet `#[Depends]` still skips — that is the runner's, not the scheduler's.

`#[PreserveGlobalState(true)]` is honoured: the parent's user-defined constants and runtime
globals reach the child, restored before its bootstrap so a bootstrap setting the same name still
wins. A value no serializer can carry — a closure, a resource — is skipped by name on stderr rather
than failing the export. Without the attribute, or with `(false)`, a child boots clean, which is
the default and unchanged. When the test that *sets* the state is itself isolated its writes never
reach the parent, so there is nothing to preserve; the spec has that same limit.

`#[Depends]` still works under isolation: a prerequisite's return value is recorded by
whichever process ran it and handed to the dependent's, so a chain behaves the same whether
it runs in one process or four. A unit waits for the values it needs while anything that
could still produce them is running; a value no serializer can carry is dropped, and its
dependent reports **untested** rather than taking the run down.

---

## Output

| Option | What it does |
|---|---|
| `--view <key>` | Select a registered progress view (`crucible extensions`) by key — third-party views included |
| `--view=map` | The suite as a map: one cell per test, reserved before the run starts, with Time, Tests and Legend panels and one progress bar. Drawn inline |
| `--view=map-fullscreen` | The same on the alternate screen, restoring the scrollback on exit. **The default wherever a screen can be redrawn** — a terminal that is not CI, not a pipe, and not `TERM=dumb` |
| `--view=console` | The dot-per-test view. The default in CI, in a pipe, and on a terminal that cannot be redrawn |
| `--testdox` | Sugar for `--view=testdox`: replace the progress output with the documentation view |
| `--testdox-text <file>` | The documentation view as plain text |
| `--testdox-html <file>` | The documentation view as a standalone HTML page |
| `--testdox-summary <file>` | Only the run tally, without the per-test listing |
| `--teamcity` | Sugar for `--view=teamcity`: replace the progress output with TeamCity service messages |
| `--profile` | List the slowest tests, each with its share of total runtime |
| `--colors[=<when>]` | `auto` (default), `always`, `never` |
| `--log-events-json <file>` | The NDJSON event stream — the machine-readable contract |
| `--log-events-text <file>` | The same stream as one line per event: sequence and name |
| `--log-events-verbose <file>` | The same, keeping each event's payload fields |
| `--with-telemetry` | Prefix each event-text line with elapsed time and peak memory |
| `--log-teamcity <file>` | TeamCity service messages to a file, leaving the progress output alone |
| `--log-junit <file>` | JUnit XML, for CI |
| `--log-otr <file>` | An Open Test Reporting (opentest4j) event stream |
| `--log-markdown <file>` | A Markdown report |
| `--log-pdf <file>` | A PDF report, dependency-free and byte-deterministic |
| `--report <key:path,...>` | Render one or more registered report formats (`crucible extensions`) to `path` — third-party formats included |
| `--subscriber <key:path,...>` | Subscribe one or more registered subscribers (`crucible extensions`) writing to `path` — third-party subscribers included |

The three event-stream targets are one substrate in three renderings, so they agree by construction:
NDJSON for parsers, a line per event for a person, and the verbose form that keeps the payload.
`--no-logging` clears all of them, and `--no-output` does not — a log target is not output.
`--with-telemetry` adds the spec's `[since start / since previous] [peak bytes]` prefix to the two
text renderings, which is what turns a trace into an answer about where the run went.

`--profile` reports the share of total runtime beside each duration, because a bare `2.0s`
is not actionable until you know whether it is half the suite or a rounding error. Retries
count as they ran.

---

## Report-format, subscriber, and progress-view plugins (`crucible extensions`)

| Option | What it does |
|---|---|
| `--key <name>` | Show one plugin's full detail (description, requirements, params) instead of the summary list |
| `--preview` | With `--key <name>`: render or run a live sample and write it |
| `--out <path>` | Preview output path (default: a temp file in the system temp directory, path printed) |
| `--json` | Machine-readable output for the list/detail view |

The list mixes three plugin kinds, distinguished by a `KIND` column: **report formats** (PDF, Markdown)
render a whole `Document` to a string once, at the end of the run; **subscribers** (JUnit) receive every
event live, across the whole run, writing to their own file, the way `--log-junit` always has;
**progress views** (console, testdox, teamcity) also receive every event live, but print to the terminal
instead of a file, and exactly one is active per run — the live view a run subscribes, never stacked.
`--log-teamcity` is the single exception: the same registered view resolved onto a file, alongside
whichever view holds the terminal.
All three are registered in `crucible.php` as a class-string + params — never a constructed instance,
deliberately separate from `->extension()`, which registers already-built `Check` plugins:

```php
->reportFormat('key', SomeFormat::class, [...])
->subscriber('key', SomeSubscriber::class, [...])
->progressView('key', SomeView::class, [...])
```

`pdf`/`markdown` (report formats), `junit` (subscriber), and `console`/`testdox`/`teamcity` (progress
views) are pre-registered the same way as any third party's own plugin; there is no privileged built-in
path. `--report`/`--log-pdf`/`--log-markdown` resolve through the report-format registry;
`--subscriber`/`--log-junit`/`--log-otr`/`--testdox-text`/`--testdox-html`/`--testdox-summary` resolve
through the subscriber registry; `--view`/`--testdox`/`--teamcity`/
`--log-teamcity` resolve through the progress-view registry — one dispatch mechanism per kind, underneath every spelling.
Because `console`/`testdox`/`teamcity` are ordinary overridable keys, registering `->progressView('testdox',
CustomClass::class)` changes what the `--testdox` flag itself constructs.

A report format's params are read at render time, so `--preview` can render it with only its schema's
own declared defaults. A subscriber's params reach its *constructor* instead (`Listener::handle()` has
no room for them), so `--preview` always supplies an `output` path — `--out`, or the default temp
file — even where the schema declares no default, and then runs a small real event sequence through it.
A progress view's params also reach its constructor, alongside a framework-supplied stream (`STDOUT` for
a real run, an in-memory buffer for `--preview`) — there's no `output` param to force, since a view
writes to whatever stream it's given, not a path it chooses.

Writing a subscriber that owns a file (opens it, closes it when the run finishes) is common enough that
`Subscriber\FileBackedSubscriber` handles that lifecycle for you — extend it and implement `write()`;
see `JUnitSubscriber` for the pattern.

### Checks: the duplication gate

`->extension()` takes an already-built `Check` — a plugin that inspects the project once, after the
suite, and presents facts for Crucible to judge. `DuplicationCheck` is one, over
[phpcpd-next](https://github.com/phpcpd-next/phpcpd):

```php
use LucianoPereira\Crucible\Extension\Duplication\DuplicationCheck;

->extension(new DuplicationCheck(paths: ['src'], maxClones: 0))
```

It fails the run when the project holds more copy-paste clones than `maxClones` allows, and names where
they are. `preset: 'laravel'`, `minLines`, `minTokens`, `exclude`, `suffixes`, `algorithm`, `fuzzy` and
`typeAnchored` pass straight through to phpcpd's own detection — the defaults are its defaults, not
Crucible's opinions.

phpcpd-next is **not** a dependency of Crucible and never becomes one; a test framework that dragged a
duplication detector into every install would charge everyone for a tool most projects do not run.
Install it where you want the check: `composer require --dev phpcpd-next/phpcpd`. Registering the check
without the package fails naming the package rather than fataling on a missing class.

The plugin never decides anything: it reports the clones it found against the threshold it was given,
and Crucible owns the verdict, the message and the exit-code vote (DESIGN.md D-078).

Duplication that is deliberate design is marked where it lives, and the check does not count it:
`@phpcpd-ignore-clone <reason>` on the declaration (one side of the pair is enough), or a
`// phpcpd-ignore-start` … `// phpcpd-ignore-end` region. With the markers in place, `maxClones: 0` holds.
A phpcpd-next acknowledgment ledger ("we know, not this quarter") does not change the count:
phpcpd-next's own rule is that an acknowledged clone is still reported, still counted, and still gates,
and the check keeps the tool's rule rather than inventing a second one.

---


## Reproducible output

`--reproducible` normalises everything in a report that describes the **run** rather than the
**result**, so two runs of the same code write byte-identical files and `cmp` answers the
only question CI actually asks: did behaviour change?

Without it the answer is always yes. A report restates when it ran and how long it took, and
both move even when nothing else did — so a checksum over a report is useless as a change
detector, and a report cannot be committed as a golden file the way a snapshot can.

Two kinds of volatility, handled two ways, because one switch could not cover both:

- **Timestamps** ride the injectable clock the event stream already uses, so the flag freezes
  that clock at the Unix epoch and every emitter follows — the event stream, the JSON and
  SARIF reports, and all four coverage writers. The epoch is deliberately not a
  plausible-looking value: a report claiming it was generated in 1970 cannot be mistaken for
  one that really was.
- **Durations** deliberately do *not* come from that clock — they are monotonic, taken at the
  call site — so they are normalised at the report boundary instead.

The console summary is untouched: it still reports the real elapsed time. The flag changes
what is written for machines, not what is shown to you.

## Coverage

| Option | What it does |
|---|---|
| `--coverage` | Collect line coverage (pcov or xdebug) and print the summary |
| `--coverage-branch` | Also collect branch coverage — needs xdebug; pcov cannot |
| `--path-coverage` | Also collect path coverage: whole routes through a function, not single blocks |
| `--warm-coverage-cache` | Parse the coverage scope's source now, so a later report does not |
| `--strict-coverage` | Executing code outside the declared `Covers`/`Uses` targets is risky |
| `--min <percent>` | Fail the run below this line-coverage percentage |
| `--coverage-clover <file>` | Clover XML, for CI |
| `--coverage-cobertura <file>` | Cobertura XML, for CI |
| `--coverage-html <dir>` | A self-contained HTML report |
| `--coverage-openclover <file>` | OpenClover XML, for CI |
| `--coverage-php <file>` | The coverage data as a PHP file that returns it — plain arrays in Crucible's own format, readable without loading any Crucible class, and deliberately not the incumbent's serialized-object dump |
| `--coverage-text[=<file>]` | The text report to a file, or the terminal when bare |
| `--coverage-text-summary` | Text report: totals only, no per-file rows |
| `--coverage-text-uncovered` | Text report: keep the files with no covered line |
| `--coverage-filter <dir>` | Add `dir` to the coverage scope, alongside `->source()` |
| `--disable-coverage-targeting` | Every test contributes all it executed, not only its declared targets |
| `--disable-coverage-ignore` | Accepted: there is no coverage-ignore metadata to disable |
| `--coverage-crap4j <file>` | Crap4J XML: complexity against coverage, per method |
| `--coverage-xml <dir>` | The XML coverage report — an index plus one document per file |
| `--coverage-xml-no-source` | Omit the `source` element from that report |
| `--without-class-view` | HTML report: drop the per-method table |
| `--without-file-view` | HTML report: drop the annotated source pages |
| `--include-git-information` | Record commit, branch, and cleanliness in the coverage data |

`--min` is the CI gate: `crucible --min 90` fails the run when line coverage comes in under
90%. It collects coverage on its own — asking for a floor is asking for the measurement it is
a floor on, so pairing it with `--coverage` is optional and only adds the printed summary.
Pairing it with `--no-coverage` is refused rather than resolved: one says measure nothing and
the other says fail below a measurement, and letting either win silently would drop the gate
and let the pipeline report success. The number judged is the one the text report prints,
rounded to two places, so a run reporting `Total: 90.00%` never fails `--min 90` over a
decimal nobody can see. A scope with no executable lines fails the gate rather than passing it.

`--warm-coverage-cache` parses the coverage scope up front — the cost the Crap4J, XML, and
per-method HTML reports otherwise pay during a run. Crucible's analysis cache lives in the process
rather than on disk, so warming it pays off inside a longer-lived one; the command reports how many
files it parsed rather than implying more than that.

Path coverage comes out of the same xdebug pass as branch coverage, so `--path-coverage` implies
`--coverage-branch`. A branch is one basic block; a path is a whole route through a function, so
the count is combinatorial in the branches — which is why it is collected only when asked for.

The coverage scope is `->source(include: [...])`; `--coverage-filter` adds to it rather than
replacing it, which also makes it the whole scope when nothing is configured. Targeting is what
the `Covers` metadata buys: a test contributes only to what it claims to cover, so an incidental
execution does not inflate someone else's number. Turning it off with `--disable-coverage-targeting`
makes every test contribute everything it ran, which is the aggregate you want when measuring
reachability rather than attribution.

Crucible has no coverage-ignore metadata — no `@codeCoverageIgnore`, no attribute — so there is
nothing for `--disable-coverage-ignore` to disable and it is accepted as already satisfied.
Crucible's default here is the spec's `--disable-coverage-ignore` behaviour.

The Crap4J, XML, and per-method HTML views need something a line map cannot say: where each method
starts and ends, and how many independent paths run through it. That comes from reading the source
as tokens, never from reflection — the report runs in the parent after a run whose workers loaded
the code, so what happens to be declared in this process is no basis for a report, and code that
never ran has to be measurable too. The complexity is the spec's own definition, validated against
its calculator over 7,182 methods of Crucible's and PHPUnit's own source.

---

#### Longer spellings

The spec writes these at length. Crucible's own name is shorter; both are accepted, so a
command line written against PHPUnit runs unchanged.

| The spec's spelling | Crucible's |
|---|---|
| `--do-not-fail-on-deprecation` | `--no-fail-on-deprecation` |
| `--do-not-fail-on-incomplete` | `--no-fail-on-incomplete` |
| `--do-not-fail-on-notice` | `--no-fail-on-notice` |
| `--do-not-fail-on-risky` | `--no-fail-on-risky` |
| `--do-not-fail-on-skipped` | `--no-fail-on-skipped` |
| `--do-not-fail-on-warning` | `--no-fail-on-warning` |
| `--do-not-fail-on-direct-deprecation` | `--no-fail-on-direct` |
| `--do-not-fail-on-indirect-deprecation` | `--no-fail-on-indirect` |
| `--do-not-fail-on-self-deprecation` | `--no-fail-on-self` |
| `--do-not-fail-on-empty-test-suite` | `--no-fail-on-empty-test-suite` |
| `--do-not-fail-on-phpunit-deprecation` | `--no-fail-on-phpunit-deprecation` |
| `--do-not-fail-on-phpunit-notice` | `--no-fail-on-phpunit-notice` |
| `--do-not-fail-on-phpunit-warning` | `--no-fail-on-phpunit-warning` |
| `--do-not-report-useless-tests` | `--no-useless-test-reports` |
| `--only-summary-for-coverage-text` | `--coverage-text-summary` |
| `--show-uncovered-for-coverage-text` | `--coverage-text-uncovered` |
| `--exclude-source-from-xml-coverage` | `--coverage-xml-no-source` |
| `--warn-when-php-is-not-configured-for-development` | `--php-advisory` |
| `--do-not-warn-when-php-is-not-configured-for-development` | `--no-php-advisory` |
| `--requires-php-extension` | `--requires-ext` |
| `--log-events-verbose-text` | `--log-events-verbose` |
| `--update-deprecations-baseline` | `--update-baseline` |
| `--require-coverage-contribution` | `--require-coverage` || `--fail-on-direct-deprecation` | `--fail-on-direct` |
| `--fail-on-indirect-deprecation` | `--fail-on-indirect` |
| `--fail-on-self-deprecation` | `--fail-on-self` || `--manual` | `manual`, the command spelling |
| `crucible completion <shell>` | Prints the tab-completion script for `bash`, `zsh` or `fish` |
## Exit-code policy

By default only errors and failures fail the run. Each of these promotes something else:

| Option | Exit non-zero when |
|---|---|
| `--fail-on-risky` | a test is risky |
| `--fail-on-skipped` | a test is skipped |
| `--fail-on-incomplete` | a test is incomplete |
| `--fail-on-deprecation` | a test triggers a deprecation |
| `--fail-on-notice` | a test triggers a notice |
| `--fail-on-warning` | a test triggers a warning |
| `--fail-on-flaky` | a test passed only on retry |

And these stop early:

| Option | Stops after |
|---|---|
| `--stop-on-defect` | the first error, failure, or risky test |
| `--stop-on-error` | the first error |
| `--stop-on-failure` | the first failure |
| `--stop-on-risky` | the first risky test |
| `--stop-on-skipped` | the first skipped test |
| `--stop-on-incomplete` | the first incomplete test |
| `--stop-on-deprecation` | the first test that triggers a deprecation |
| `--stop-on-notice` | the first test that triggers a notice |
| `--stop-on-warning` | the first test that triggers a warning |

The last three read what a test *emitted*, not how it ended: a passing test that triggers a
deprecation still stops the run under `--stop-on-deprecation`, which is the reason to have
it. Each has a configuration twin — `->stopOnRisky()` and the rest.

Stopping is the in-process runner's, so under `--parallel` the workers already dispatched
finish their unit. That is true of `--stop-on-defect` today and has not changed.

`--update-baseline` adds the run's deprecations to the baseline file, so the
existing ones stop failing and new ones still do.

---

## Snapshots and the browser

| Option | What it does |
|---|---|
| `--update-snapshots`, `-u` | Record or replace snapshot values — never happens implicitly |
| `--browser <engine>` | `chrome` (default), `firefox`, `safari` |

---

## Caches and configuration

| Option | What it does |
|---|---|
| `--configuration <file>` | Read configuration from `<file>` instead of `crucible.php` |
| `--cache-result` | Write the result cache (the default) |
| `--do-not-cache-result` | Neither read nor write the result cache |
| `--cache-directory <dir>` | Where Crucible's caches live |
| `--init` | Create a `crucible.php` in the current directory |
| `--version` | Print version information and exit |
| `-h`, `--help` | Print the built-in help and exit |

The result cache is what makes `--order-by defects` and `--order-by duration` work: it
holds each test's outcome history and last duration.

---

## Migrating from PHPUnit

| Option | What it does |
|---|---|
| `--auto-fix` | With `crucible compat-check`: apply known-safe rewrites without asking |
| `--revert` | With `crucible compat-check`: undo fixes applied by a previous `--auto-fix` run |

### PHPUnit spellings

Accepted so an existing command line runs unchanged. Each one is the spec's name for
something Crucible already does; nothing here is a second implementation.

| Option | Resolves to |
|---|---|
| `--bootstrap <file>` | The configuration's `->bootstrap()`, overridden for this run |
| `--extension <class>` | `->extension()`, instantiated from the class name (no constructor arguments) |
| `--globals-backup` | `->backupGlobals()` |
| `--static-backup` | `->backupStaticProperties()` |
| `--strict-global-state` | `->beStrictAboutChangesToGlobalState()` |
| `--require-coverage` | `->requireCoverageMetadata()` |
| `--no-coverage` | Collects no coverage, whatever else the same command line asks for |
| `--no-fail-on-deprecation` | `->failOnDeprecation(false)` |
| `--no-fail-on-incomplete` | `->failOnIncomplete(false)` |
| `--no-fail-on-notice` | `->failOnNotice(false)` |
| `--no-fail-on-risky` | `->failOnRisky(false)` |
| `--no-fail-on-skipped` | `->failOnSkipped(false)` |
| `--no-fail-on-warning` | `->failOnWarning(false)` |
| `--fail-on-self` | `->failOnSelfDeprecation()` — triggered in your own code |
| `--fail-on-direct` | `->failOnDirectDeprecation()` — your code triggered it in a dependency |
| `--fail-on-indirect` | `->failOnIndirectDeprecation()` — one dependency triggered it in another |
| `--no-fail-on-self` | `->failOnSelfDeprecation(false)` |
| `--no-fail-on-direct` | `->failOnDirectDeprecation(false)` |
| `--no-fail-on-indirect` | `->failOnIndirectDeprecation(false)` |
| `--fail-on-empty-test-suite` | `->failOnEmptyTestSuite()` — the run selected no tests |
| `--no-fail-on-empty-test-suite` | `->failOnEmptyTestSuite(false)` |
| `--fail-on-all-issues` | Turns on every fail-on policy; a negation alongside it still wins |
| `--fail-on-phpunit-deprecation` | Accepted, nothing to act on — see below |
| `--fail-on-phpunit-notice` | Accepted, nothing to act on — see below |
| `--fail-on-phpunit-warning` | Accepted, nothing to act on — see below |
| `--no-fail-on-phpunit-deprecation` | Accepted, nothing to act on — see below |
| `--no-fail-on-phpunit-notice` | Accepted, nothing to act on — see below |
| `--no-fail-on-phpunit-warning` | Accepted, nothing to act on — see below |
| `--display-phpunit-deprecations` | Accepted, nothing to act on — see below |
| `--display-phpunit-notices` | Accepted, nothing to act on — see below |

The three scopes are the attribution the issue collector already makes, and the same counts
the deprecation budgets read: a trigger inside your own directories is **self**, one inside a
dependency is **direct** when your code called it and **indirect** otherwise.

The eight `phpunit`-prefixed options are the one group here that is accepted rather than
implemented. In PHPUnit they concern issues *PHPUnit itself* raises about how a suite uses
its API. Crucible raises none: there is no framework-internal issue channel to filter. They
parse so an existing command line runs unchanged, and they are listed here rather than left
for someone to discover they do nothing.
| `--random-order` | `--order-by random` |
| `--reverse-order` | `--order-by reverse` |
| `--record-test-run-history` | `--cache-result` — the result cache *is* the per-test run history |
| `--do-not-record-test-run-history` | `--do-not-cache-result` |
| `--generate-configuration` | `--init` — writes a `crucible.php` |
| `--branch-coverage` | `--coverage-branch` |
| `--migrate-configuration` | The `migrate-config` command |
| `--no-configuration` | Runs without reading `crucible.php` at all |
| `--no-logging` | Writes no report or log file, whatever else the same command line asks for |
| `--no-extensions` | Registers no extensions, configured or from `--extension` |
| `--include-path <path>` | Prepends to PHP's `include_path` before the bootstrap; `:`-separated |
| `--no-useless-test-reports` | Stops marking a test that asserts nothing as risky |
| `--enforce-time-limit` | A test slower than its declared size allows is risky |
| `--default-time-limit <sec>` | The budget for a test that declares no size |
| `--disallow-test-output` | A test that prints anything is risky |
| `--atleast-version <v>` | Exits `0` only if the running version is at least `<v>` |
| `--check-version` | Reports that Crucible has no update channel, and exits `0` |
| `--validate-configuration` | Checks that `crucible.php` loads and every path it names exists |
| `--all` | Accepted: Crucible runs every configured suite already |
| `--check-php-configuration` | Reports whether PHP is configured for development, exit `1` if not |
| `--php-advisory` | Prints that advisory before the run instead |
| `--no-php-advisory` | Never prints it — the default |
| `--compact` | Progress and tally only, nothing between them |
| `--columns <n\|max>` | Progress characters per line (default 64) |
| `--no-progress` | Prints no per-test progress characters |
| `--no-results` | Prints no problem list and no passed tree |
| `--no-output` | Prints nothing at all; the exit code and the log targets are unchanged |
| `--reverse-list` | Lists the problems last-first |
| `--stderr` | Writes the progress view to stderr instead of stdout |
| `--diff-context <n>` | Unchanged lines to keep around each change in a diff |
| `--display-deprecations` | Lists the deprecations the tally counted, each with its scope |
| `--display-notices` | Lists the notices the tally counted |
| `--display-warnings` | Lists the warnings the tally counted |
| `--display-errors` | Lists the errored tests and their reasons |
| `--display-skipped` | Lists the skipped tests and their reasons |
| `--display-incomplete` | Lists the incomplete tests and their reasons |
| `--display-all-issues` | All six of the above at once |
| `--generate-baseline <file>` | Runs reading no baseline, then writes what it saw to `<file>` |
| `--use-baseline <file>` | Reads the deprecations baseline from `<file>` |
| `--ignore-baseline` | Reads no deprecations baseline at all |

Passing a fail-on switch together with its do-not counterpart is an error rather than a
precedence puzzle, and so is combining the two retry spellings.

The overrides that a test can observe — the bootstrap file, `--include-path`, the four
backup/strict switches, and `--no-useless-test-reports` — travel to worker processes on
the manifest, so a `--parallel` run behaves like its sequential twin.

The output switches reach the progress view as engine-computed parameters, the same way
`--colors` already does: a view that declares the parameter receives it, one that does not is
left alone, so a third-party view never inherits an option it did not agree to. `--no-output`
does not subscribe a view at all.

The run summary says `Deprecations: 3`; the display family says which three, where
they were triggered, and — for a deprecation — whether the trigger was **self**, **direct**,
or **indirect**. Each display option reads the same log the exit-code policy reads, so a
listed issue and a counted one are always the same issue.

`--generate-baseline` is a *generate*, not a merge: the run reads no baseline, so everything
it saw is the whole truth and writing it fresh is correct. `--update-baseline`
is the merging form, and it keeps acknowledged entries that this run happened not to trigger.
Both the chosen file and the decision to read none travel to workers, which suppress
baselined deprecations at capture and would otherwise disagree with the parent.

`--diff-context` is run-wide display state on the differ, set before any test can render a
failure, and it rides the worker manifest so a `--parallel` failure reads the same.

`--no-configuration` leaves no configured suite, so there is nothing to discover: it exists
to prove `crucible.php` is not being read, which is the only thing it can prove here, since
The time budgets are the spec's: one second for `#[Small]`, ten for `#[Medium]`, sixty for
`#[Large]`, and `--default-time-limit` for a test that declares no size. Crucible *measures*
rather than interrupts — the spec aborts the test mid-flight with a `pcntl` alarm — so the
budget needs no extension, holds identically inside a worker, and the reason states the
duration actually observed. A test that hangs forever is a hang either way: no alarm survives
a blocking syscall.

`--disallow-test-output` needs the output itself, so a test's output is now captured, reported
as a `test:output` event, and written back out — capturing it never means losing it. Under the
switch it is reported instead of printed, since the point is that it should not be there.

The php.ini advisory checks `display_errors`, `display_startup_errors`, `error_reporting`,
`zend.assertions`, `assert.exception`, `memory_limit`, and — when the extension is loaded —
`xdebug.show_exception_trace`, against the same values the spec recommends. It is advice about
the machine, never a verdict about the code: the warning form cannot fail a run, and the
command form runs no tests.

`--validate-configuration` has no XML schema to validate against, so it validates what a PHP
configuration can actually be wrong about: whether it loads at all, and whether every directory,
file, bootstrap, and source include it names is there. It exits non-zero on any problem, naming
each one, so CI can hold the line on a configuration that has drifted from the tree.

`--all` ignores the test selection a configuration file declares. Crucible has no such selection
to ignore — every configured suite runs unless `--testsuite` or `--exclude-testsuite` says
otherwise, and those are command-line, not configuration — so the switch is accepted as already
satisfied rather than refused.

Crucible takes no positional paths to run instead. `--check-version` is answered rather than
performed — Crucible has no update channel and does not phone home.

---

`crucible compat-check` runs the project's real, installed incumbent alongside Crucible and
diagnoses any test that passes on the oracle but fails on Crucible — usually a sign the test
asserts PHPUnit's own internals (its file layout, its internal method names) rather than your
code's behavior, not a Crucible bug. The oracle is `vendor/bin/pest` where Pest is installed and
`vendor/bin/phpunit` otherwise: PHPUnit's binary refuses to run a Pest suite, so on a Pest
project it would collect nothing. Tests the oracle reports without a source file — Pest names a
classic PHPUnit class by its description — are read instead from the same run's PHPUnit event log,
which names the class, and placed by the file that declares it. Anything still unplaced is listed,
left out of the comparison rather than counted as agreement, and makes the run exit `2`: a verdict over part of a
suite is not a clean run. Its exits follow the table below — `1` means the engines disagree, which
is a finding about your code; `2` means the comparison could not be performed, which is a finding
about the setup, and the message names what to change. It only ever auto-rewrites a small, explicit table of
known-safe shapes; anything else is diagnosed, never guessed at, and the interactive path
always asks before writing. `--revert` restores every file `--auto-fix` touched, in full.

---

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Everything the policy above counts as success |
| `1` | Failures, errors, or anything a fail-on switch promoted |
| `2` | The run could not proceed — a configuration error, no tests found, an unknown option, or a suite that could not be built (a `uses()` binding PHP refuses, say) |

---

## Not an option

`--worker` exists in the parser and is deliberately absent from the help: it is the
supervisor's private handshake with the child processes it spawns, not something to invoke
by hand. It is the only such omission, and the suite asserts that.
