# Changelog

Every release, as it ships. RELEASE.md is the full record of what shipped, each entry traced to its decision record.

## v1.4.6 — 2026-11-25

Thanks to Thomas (lekoala) for the #29 follow-up review.

- Fixed the watchdog to measure inactivity, not total unit time, and to check the deadline only after reading pending frames: a unit reporting normally survives both a total longer than `->watchdog()` and a finish at the boundary
- Fixed the cold mutation worker blocking on a mutant that floods stdout: the executor now drains stdout on every poll
- Fixed a foreign `test:start` reaching the event stream and allowing a duplicate synthetic start; starts are counted against the unit like finishes
- Refused a `--changed=<ref>` value that starts with `-` instead of passing it to git as an option
- Routed `observed-types.json` through `AtomicFile` — the last cache artifact listed in `spec/cache-formats.md`
- Added `pnpm-workspace.yaml`, `nx.json`, `turbo.json` and `.npmrc` to the Vitest environment files, and routed four host-dependent absolute-path checks through `WorkingDirectory::isAbsolute()`
- Widened PHP, type and Vitest selection on a gitlink/submodule change instead of selecting nothing, and added `WorkingDirectory::isAbsoluteAnyPlatform()` for paths rendered from another platform
- Fixed `doctor` for Laravel/testbench migration mode: PHPUnit is a framework requirement and `phpstan-phpunit` is its companion, not a duplicate; added a real Laravel/PHPUnit migration parity test and CI gate. D-195, D-196
- Kept the documented behaviours: the worker exit code after `run:finish` is not a verdict, non-module shared files outside a Vitest root leave suites out, the dependency index saves fresh entries only, and the event marker is not a security boundary. The deprecations baseline and the worker dependency handoff are outside the written cache-atomicity contract and were left alone. D-191–D-196
- Full gate: 2046 tests, 0 failed, 0 errors, 0 risky; all 19 conformance fixtures conform; phpunit/mockery compatibility analysis clean

## v1.4.5 — 2026-11-25

- Fixed the v1.4.4 Windows gate: the atomic-file mode-preservation assertion is POSIX-only; Windows synthesises permission bits, so the test now skips by its OS requirement there. No runtime change
- Full gate: 2034 tests, 0 failed, 0 errors, 0 risky on Linux; all 19 conformance fixtures conform; the phpunit/mockery compatibility tier of `composer analyse:oracles` clean

## v1.4.4 — 2026-11-25

Thanks to Thomas (lekoala) for the #28 audit, the reproductions and the investigation notes.

- Fixed `--last-failed` claiming `complete`, which let snapshot and property-failure pruning delete stored data for tests the run deliberately did not run
- Fixed a class-level `markTestSkipped()`/`markTestIncomplete()` from a real PHPUnit-descended class erroring every test instead of skipping the class; verified by running the same fixture through the real `phpunit` binary and Crucible
- Fixed Vitest impact selection hiding a module inside one suite root from the sibling suite that imports it
- Fixed type suites being dropped when `composer.json`, `composer.lock` or a `.stub` file changes
- Fixed impact selection ignoring project files required by the active configuration or bootstrap
- Fixed a coverage worker without a driver silently collecting nothing: it now logs the reason and runs nothing, and every dispatched worker must leave an artifact or the run fails
- Fixed a worker that never spawned still publishing `complete:true`
- Fixed `--stop-on-*` under `--parallel`: the resolved policy now rides the worker manifest and the supervisor stops handing out units after a matching result
- Added `->watchdog($seconds)` (default 600, `0` disables): a worker unit that reports nothing is terminated and its unfinished tests errored
- Made cache, snapshot, inline-snapshot, coverage-map and dependency-index rewrites atomic through one `AtomicFile` helper
- The listing fixture no longer writes a tracked results cache, and the impact documentation now states the actual widen/select-nothing rule, including the watch-mode difference. D-190
- Full gate: 2034 tests, 1984 passed, 0 failed, 0 errors, 0 risky, 49 skipped (optional oracles), 1 untested; all 19 conformance fixtures conform; the phpunit/mockery compatibility tier of `composer analyse:oracles` clean

## v1.4.3 — 2026-11-24

Thanks to Thomas (lekoala) for the #27 report, bisect and Windows measurements.

- Fixed `--parallel` hanging on Windows once a worker wrote about 6 KB of events: a `proc_open()` pipe there cannot be read without blocking, and `stream_select()` waits on sockets only, so the worker blocked on a full stderr pipe while the supervisor blocked on a silent stdout. The worker's stderr is now a per-worker file — the same stream, frames and user text in write order — read through a second handle by offset and polled every millisecond; stdout goes to the null device and POSIX keeps its pipe. A crashed worker keeps its log, named in the error. D-189
- Added `php crucible --parallel 2` to the Windows CI matrix; it previously ran the suite sequentially and never read a worker's output, which is why 1.4.2 stayed green while `--parallel` hung
- Added `WorkerStderrChannelTest` and a file-channel `WorkerProcessTest` case, so the Windows reader is exercised on every platform
- Full gate: 2019 tests, 0 failed, 0 errors, 0 risky; CI green on PHP 8.3–8.5, Linux and Windows; 8.6 experimental green

## v1.4.2 — 2026-11-23

Thanks to Thomas (lekoala) for the reports and patches behind #22–#25.

- Fixed a worker's NDJSON events sharing stdout with whatever the project printed: a raw `fwrite(STDOUT, 'x')` without a trailing newline could swallow the frame carrying a failure, so the failed test was reported as a lost result. Events now travel on the worker's stderr behind a per-frame marker; stdout is drained and user stderr keeps write-order attribution. D-189
- Fixed `--dirty`/`--changed` narrowing the run when the active configuration was given relatively: the environment paths are now resolved at the impact-selection boundary, and an unresolvable bootstrap widens instead of vanishing from the comparison. D-036
- Fixed `--dirty`/`--changed` dropping the type suite when a change deletes the PHP source or PHPStan configuration a `*.types.php` assertion names, or edits `phpstan.neon.dist`. D-130
- Changed the dependency fingerprint to walk the suite configuration once per directory instead of once per test case: 350 ms → 2.4 ms on a 3,000-row fixture, with a byte-identical key. D-188
- Added a CI diff-hygiene gate for leftover conflict markers and whitespace errors.
- Full gate: 2016 tests, 0 failed, 0 errors, 0 risky; CI green on PHP 8.3–8.6, Linux and Windows

## v1.4.1 — 2026-11-21

- Fixed the 1.4.0 gate on PHP 8.3/8.4: the new handler-stack tests called `get_error_handler()`, which is PHP 8.5+, and one nowdoc fixture contained its own delimiter word. The collector already read the stack with a portable probe; the tests now do the same. No runtime behavior change
- Re-ran the full gate on 8.3 and 8.4: 1992 tests, 0 failed, 0 risky on both; 8.5/8.6 unchanged

## v1.4.0 — 2026-11-21

- Added PHP 8.6 as an experimental CI target: the self-hosted suite, PHPStan, Rector and the generated-code tier pass on 8.6.0. Fixed the two 8.6 deprecations it exposed: `ReflectionMethod::invoke()` with an object for a static method (`CurrentTest::call`) and the one-argument `ReflectionMethod` constructor (`Observer::describe`, now `createFromMethodName()`)
- Fixed a class-level `markTestSkipped()`/`markTestIncomplete()` in `setUpBeforeClass()` erroring every test instead of skipping them, and made both methods public static as PHPUnit's are. PHPUnit 13.4.8 prints `S` and counts every test in the class as skipped; `--fail-on-skipped` now exits non-zero
- Fixed a Vitest file-level failure being folded in as nothing: a crashed file or a throwing `beforeAll()` is now one `test file` failure, and a non-zero Vitest exit with an all-green report is a failed suite event. Measured on Vitest 5.1.1: 4 tests, 1 passed, 2 failed, 1 skipped, exit 1
- Added `crucible doctor`: checks `vendor/bin/crucible`, `crucible.php`, a leftover `phpunit.xml`, migration-mode PHPUnit, the Testbench meta package, duplicate `phpstan-phpunit`/`pest-plugin-phpstan`, the PHPStan extension wiring (including a double include), and a `composer.json` that disables the bridge's package discovery. Exits 1 only for a problem that breaks the drop-in story
- Completed the `php artisan test` bridge: the signature now mirrors the engine's options — `--last-failed`, `--profile`, repeated `--testsuite`/`--group`/`--exclude-testsuite`/`--covers`/`--uses`/`--test-suffix`, coverage targets/floors, display and fail-on switches — forwards every declared option by name, and appends tokens after `--` verbatim. `phpstan-init` now names a duplicate `phpstan-phpunit`/`pest-plugin-phpstan`
- Added `--last-failed`: PHP tests whose most recent result-cache outcome failed or errored, and Vitest's own `--last-failed` for the JS suites. A clean recorded run falls back to the full selection; `--do-not-cache-result` refuses the flag instead of silently ignoring it
- Added `--shard` to the JS suite: the same `M/N` reaches `vitest --shard=M/N`, so a sharded CI run does not repeat the whole JS suite in every shard
- Added visible control characters in human-facing names: a data set containing ESC prints as `\u{001B}`, not as a raw terminal escape, matching PHPUnit 13.4.8
- Changed a window that flushes the whole error-handler stack to restore the saved handlers without a risky verdict: Laravel's `TestCase::tearDown()` calls `HandleExceptions::flushState()`, which pops until null and re-enables only PHPUnit's handler, so every framework-booting test on a Crucible-only install was reported as "removed error handlers other than its own". A silent zero-mask frame below the capture keeps a popped capture (a partial removal, still risky) distinct from the full flush
- Re-ran the conformance lane against PHPUnit 13.4.8: all fixtures conform, so the 13.4.4-13.4.8 fixes need no Crucible change. Re-measured `Quirk::ThrowInterfaceAsMessage` on Pest 5.3.7: the interface-as-message bug is still present, so the quirk stays

## v1.3.2 — 2026-11-20

Thanks to Thomas (lekoala) for the Symfony 8.1 / Doctrine / PostgreSQL migration, the issue #2 report, and the pull requests that addressed several of its items. The two item-6 requests (`--testsuite` path intersection and per-class `--profile`) remain open.

- Fixed `->env()`, `->ini()` and `->constant()` being applied after the bootstrap, so Symfony's `Dotenv::bootEnv()` saw no `APP_ENV` and loaded the dev environment: they apply before it, as PHPUnit's do. A bootstrap that `define()`s a constant also set with `->constant()` now finds it defined
- Fixed a bootstrap given as a Windows absolute path, `C:\…` or a UNC share, never being loaded
- Fixed the start banner and the messages before a run marking HTTP headers as sent, so a test booting a kernel that starts a native session failed with "headers have already been sent": they are written to the terminal stream, as PHPUnit's printer writes
- Added `LogicalAnd`, and `PHPUnit\Framework\Constraint\LogicalAnd` and `LogicalOr` as aliases, so Symfony's `assertResponseRedirects()`, `assertSelectorTextContains()` and the rest work; delete a project's own shim of either, which now collides with the alias
- Added `->aroundEachTest()` and the `AroundEachTest` extension role: a callable around each test's whole lifecycle, in the process that runs it, so a rolled-back transaction (what dama/doctrine-test-bundle does) closes in a `finally` even when `setUp()` throws
- Added `stubs/phpunit.php`, the PHPUnit names the compatibility layer aliases, declared for Mago, Psalm and other analysers that run no PHP
- Added `@phpstan-assert` and `@psalm-assert` to `Assert`, so `assertIsString($x)` and the rest narrow in analysers that cannot load the PHPStan extension; PHPStan keeps the extension's answers
- Changed a test that leaves an error handler installed, or removes one it did not install, to be risky; under `--fail-on-risky` it fails the run. The next test starts from the handlers the previous one was given
- Changed `--parallel` workers to discover only the files their units name, after loading what the parent's discovery loaded, so a worker has every function and class the sequential run has; a suite whose test files do anything at load time but declare keeps full discovery. 300 files under `--parallel 8` went from 6.7–7.5 s to 4.3–5.2 s
- Fixed `--dirty` selecting nothing on a red suite after a regenerated classmap, a reversed loader order or a file that appears ahead of another in a PSR-4 directory; fixed the dependency index never persisting on Windows, and ignoring `--cache-directory`
- Fixed `--dirty` and `--changed` missing renames, untracked files above a nested working directory, and filenames with spaces or newlines
- Fixed a run narrowed by `--dirty` or `--test-suffix` being treated as complete, which dropped stored data of tests it did not run
- Fixed a Pest file named `*Test.php` not being selected when the `Pest.php` or `Datasets/` above it changed
- Fixed a Vitest suite not running when its lockfile, `package.json`, Vite or Vitest configuration or `tsconfig.json` changed, and a shared module outside every suite's directory being dropped: it is passed to each suite's `related`
- Fixed a parallel worker that finished without reporting a test, or reported one it was not given, still letting the run count as complete; `--repeat` repetitions are each accounted for
- Fixed a coverage worker on Windows starting without its driver: it is loaded by the bare name, `extension=pcov` or `zend_extension=xdebug`, which every platform resolves
- Changed a worker to read the run history only when ordering by `defects` or `duration`

## v1.3.0 — 2026-11-15

- Added a file and line to every problem in the console, TestDox, Markdown and PDF reports: an assertion names the line in the test file, and an error names the line where it was thrown
- Added named coverage targets: `--coverage-target Name=path` on the command line and `->coverageTarget('Name', 'path', min: 100)` in `crucible.php`
- Added `--coverage-min` (alias `--mins`): a named target's own floor, written `Name:pct`, `Name1,Name2:pct`, or `@file` for one floor per line
- Changed a run with named targets to judge each target on its own slice, so a passing pooled total cannot hide a module under its floor; a target's own floor wins over `--coverage-min`, which wins over `--min`

## v1.2.0 — 2026-10-26

- Fixed a mutant in a `match` arm escaping although a test kills it: coverage marks an arm's line unrun whenever another arm matched, so the mutant now runs the tests of every line of its `match`
- Fixed a mutant that cannot compile printing PHP's fatal error and stack trace between the progress lines: it is an errored verdict whose reason is printed on one line
- Added the changed lines under each escaped mutant, so a line with several operators says which change survived
- Added the errored, timed-out and not-covered mutants to the `mutate` report, listed as escaped ones are
- Changed `mutate` to print each escaped mutant once: one printed while running is left out of the final list
- Changed `mutation-verdicts.ndjson` to hold only the verdicts of the last run that finished, one line per mutant
- Added `manifest.json` to the cache directory, listing each artifact with its format version and hash, and the formats' specification in spec/cache-formats.md
- Changed `coverage-lines.json` and `coverage-map.json` to version 2, recording each file's hash; a version 1 map is read as empty, so the first coverage run after updating rebuilds it
- Changed `mutate` to warn about stale coverage by each covered file's hash instead of its modification time
- Fixed a mock whose `with()` rejected a call failing as if the method had never been called: the call fails when it is made, naming the parameter, as PHPUnit does
- Changed mock failures to PHPUnit's words: `was expected to be invoked once but was never invoked`, `was not expected to be called more than once, actually called 2 times`, with the call written as PHPUnit shortens it
- Fixed `atMost()` failing at the call that exceeded it: it is judged when the test ends, as PHPUnit does
- Fixed an omitted optional argument failing a mock's `with()` that names its default: it is compared as the default, as PHPUnit does
- Fixed a mock's rejected call that the code under test caught passing the test: it is reported again when the test ends
- Added `--observe-types`: the suite runs with the source watched, every phpdoc a test proves wrong fails the run with the value that disproved it, and the types each function received and returned are written to `observed-types.json`
- Fixed `toMatchShape()` and `assertMatchesShape()` refusing a trait's name as a `class-string`, which PHPStan accepts
- Fixed `class-string<A&B>` and `class-string<A|B>` refusing every value: a class of the intersection or union is accepted, as PHPStan does
- Fixed the unnamed rows of a data provider being numbered with the named ones counted: they are `#0`, `#1` among themselves, across providers and `#[TestWith]`, as PHPUnit numbers them, so their ids, filters and cached results change
- Changed `--list-tests` to name each data set, and the listings to PHPUnit's headings, counts, `default` group and absolute file paths
- Changed `--testdox-text` to write PHPUnit's file: the class in the heading, `[x]` and `[ ]` marks, no tally
- Changed every assertion's failure to PHPUnit's sentence, values written as PHPUnit writes them: `7 matches expected 8`, `Array &0 [...] contains only values of type "int"`, `file "/tmp/x" exists`
- Fixed `assertInstanceOf()` with a type that does not exist, and `assertCount()` of a generator, failing instead of erroring as PHPUnit does
- Changed a negated Pest expectation to fail in Pest's words, `Expecting 'a' not to be 'a'.`, and `toBeBetween()`, `toBeIn()`, `toHaveLength()`, `toMatchArray()`, `toMatchObject()`, `toHaveKey()` and `toThrow()` to Pest's failures
- Fixed `not->toHaveKey($key, $value)` failing when the key holds another value: it passes, as in Pest
- Fixed an unknown method on an expectation of a value that is no object failing instead of throwing Pest's `BadMethodCallException`
- Changed `createStub()` to make a stub as PHPUnit does, with no `expects()` and no `with()`, and a mock to refuse a second `with()` for one method with PHPUnit's `MethodParametersAlreadyConfiguredForAnotherMatcherException`
- Fixed `createStubForIntersectionOfInterfaces()` making a mock
- Changed a `table()` case's name and a property's counterexample to write a value passed to a `#[\SensitiveParameter]` as PHP's trace writes it, `Object(SensitiveParameterValue)`
- Fixed a coverage score being rounded up, so 99.996% passed `--min=100`: it is floored at two places as PHPUnit prints it, and the floor is compared as displayed (from laravel-module-test)
- Added the missed lines to each row of the coverage text report, as runs: `12..18, 30` (from laravel-module-test)
- Added `--coverage-target <path>`, which reports and judges `--min` on the files under a path alone (from laravel-module-test)

## v1.1.8 — 2026-10-24

- Fixed `->env()` replacing a variable the environment already defines: it is kept unless `force: true`, and `$_SERVER` is never written, as PHPUnit's `<env>` does; `migrate-config` carries `force="true"` over
- Fixed parallel workers starting from the configured env values instead of what the user exported, so a parallel run sees what a serial one sees
- Added test files and directories as arguments, `crucible tests/Unit/UserTest.php`, run instead of the configured suites as PHPUnit runs them; a path that does not exist stops the run with exit 2
- Fixed a run with errors or failures ending without a verdict: the last line is `ERRORS!`, `FAILURES!`, `OK, but there were issues!`, `OK, but some tests were skipped!` or `OK`, in PHPUnit's order of precedence
- Changed the console to leave out the passed-tests tree when no test passed
- Added the deprecations, notices and warnings a run fails on, listed before the tally with every test that triggered each, as PHPUnit lists them without `--display-*`
- Changed the progress line to mark a passing test that triggered a warning, deprecation or notice with `W`, `D` or `N`, as PHPUnit does
- Added `expectOutputString()`, `expectOutputRegex()`, `getActualOutputForAssertion()`, `output()`, `expectsOutput()` and `hasUnexpectedOutput()` to the phpunit and pest dialects
- Added `beStrictAboutOutputDuringTests()`, which `migrate-config` now translates instead of listing it as not migrated
- Changed unexpected output under `--disallow-test-output` to be printed as well as reported, in PHPUnit's words
- Added `$this->output()` without taking the name from a test class: an `output()` method of its own, any signature or visibility, still wins
- Fixed the regular-expression assertions' failure message to say `matches PCRE pattern`, as PHPUnit's does
- Changed `No tests found` to say how many test classes were left out because they extend PHPUnit's TestCase with the compatibility aliases off
- Changed `migrate-config` to add `->phpunitCompatibility()` when phpunit/phpunit is installed
- Fixed PHPStan's result cache keeping an old answer after a `Pest.php` was created, changed or deleted: each Pest file records the `Pest.php` files that decide its `$this`, and only those files are re-analysed
- Fixed `->in(__DIR__)` in `Pest.php` being ignored by the PHPStan extension, which typed `$this` as the base test case
- Changed `toBeList()` and `assertIsList()` to narrow as PHPStan reads `array_is_list()` with phpstan-src#6800, on every supported version: `array{}` for a string-keyed array, never where no value can pass
- Changed the PHPStan extension's minimum to PHPStan 2.3, declared as a Composer conflict: PHPStan 2.1 and 2.2 no longer get releases, and the cache tracking uses PHPStan 2.3's file dependencies

## v1.1.7 — 2026-10-20

- Fixed a `beforeEach` or `afterEach` inside `describe()` running for every test of the file, tests declared before the block included: it runs for the tests of its block and the blocks nested in it, in declaration order with the file's hooks, as Pest does (Hallmark cross-check)
- Fixed `beforeAll` and `afterAll` inside `describe()` being accepted and run once for the whole file: the file is refused at collection with Pest's message, and nothing in it runs
- Fixed a global `afterEach` from `Pest.php` running after the file's own: it runs before it, as Pest does
- Fixed `->in(__DIR__)` in `Pest.php` matching nothing, so its class, traits, groups and hooks were silently dropped: an absolute path selects the files beneath it, as Pest does

## v1.1.6 — 2026-10-20

- Fixed a constraint's additional failure description being joined to the failure sentence: it now starts a line of its own, as PHPUnit writes it (Hallmark cross-check)
- Fixed `--report`, `--log-markdown` and `--log-pdf` into a missing directory ending the run in an uncaught fatal, exit 255, after every test had run: the directory is created before the run, and a report that still cannot be written is named after the summary (Hallmark cross-check)
- Fixed the coverage file writers printing PHP's warning and then "written to" for a file they had not written: a missing directory is created, and a file that cannot be written is named instead (Hallmark cross-check)
- Changed `--log-junit`, `--log-teamcity`, `--testdox-text`, `--testdox-html` and `--log-events-json` to create a missing directory, as PHPUnit does for its own; `--log-otr` and the text event logs still refuse, as PHPUnit's do (Hallmark cross-check)
- Fixed an unmet exception expectation failing in Crucible's own words: messages and patterns are quoted as PHPUnit exports them, a code mismatch names the expected code, and a wrong exception type says where it was thrown (Hallmark cross-check)

## v1.1.5 — 2026-10-17

- Fixed a stock Laravel + Pest project stopping `compat-check` at exit 255 when `pestphp/pest-plugin-laravel` is installed: the prelude now defines Crucible's `expect()` before the plugin loads, and the plugin's helpers such as `get()` and `actingAs()` work (Laravents cross-check)
- Added `test()` with no arguments, which returns the running test, as under Pest (Laravents cross-check)
- Fixed `pest()->extend()` refusing an abstract class, such as the `TestCase` the Laravel skeleton ships (Laravents cross-check)
- Fixed `expect()` with no argument, the form `pest --init` writes for `expect()->extend()` (Laravents cross-check)
- Added the closure and exception forms of `toThrow()`, as Pest takes them (Laravents cross-check)
- Fixed a negated `toThrow()` failing whenever anything was thrown: it now fails only when the thrown exception matches (Laravents cross-check)
- Added `Quirk::ThrowInterfaceAsMessage`, which restores Pest's reading of `toThrow(Throwable::class)` as a message fragment; Crucible matches the interface by default (Laravents cross-check)
- Fixed `compat-check` hiding why Crucible ran no tests: it now prints Crucible's own error, shows each drifted test's failure message, and blames PHPUnit's internals only when PHPUnit is the oracle (Laravents cross-check)
- Fixed `compat-check` printing a PHP warning when the oracle wrote no JUnit file (Laravents cross-check)
- Fixed PHPStan reporting an undefined property for `$this->user` set in a `beforeEach`: it now has the type the hook gave it, as pest-plugin-phpstan reports (Laravents cross-check)
- Fixed `--coverage` on pcov dying with signal 11 and no message on every PHP version: the driver now collects in `all` mode, which works with pcov 1.0.12 (Laravents cross-check)
- Added the signal, and the coverage driver, to the reason given for tests whose worker was killed by a signal (Laravents cross-check)
- Fixed the missing-driver hint saying upstream pcov does not build on PHP 8.5 (Laravents cross-check)
- Fixed `crucible mutate` calling mutants on attribute arguments, property defaults and inner array lines not covered: they now run against their method's tests, after the next `--coverage` run (Laravents cross-check)
- Fixed a resumed `crucible mutate` keeping an old verdict when only a fixture changed; the first resume after upgrading re-judges every mutant once (Laravents cross-check)
- Fixed impact selection missing a trait used with an adaptation block and the class type of a PHP 8.3 typed constant (Laravents cross-check)
- Improved the manual: the inline dialect in full, which dialect to choose, the three jobs of `->source()`, arch rules without `not`, and where the Pest dialect differs from Pest (Laravents cross-check)
- Changed RESEARCH.md into a study of each source and what Crucible took from it, and corrected its claim that Crucible's sharding balances by duration

## v1.1.4 — 2026-10-14

- Fixed `--reproducible` leaving test durations, the slowest-tests list and a time-based order in Markdown and PDF reports, and a time-based problem order in JSON and SARIF, so two runs of the same code, sequential or parallel, now write identical bytes
- Changed `--reproducible` to omit the JSON report's run duration and the SARIF end time instead of writing a zero
- Fixed a Laravel suite erroring in every test, and `compat-check` reporting each as drift, while `phpunit/phpunit` is still installed with `->phpunitCompatibility()` on
- Added `--report`, the `--log-*` options and `--reproducible` to `php artisan test`, passed through to Crucible
- Fixed the Laravel bridge tests failing on a repository checkout whose Laravel oracle needs a newer PHP than the one running them: they now skip, named, on Composer's own platform check

## v1.1.3 — 2026-10-10

- Added `TestCase::expectExceptionObject()`, which sets the class, message and code of one exception object as three expectations, as under PHPUnit
- Fixed `expectNotToPerformAssertions()` still reporting the test risky: the runner now reads the declaration, in the PHPUnit and Pest dialects, and it does not carry into the next test
- Fixed `composer dump-autoload --strict-psr` failing on a repository checkout: one test class had a namespace outside the autoload rule, and the nested-vendor fixtures are now excluded from the classmap
- Added Ko-fi as a funding channel beside Open Collective

## v1.1.2 — 2026-10-06

- Fixed `crucible mutate` reporting every mutant of a class as escaped when its file carries an inline test (`#[Check]` or `@crucible`): discovery required the file by path, so the original class loaded before the mutant; a cold worker now loads the mutant in its place
- Fixed `crucible mutate` stopping at discovery on a Laravel suite with `Class "PHPUnit\Framework\TestCase" not found`: it now loads the compatibility aliases and applies the configuration's bootstrap, ini, env and constants before discovery, as a run does, so feature tests boot on the configuration's env instead of the project's `.env`
- Fixed Pest files whose `uses()` class extends the real PHPUnit `TestCase`, such as Orchestra Testbench's, under PHPUnit 13.4: its configuration was never set, as 13.4 passes the event emitter where earlier releases took none
- Added Open Collective as a funding channel

## v1.1.1 — 2026-09-26

- Fixed type tests and `crucible lint-inline` failing to start PHPStan on Windows, and `crucible flakes`, `compat-check` runs, Vitest suites, the watch loop and Git coverage details opening `/dev/null` there; Windows uses its own null device, `NUL`

## v1.1.0 — 2026-09-26

- Fixed a process-isolated test's STDERR, which was dropped: the test now errors with that output as its message, as under PHPUnit, and a `--parallel` worker's STDERR reaches the console
- Added a name to the folded Vitest suite, so `--testsuite` and `--exclude-testsuite` select it; `--filter` and the other PHP-only selections leave it out unless `--testsuite` names it, and a named suite takes `--filter` through to Vitest
- Added Crucible's PHPStan extension to `phpstan/extension-installer`, which now loads it with no include line; `crucible phpstan-init` says so, and flags a manual include that would load it twice
- Fixed the console printing OK when a run-scoped check failed
- Added a generic `expect()` for PHPStan, type matchers that narrow the chain, and narrowing for `assertArrayHasKey`, `assertObjectHasProperty` and `assertContainsOnlyInstancesOf`, held against phpstan-phpunit and pest-plugin-phpstan by a new probe
- Changed the PHPStan extension to leave `check()`, `property()` and `table()` to `phpstan/crucible-dialect.neon`, so a project's own global `check()` is no longer read as Crucible's
- Added subject narrowing: after `expect($x)->toBeString();` PHPStan knows `$x` is a string
- Added PHPStan rules that check dataset rows, `#[TestWith]`, `#[TestWithJson]`, `#[Check]` and `table()` rows against the parameters they feed
- Added type tests: `assertType()` in `*.types.php` files, run as a folded `types` suite, with a negative form
- Added `toMatchShape()` and `assertMatchesShape()` for PHPStan type strings, narrowed for the analyser
- Added `Gen::of()`, `Gen::shape()`, and minimum lengths for `Gen::string()` and `Gen::listOf()`
- Fixed Pest files never getting their class's `setUpBeforeClass()` and `tearDownAfterClass()`, which made suites on Orchestra Testbench slow down with every test
- Fixed discovery stalling when the real PHPUnit is installed
- Fixed a dataset that throws while it is built stopping the whole run; it now fails its own test
- Fixed a `crucible.php` that throws ending in a fatal error instead of a message
- Added declared equivalent mutants and a mutator that drops a returned array's keys
- Changed `toBeArray()` to narrow as `assertIsArray()` does, and `assertIsList()` to narrow as `toBeList()` does, so each guarantee has one reading in both dialects
- Added `assertNativeType()`, `assertSuperType()` and `assertVariableCertainty()` to type tests; a `*.types.php` file with no assertion is now an error
- Fixed `crucible lint-inline` reporting clean when PHPStan could not load its configuration
- Fixed group and comma-list imports (`use Tests\{TestCase, …};`, `use A, B;`) in Pest files, which gave closures' `$this` a class that does not exist
- Fixed PHPStan narrowing after a spread `each`: `expect($list)->each()->toBeString()` no longer makes the chain a string or `*NEVER*`, and what the matchers before a spread or an `and()` proved about the variable now holds
- Fixed `Gen::of()` drawing an empty array for a `non-empty-array` whose keys PHP rewrites; it now draws again, and refuses a type no array can fill, such as `non-empty-array<numeric-string, int>`
- Fixed `@crucible-equivalent: reason` and `crucible-equivalent-line: reason`: a colon before the reason lost it, or hid the marker
- Fixed `crucible mutate` reporting every mutant of a file as escaped when the file declared an equivalent mutant: discovery read `@crucible-equivalent` as a doctest and loaded the file, so a mutated class could never take the original's place
- Added the Crucible logo to the HTML coverage report, the testdox page and the PDF report; the PDF reads `[logo] Test report [OK]` on one line with the byline beneath, and the testdox page shows the run's verdict and colours only each test's mark
- Fixed long lines in the PDF report running past the right margin; paragraphs now wrap
- Fixed `--log-pdf` or `--log-markdown` together with `--report` for the same format dropping one of the two paths silently; asking one format for two paths is now refused

## v1.0.1 — 2026-09-25

- Fixed the hook order to match PHPUnit: at the default priority, `#[Before]` hooks now run before `setUp()` and `#[After]` hooks after `tearDown()`, so a trait's `#[Before]` reset no longer wipes what `setUp()` wired
- Fixed `#[After]` and `#[PostCondition]` priorities, which ran lowest first: the highest priority now runs first in every phase, and hooks at equal priority run in PHPUnit's order
- Added `assertPreConditions()` and `assertPostConditions()` to `TestCase`, called where PHPUnit calls them
- Added `TestCase::transformException()`, so Orchestra Testbench test cases load instead of stopping discovery with a fatal error
- Added the `PHPUnit\Framework\Constraint\LogicalNot` alias, so Laravel's `assertDatabaseMissing()` runs
- Fixed failure messages from framework constraints such as Laravel's `HasInDatabase`: they now read "Failed asserting that …" as under PHPUnit, and the negated form no longer rewrites the data it quotes
- Fixed the release check, which rejected a two-part tag such as `v1.0` although Composer reads it as 1.0.0
- Updated the phpcpd-next benchmark from v1.4 to v2.0, its latest release: 710 of 710 tests pass, as under PHPUnit

## v1.0 — 2026-09-23

- Added the engine's core: immutable events with a versioned NDJSON event stream as the one output substrate, typed `crucible.php` configuration instead of XML, and a closed command line where an unknown option is an error
- Added the complete PHPUnit 13 assertion surface — all 176 `assert*` methods, each checked against the real PHPUnit case by case
- Added test doubles with full `getMockBuilder()` parity and partial doubles, plus a Mockery-compatible grammar that runs Pest's mocking chapter without installing Mockery
- Added the supervisor/worker runner: `--parallel N` scheduled longest-first with the event stream as the IPC, state isolation between tests, and defects-first ordering from the result cache
- Added `crucible migrate-config`, the one sanctioned `phpunit.xml` read, which reports anything it cannot translate instead of dropping it
- Added the Pest dialect: `*.pest.php` runs natively beside PHPUnit classes in the same run, with the full `expect()` surface, datasets and hooks
- Added the Crucible and inline dialects: `check()`, `table()`, `#[Check]` tables on methods and `@crucible` doctests
- Added impact selection — `--changed`, `--related`, `--dirty` — and `--watch` with sticky failures, plus declared `->impactRule()`s and a `->retrigger()` endpoint for build tools
- Added flakiness tooling: `--retries`, where a pass on retry is flaky rather than a pass, quarantine, and `crucible flakes` to hunt order-dependent tests
- Added property-based testing with integrated shrinking and a failure database that replays stored counterexamples first
- Added coverage — line, branch and path — with Clover, OpenClover, Cobertura, Crap4J, HTML, XML, text and PHP reports, and `--min` as the CI gate
- Added `crucible mutate`: mutation testing over the covered source, running each mutant's covering tests fastest-first
- Added snapshot testing, in files and inline, recorded only when `--update-snapshots` asks for it
- Added the PHPStan extension: assertion narrowing, dialect files analysed at level max, and `crucible lint-inline`
- Added the browser tier: Playwright driven from pure PHP, the server inside the test process, Inertia and Livewire assertions, and `waitForNetworkIdle()`
- Added `->vitest()`: a JavaScript suite folded into the same run, report and exit code, narrowed through `vitest related` under `--changed`
- Added architecture rules with `arch()`, scoped to the configured `->source()`
- Added `crucible compat-check`: the real incumbent and Crucible on the same suite, with `--auto-fix` and `--revert`
- Added report formats, subscribers and progress views as registered plugins, listed by `crucible extensions`, with the map view as the default wherever a screen can be redrawn
- Added `crucible manual` in the terminal and as a PDF, shell completion for bash, zsh and fish, and a man page
- Added machine-readable reports held to the incumbent's own writers, by shape and by value — Clover, OpenClover, Cobertura, Crap4J, JUnit and TeamCity
- Added a discovery guard: a Pest file that declares no test is refused rather than reported green
- Added executable conformance: 19 fixture suites run against the real `phpunit` and `mockery`, asserting identical outcomes, counts and exit codes
