# Changelog

Every release, as it ships. RELEASE.md is the full record of what shipped, each entry traced to its decision record.

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
