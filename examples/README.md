# Examples

Every file here is a **real test that the suite runs**. `crucible.php` registers this directory
as a test suite of its own, so an example that stopped working turns the suite red.

That is the point. Documentation that is merely *checked by a human at review time* drifts
away from the code silently — this cannot, because it is the code.

```bash
crucible --testsuite examples
```

| Directory | Shows |
|---|---|
| [`01-phpunit-dialect`](01-phpunit-dialect/CalculatorTest.php) | The drop-in dialect: `TestCase`, `#[DataProvider]`, `#[Group]`, `#[Depends]` |
| [`02-pest-dialect`](02-pest-dialect/basics.pest.php) | `test`/`it`/`describe`, hooks, `expect()` chains, datasets, `->fails()` |
| [`03-doubles`](03-doubles/PaymentsTest.php) | Stubs vs mocks, expectations, defaults for unconfigured methods |
| [`04-architecture`](04-architecture/rules.pest.php) | `arch()` rules — targeting, layering, `ignoring()` |

The architecture examples are not toys: they run against Crucible's own source, which is what
`->source()` points at in this repository. If Crucible's layering drifts, those examples fail.

[`MANUAL.md`](../MANUAL.md) explains what these files demonstrate; this directory proves it.

## A note on style

These files are excluded from Pint. Crucible's own source uses `\ceil()` and `\arch()` — the
leading backslash is a project style rule about global-function resolution — but nobody
writes that in their own test suite, and documentation that shows you something you would
never type is documentation that lies a little. They read the way you would write them.
