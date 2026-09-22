# 21 — `--coverage-xml` against the incumbent's writer

`--coverage-xml` has no schema. Neither PHPUnit nor php-code-coverage ships one, so
"is this document correct" cannot be answered the way the OTR log's can — there is
nothing to validate against.

What *can* be answered is the question a consumer actually has: would a tool that
reads php-code-coverage's XML recognise Crucible's? So this probe runs both writers
over one fixture and compares their **vocabulary** — every element with the attributes
it carries.

```bash
php -d xdebug.mode=coverage conformance/probes/21-coverage-xml/compare.php
```

Values are deliberately not compared. Two runs legitimately differ on timings, paths
and percentages; the shape is the contract.

Both divergence lists are recorded in `probe.php` with a reason each, and the probe
fails when reality differs from the record **in either direction** — a new divergence
fails, and so does one that quietly closed, because a stale exemption is how a
recorded gap becomes folklore.

Standing at 19 of the incumbent's 25 shapes. The remainder are not oversights:

| Shape | Why |
|---|---|
| `build[…phpunit…]` | the stamp names phpunit and its version; Crucible is not phpunit and will not claim to be |
| `tests`, `test[…status,time]` | per-test status and duration; `CoverageData` records which test touched which line, not how each test ended |
| `token[name]`, `line[no]`, `line[nr]` | the incumbent tokenizes `<source>`; Crucible emits the source text |

The first is a decision, not a gap. The other two are reachable if a consumer ever
needs them — the per-test one would mean carrying outcomes into `CoverageData`, and
the token dump is a second pass over source that `SourceAnalysis` already parses.
