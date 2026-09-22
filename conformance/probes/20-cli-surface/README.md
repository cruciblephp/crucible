# Probe: the CLI option surface

The README names "assertions, attributes, **CLI options**, defaults, exit codes" as the
behavioral spec Crucible implements. The assertion half of that claim is audited
method-by-method in D-070. The CLI half is not audited anywhere.

```bash
php conformance/probes/20-cli-surface/compare.php
```

It asks both binaries for their own `--help`, then probes every option the oracle lists
against Crucible's real parser — because a help screen is a claim and the parser is the fact.

## Result, PHPUnit 13.3.1 on 2026-08-19

146 oracle options; **120 rejected** by `CliOptions` as unknown. Reproducible: the
refusals survive the `=value` form and a scratch working directory, and `--colors`,
which Crucible does support, is accepted — so the count is the parser's, not the probe's.

| Family | Count | Reading |
|---|---|---|
| XML configuration | 5 | Architecture. `crucible.php` and `migrate-config` are the answer, by D-002/D-024. |
| Coverage formats | 15 | Mostly deliberate — Crucible ships clover, cobertura, HTML, branch. |
| `--do-not-fail-on-*` / finer `--fail-on-*` | 21 | Crucible has seven `--fail-on-*`; the negations and the PHPUnit-issue-specific ones are absent. |
| `--display-*` | 9 | No equivalent surface. |
| `--list-*` | 6 | No equivalent. IDEs and CI tooling call these. |
| `--stop-on-*` | 6 | Crucible has defect/error/failure; risky, skipped, warning, notice, deprecation, incomplete are absent. |
| `--testdox-*` variants | 3 | `--testdox` exists; the file-writing variants do not. |
| Everything else | 55 | Mixed — see below. |

## Why a green conformance run did not catch this

It could not: the two measure different things. `composer conformance` runs 18 fixtures
against both binaries and asserts identical outcomes, exit codes, sequences, and summary
counts — under the arguments each fixture declares. Across all 18 `options.json` files
those arguments are five in total: `--order-by=reverse`, `--filter`, `--group`,
`--exclude-group`, `--stop-on-failure`. All five are registered. Conformance tests
*behaviour under the options it passes*; nothing in the repository tested the *surface*
until this probe, which is why the number appears for the first time here rather than as
a regression.

## The ledger

`compare.php` answers "how many does the parser refuse". That number cannot be worked
from. `ledger.php` classifies every one of them by what it would cost:

```bash
php conformance/probes/20-cli-surface/ledger.php          # the tally
php conformance/probes/20-cli-surface/ledger.php alias    # one verdict, with evidence
```

| Verdict | At the audit | Now | Meaning |
|---|---:|---:|---|
| `alias` | 17 | **0** | The capability ships; only this spelling is missing. |
| `wiring` | 86 | 86 | The machinery ships; a target, negation, or resolution is missing. |
| `new` | 13 | 13 | Needs machinery Crucible does not have. |
| `declined` | 3 | 3 | The design record assessed the capability and chose against it. |

The shape of that table is the finding. Only three of the 120 are refusals on principle,
and only 13 need something built: `--enforce-time-limit` / `--default-time-limit` (probe 19),
path coverage, output capture, telemetry, run history, the php.ini advisories, and OTR.
The other 103 are surface over machinery that already exists — `CoversClass` and `UsesClass`
attributes behind `--covers` / `--uses`, `RunInSeparateProcess` behind `--process-isolation`,
`IssueCollector` and `IssueKind` behind the nine `--display-*`, `DeprecationBaseline` behind
the three baseline options, `XmlMigrator` behind `--migrate-configuration`, and the NDJSON
event stream behind `--log-events-text`.

Each entry carries the capability it maps to, so the ledger can be argued with rather than
believed. It also checks itself against the parser on every run and exits non-zero on drift:
an option the parser refuses that no entry covers, an entry the parser now accepts, or a
`COVERED` entry that has started being refused again.

## Closed so far

**Every `alias` entry, plus `--retry` — 18 options.** The pure rewrites (`--random-order`,
`--reverse-order`, `--branch-coverage`, `--migrate-configuration`) go through an `ALIASES`
table applied before the parse loop, so there is one option in the registry, one code path,
and no second meaning to keep in sync. The rest resolve against the configuration the same
way every other CLI value does — `??`, never a sentinel.

The five a test can actually observe — `--bootstrap` and the four backup/strict switches —
ride the worker manifest as one `Overrides` object, because a worker loads the project's
configuration from disk and would otherwise run with the settings the parent was told to
change: `--parallel` has to behave like its sequential twin. Verified both ways.

The six `--do-not-fail-on-*` become the off half of the `failOn*` tri-states, so they beat a
`crucible.php` that turns the policy on; passing both spellings is an error rather than a
precedence puzzle.

`--retry` — the oracle's spelling, now accepted. It is **not** a rename: PHPUnit counts
total attempts ("attempt each test up to N times"), Crucible's `--retries` counts the extra
ones, following nextest, which is also what `->retries()` and `#[Retry]` mean. Moving either
vocabulary onto the other's would have silently changed the meaning of every existing
`#[Retry(N)]`, so the conversion happens at parse time (`--retry 3` == `--retries 2`) and both
spellings stay exact. Passing both is an error rather than a precedence puzzle.

## Read the record by capability, not by flag spelling

Checking whether something is already decided means searching the design record for the
*capability*, not for the option string. The `vendor/bin/phpunit` shim is the cautionary case:
grepping for the flag finds nothing, but DESIGN.md D-029 assessed it in full and rejected it
on a concrete finding — Collision's `TestCommand` hard-depends on the PHPUnit tree and fatals
on `SebastianBergmann\Environment` once `phpunit/phpunit` leaves the vendor, so the answer
shipped as a bridge package with a Crucible-backed `php artisan test`, not as a binary.

Searched by capability, these have no entry in the record: test listing, issue display,
the `--stop-on-*` variants beyond defect/error/failure, test-file suffixes, text coverage,
and process isolation as a run-wide switch. `bootstrap` and `baseline` appear only as
configuration, never as CLI surface.

## What this probe is for

To do for the CLI what D-070 did for the assertion surface: classify every one, publish the
result, and keep the classification honest by checking it against the parser on every run.

An audited gap is a feature of this project. An undeclared one is the thing D-070 exists to
prevent — and until the ledger existed, all 120 of these were undeclared.
