# Crucible PHP — Improvements

Findings from a code-quality pass (PHPStan level max, Pint/PER-CS 2.0, self-hosted suite,
dependency audit, and manual review) on 2026-07-23. Nothing here blocks release — all
automated gates are green. These are the actionable structural items.

## Automated gates (baseline, all green)

| Gate | Result |
|---|---|
| PHPStan (`level: max` + strict-rules + deprecation-rules) | 0 errors, 467 files |
| Pint (PER-CS 2.0) | passed, 0 files need formatting |
| Self-hosted suite (`php crucible`) | 674/674 passing, 32 skipped (missing optional Playwright/Xdebug-coverage oracles) |
| `composer audit` | no known vulnerabilities |

## Findings

1. ~~**`src/CLI/Application.php` is a 2,462-line god class.**~~ **Resolved, 2026-07-28.**
   Split by subcommand family into `src/CLI/Commands/`: `InitCommand`, `MigrateConfigCommand`,
   `PhpstanInitCommand`, `WatchCommand`, `FlakesCommand`, `LintInlineCommand`,
   `MutationCommand` (mutation-index + mutate — sibling commands sharing internal helpers),
   and `RunTestsCommand` (the default run path plus the worker protocol, `execute()` +
   `worker()` — by far the largest single family). The one genuinely cross-family helper
   (`absolute()`, called from 4+ unrelated families) moved to `src/CLI/Paths.php`; everything
   else that was called more than once turned out to be cohesive within a single family (e.g.
   `runTests()` and `runWorker()` sharing 6 helpers, since the worker path *is* the run path's
   child-process half) and moved wholesale, no new shared abstraction needed. `Application::run()`
   is now an 186-line pure dispatcher — every subcommand's own body verified byte-identical or
   structurally identical against captured baselines (most had zero prior automated coverage:
   no CI job or composer script exercises `mutate`, `flakes`, `watch`, `phpstan-init`,
   `lint-inline`, `migrate-config`, or `--init` at all), plus direct verification that worker
   mode still spawns real child processes (a scratch fixture using
   `#[RunInSeparateProcess]` confirmed a different PID, and that a failure inside the child
   still propagates and fails the run). Full `composer check` green on real PHP 8.3/8.4/8.5.

   **Follow-up, same day**: `RunTestsCommand` was still ~1,350 lines on its own. Split out its
   post-run-reporting cluster — `printChecks`, `printFlakiness`, `printFailuresOutsideDiff`,
   `thresholdBreaches`, `coverageScope`, `reportCoverage`, `exitCode` — into `src/CLI/
   PostRunReport.php`, since all seven only print or compute from a run that already finished
   and share no state with the orchestration in `execute()`/`worker()` beyond their explicit
   parameters. Left in `RunTestsCommand`: the run orchestration itself and the worker protocol
   — neither has a clean seam to split further without adding shared mutable state across the
   pieces. `RunTestsCommand` is now ~1,020 lines; `PostRunReport` ~340. Verified: the 746-test
   self-hosted suite (which exercises `execute()` on every run) plus real PHP 8.3/8.4 both
   unchanged at 713 passed/0 failed, full `composer check` green.

2. ~~**`src/Reporting/PdfWriter.php` (1,561 lines) mixes two concerns.**~~ **Resolved,
   2026-07-28 — see `DESIGN.md` D-091.** Investigating the suggested fix (split into a
   PDF-primitives layer and a report-layout layer) surfaced the same duplicated/inconsistent
   logic across all 7 of `src/Reporting/`'s reporters, not just `PdfWriter`. Landed in two
   stages: a shared `RunModel` value object retired 4 independently-reimplemented
   classification/grouping computations, then a `Document`/`Block` content AST (new
   `src/Reporting/Document/` tree, `PdfPrimitives`+`PdfRenderer` split) replaced `PdfWriter`,
   `MarkdownWriter`, `TestDoxReporter`, and `ConsoleReporter`'s summary half with one shared
   content model and per-format renderers. `TeamCityReporter`/`JUnitXmlWriter` confirmed not
   to fit and stayed hand-written. Verified on real PHP 8.3/8.4/8.5, full `composer check`
   green.

3. ~~**`eval()` appears in 4 places**~~ **Superseded, 2026-08-31.** The count was wrong when
   written — there were five call sites, not four, and the fifth
   (`Bridge/PestSnapshots/SnapshotIdentityWrapper.php`) was the one nothing analysed. Both
   halves of this item are now closed, and not by the suggested fix.

   There is exactly **one** `eval()`, `Generated/GeneratedCode.php:66`, and
   `ApprovedEvalRule` holds the count there. **Five generators** reach it —
   `Double/Generator`, `Double/Mockery/MockeryContainer`, `Dialect/Pest/TraitComposer`,
   `Bridge/PestSnapshots/SnapshotIdentityWrapper`, `Dialect/Inline/InlineBuilder` — and
   `ApprovedGeneratorRule` holds THAT count, so a sixth cannot appear without registering.

   "PHPStan cannot see inside the generated code" is no longer true either.
   `composer analyse:generated` materialises every generated source and analyses it at level
   max, regenerated each run so it cannot pass vacuously, and reports what each generator
   contributed so a silent one fails the run. The expression seam is gated on the composed
   source PARSING instead, since its payload is the author's rather than Crucible's.

   The suggested fix — "make sure every `eval()` site has direct runtime test coverage" —
   would not have found the defects that were actually there. A test exercising the caller
   passes while the generated string is wrong; `nameWithDataSet()` was missing from the
   snapshot wrapper's output entirely, and the type tier found it, not the suite.

4. ~~**Minor dependency housekeeping.**~~ **Resolved, 2026-07-28 — not as minor as it
   looked.** Updated `phpstan/phpstan-strict-rules` (2.0.11 → 2.0.12), which pulled
   `phpstan/phpstan` along (2.2.5 → 2.2.6). That alone broke `composer refactor:check`:
   Rector reaches into `PHPStan\Parser\RichParser`'s private internals directly (not a
   stable API), and 2.2.6 changed its shape. Fixed by also updating `rector/rector`
   (2.5.7 → 2.5.8), which in turn enabled a genuinely new rule
   (`RemoveDefaultValueFromAssignedPropertyRector`) that fired on
   `tests/unit/Double/MockBuilderTest.php`'s `Brewer` fixture — a false positive, since that
   fixture's default property values are the observable pre-construction state the builder
   tests assert (`assertFalse($mock->constructed)` on a double that never ran the
   constructor). Skipped for that file in `rector.php`, same rationale as its two existing
   neighbors there. Full `composer check` green on real PHP 8.3/8.4/8.5 afterward.

## Discounted (not real issues)

- `Assert.php` (989 lines) and `Dialect/Pest/Expectation.php` (1,220 lines) are large,
  but their size tracks the breadth of the PHPUnit/Pest assertion API they mirror
  (one method per assertion), not accidental complexity.
- Compat classes (`PhpUnitCompatibility`, `MockeryCompatibility`) look untested by a
  static grep for their class name — they're wired in via `class_alias()`, so every
  test in the suite that uses the aliased PHPUnit-namespace API is exercising them
  indirectly. Not a real gap.
