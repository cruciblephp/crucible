# Probe: test-size time limits

`#[Small]`, `#[Medium]` and `#[Large]` carry two meanings in the spec. Crucible implements
one — `Scheduler::sizeWeight()` reads all three as ordering weights for
`ExecutionOrder::SizeAscending`. The other is a per-size time limit, and nothing in `src/`
enforces it. The attributes are accepted and the limit is silently dropped.

Measured against PHPUnit 13.3.1 on 2026-08-19, PHP 8.5.9, pcntl present.

## What the oracle does

```bash
php conformance/phpunit-oracle.php --enforce-time-limit conformance/probes/19-time-limits/tests
```

```
.R.R                                                                4 / 4 (100%)

1) SmallSizeTest::testOverrunsTheSmallLimit
* This test was aborted after 1 second
* This test did not perform any assertions

2) UnsizedTest::testOverrunsTheSmallLimit
* This test was aborted after 1 second

OK, but there were issues!
Tests: 4, Assertions: 2, Risky: 2.        exit 0
```

Four answers, none of them assumed:

1. **The outcome is `risky`, and the run still exits 0.** An over-running test does not fail
   the build unless something else makes it. `Outcome::Risky` already exists, so no new
   outcome is needed — this lands inside the model as it stands.
2. **The message is `This test was aborted after 1 second`.** The abort also produces a
   consequential *did not perform any assertions*, because the test is cut before its
   assertion runs. Both are observable; a reporter shows both.
3. **Limits are per size, not global.** `MediumSizeTest` sleeps 2 s — past the small limit,
   inside its own — and passes.
4. **An unsized test is limited too, at 1 second.** This is the one that matters most:
   most real suites declare no size at all, so `--enforce-time-limit` applies a 1 s limit to
   nearly everything by default.

Two flags, confirmed separately:

- **Without `--enforce-time-limit`, nothing is enforced** — `OK (4 tests, 4 assertions)`.
  Entirely opt-in.
- **`--default-time-limit=5` moves only the unsized limit.** The unsized test then passes and
  `SmallSizeTest` still aborts after 1 second, which is the per-size reading confirmed from
  the other direction.

**Not measured:** behaviour when `ext-pcntl` is absent. pcntl is compiled into the PHP on this
machine and cannot be unloaded, so the oracle's degradation path — silent, or stated — is still
an open question. It matters, because it is the deciding input for the note below.

## Where enforcement goes in Crucible

The supervisor, not the worker. It already ticks on `Supervisor::await()`'s 50 ms
`stream_select` timeout, already sees `test:start` cross the stream so the in-flight test and
its start time are free, and `reportLost()` already synthesizes a `TestStarted` +
`TestFinished` pair for a test that never reported. The one change is
`WorkerProcess::terminate()`, which hard-codes signal 9: take the signal as an argument so a
deadline can send TERM, wait a grace, then kill.

That placement is what makes the feature portable. Whatever the oracle does without pcntl, a
supervisor-side deadline needs no extension at all, and the CI matrix runs `windows-latest`.

## Landing it

`conformance/run.php` globs `fixtures/*`, so this directory is outside `composer conformance`
and cannot turn the gate red. Shaped as a fixture, so landing it is one move:

```bash
git mv conformance/probes/19-time-limits conformance/fixtures/19-time-limits
```

Do that once the behaviour is implemented — or once the decision is to decline it, with the
fixture pinning whatever Crucible does instead. Note that `--enforce-time-limit` is currently
rejected by `CliOptions` as an unknown option, so the Crucible side produces no events at all
until the flag exists.
