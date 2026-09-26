# Changelog

Every release, as it ships. RELEASE.md is the full record of what shipped, each entry traced to its decision record.

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
