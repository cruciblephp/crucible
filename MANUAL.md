# Crucible PHP — the manual

Crucible is an independent test framework for PHP 8.3+ whose observable behavior targets
drop-in compatibility with PHPUnit 13 suites.

Every code sample in this manual comes from [`examples/`](examples), and those files are
**executed by the test suite**. An example that stopped working would turn the suite red, so
nothing here can quietly drift out of date.

- [`HELP.md`](HELP.md) — every command-line option
- [`RELEASE.md`](RELEASE.md) — what shipped, traced to its decision record
- [`DESIGN.md`](DESIGN.md) — why each thing is the way it is
- [`ORACLES.md`](ORACLES.md) — the real installs some tests run against

---

## Contents

1. [Install and first run](#1-install-and-first-run)
2. [Configuration](#2-configuration)
3. [Writing tests](#3-writing-tests) — the three dialects
4. [Assertions and `expect()`](#4-assertions-and-expect)
5. [Datasets](#5-datasets)
6. [Test doubles](#6-test-doubles)
7. [Architecture rules](#7-architecture-rules)
8. [The developer loop](#8-the-developer-loop) — watch, impact selection, retrigger
9. [Coverage](#9-coverage)
10. [Browser testing](#10-browser-testing)
11. [Mutation testing](#11-mutation-testing)
12. [JavaScript suites](#12-javascript-suites)
13. [Reporting and CI](#13-reporting-and-ci)
14. [Migrating from PHPUnit](#14-migrating-from-phpunit)
15. [The command itself](#15-the-command-itself) — the manual, PDF, shell completion

---

## 1. Install and first run

```bash
composer require --dev cruciblephp/crucible
./vendor/bin/crucible --init      # writes a crucible.php
./vendor/bin/crucible
```

Crucible needs **PHP 8.3+** and `ext-mbstring`. Nothing else at runtime — coverage extensions
are optional and only needed when you ask for coverage.

If the project already has a `phpunit.xml`, don't hand-write the configuration:

```bash
./vendor/bin/crucible migrate-config
```

This is the one place Crucible reads XML, and it never silently drops a setting it did not
understand — anything it cannot translate is reported.

---

## 2. Configuration

Configuration is typed PHP in `crucible.php`, not XML. Your editor completes it and PHPStan
checks it.

```php
<?php

use LucianoPereira\Crucible\Configuration\Crucible;

return Crucible::configure()
    ->bootstrap('vendor/autoload.php')
    ->testSuite('unit', 'tests/Unit')
    ->testSuite('feature', 'tests/Feature')
    ->source(include: ['src'])
    ->build();
```

`->source()` deserves attention: it states which directories hold **the code you own**.
Coverage reports on it, and architecture rules reason about it, so getting it right makes
two other features correct for free.

Some settings worth knowing early:

| Call | What it does |
|---|---|
| `->strict()` | Turn on every fail-on switch at once |
| `->executionOrder(ExecutionOrder::Defects)` | Failures first on every run |
| `->retries(2, Backoff::Exponential)` | Re-run failures; a pass on retry is **flaky**, not a pass |
| `->quarantine('tests/Flaky.php::sometimes')` | A known-flaky test whose failure does not affect the exit code |
| `->impactRule('resources/js', ['browser'])` | Files the dependency graph cannot reach (see §8) |
| `->browser(enabled: true)` | The browser tier (see §10) |
| `->vitest('resources/js')` | Fold a JavaScript suite into the same run (see §12) |

A CLI option always beats the same setting here.

---

## 3. Writing tests

Three dialects, mixed freely in one suite and one run. There is no mode to switch.

### The PHPUnit dialect — the drop-in one

A suite written for PHPUnit runs unchanged.
[`examples/01-phpunit-dialect`](examples/01-phpunit-dialect/CalculatorTest.php):

```php
final class CalculatorTest extends TestCase
{
    public function testAddsTwoNumbers(): void
    {
        $this->assertSame(4, 2 + 2);
    }
}
```

Attributes come from `LucianoPereira\Crucible\Attributes\*`, and with compatibility aliases
enabled `PHPUnit\Framework\*` resolves too — which is what makes an existing suite run with
`composer remove phpunit/phpunit` as the only change.

`#[Depends]` passes a return value along, and skips rather than fails when its dependency
did not pass. An unmet dependency reports as **untested**, not as a defect — Crucible keeps
"this did not run" separate from "this is broken".

### The Pest dialect — the expressive one

`*.pest.php` files run natively.
[`examples/02-pest-dialect`](examples/02-pest-dialect/basics.pest.php):

```php
test('a test is a description and a closure', function (): void {
    expect(2 + 2)->toBe(4);
});

it('rounds up', function (): void {
    expect((int) ceil(1.2))->toBe(2);
});

describe('expectations', function (): void {
    it('chains', function (): void {
        expect('crucible')->toBeString()->toHaveLength(8)->toStartWith('cru');
    });
});
```

Hooks are `beforeEach` / `afterEach` / `beforeAll` / `afterAll`, and a `Pest.php` beside or
above a file applies to everything beneath it.

Crucible **refuses to run** when the real Pest is installed, rather than fighting it for the
same functions — and the refusal is not a preference. Pest ships `test()`, `it()` and `expect()`
through Composer's `files` autoload, so they exist before any Crucible code runs, and PHP has no
`function_alias()`: redeclaring one is an uncatchable fatal. Two sets of these globals cannot
share a process, however either engine is configured.

They can, however, simply not be loaded. Composer skips any `files` entry whose hash is already
marked, so Crucible ships a prelude that marks Pest's two function files and nothing else —
its globals are never defined, every one of its classes stays autoloadable:

```
php -d auto_prepend_file=vendor/cruciblephp/crucible/src/Dialect/Pest/vocabulary-prelude.php \
    vendor/bin/crucible
```

Pest brings PHPUnit with it, so a Pest project also needs `->phpunitCompatibility()` in
`crucible.php` to re-enable the PHPUnit-namespace aliases. With both in place Crucible runs a
Pest suite while Pest is still installed — which is what `compat-check` needs, since comparing
two engines requires both of them present.

### The inline dialect

`check()` is a nameless test — the name comes from the source line:

```php
check(fn () => expect(strtoupper('a'))->toBe('A'));
```

---

## 4. Assertions and `expect()`

The PHPUnit dialect has the assertion surface you already know: `assertSame`,
`assertEquals`, `assertStringContainsString`, the JSON family, `assertMatchesFormat`, and so
on. All of it — every one of PHPUnit 13's 176 `assert*` methods, including the XML family,
the `assertArrays*` comparisons, the `IgnoringWhitespace` variants and the
`assertContainsNotOnly*` negations. Each is checked against the real PHPUnit case by case,
in both directions, so a method that exists here behaves the way your suite already expects
(`conformance/fixtures/19-assertion-tail`).

`expect()` is the fluent surface, available in every dialect:

```php
expect([1, 2, 3])
    ->toBeArray()
    ->not->toBeEmpty()
    ->toContain(2);
```

Two things to know:

**`not` negates the rest of the chain**, not just the next call.

**Naming a key narrows the expectation to that value.** This is the one that catches people:

```php
expect($user)
    ->name->toBe('Ada')                    // the expectation is now 'Ada'
    ->and($user['roles'])->toHaveCount(2); // ->and() starts again
```

Writing `->name->toBe('Ada')->roles->toHaveCount(2)` fails, because after `->name` there is
no `roles` on the string `'Ada'`. That example is in the suite precisely because the first
draft of this manual got it wrong.

---

## 5. Datasets

One test per row, each row named in the output, so a failing row identifies itself.

```php
it('uppercases', function (string $input, string $expected): void {
    expect(strtoupper($input))->toBe($expected);
})->with([
    'lowercase' => ['crucible', 'CRUCIBLE'],
    'mixed'     => ['CrUcIbLe', 'CRUCIBLE'],
]);
```

Two `->with()` calls multiply — the Cartesian product, not a zip. In the PHPUnit dialect the
same thing is `#[DataProvider]`, expanded at discovery time.

---

## 6. Test doubles

[`examples/03-doubles`](examples/03-doubles/PaymentsTest.php). A **stub** answers questions;
a **mock** also carries expectations about how it is called, verified at the end of the test
whether or not you remember to check.

```php
$gateway = $this->createStub(Gateway::class);
$gateway->method('charge')->willReturn(true);

$gateway = $this->createMock(Gateway::class);
$gateway->expects($this->once())->method('charge')->with(500)->willReturn(true);
```

Unconfigured methods return their type's default rather than null, so a partially configured
double stays usable. `createPartialMock` and `getMockBuilder()->onlyMethods([...])` leave
unlisted methods running real code.

Mockery works too, unchanged, if the project already uses it.

---

## 7. Architecture rules

[`examples/04-architecture`](examples/04-architecture/rules.pest.php). A rule states the
shape the code must keep and fails like any other test when it drifts.

```php
arch('the domain stays pure')
    ->expect('App\Domain')
    ->toOnlyUse('App\Domain')
    ->ignoring('App\Domain\Support');
```

**Targeting.** A bare namespace is a prefix. `*` matches within one namespace segment, `**`
across segments, and an exact class name targets one class. `ignoring()` subtracts with the
same grammar.

**The universe** is the configured `->source()`, so vendor and tests are outside every rule
without anyone remembering to exclude them.

**Dependency rules** — `toUse`, `toOnlyUse`, `toUseNothing`, `toOnlyBeUsedIn` — consider only
references into your own source. PHP built-ins and vendor packages are ignored, because
otherwise every rule would need a whitelist of `Closure` and `ReflectionClass` before it said
anything. `toOnlyBeUsedIn` is the inverse rule, and the one that catches a boundary crossed
from the far side, where the offending file is not the file the rule is about.

**Shape rules**: `toUseStrictTypes`, `toBeFinal`, `toBeReadonly`, `toBeAbstract`,
`toBeInterfaces`, `toExtend`, `toImplement`, `toHaveSuffix`.

A rule matching **no class** fails, as does a rule stating no expectation. A renamed
namespace must not leave a green test enforcing nothing.

---

## 8. The developer loop

### Watch

```bash
crucible --watch
```

Re-runs the affected tests on every change. `q` quits, `a` runs everything, `f` runs just the
failures. The failed set is **sticky**: while anything is red it rides along on every
subsequent run, and one full-suite run confirms the recovery before the session is called
green again.

### Selecting by what changed

```bash
crucible --changed          # vs HEAD
crucible --changed=main     # vs a ref
crucible --dirty            # uncommitted work: staged, unstaged and untracked
crucible --related src/Cart.php
```

A test is selected when the transitive closure of the file declaring it intersects the
change set. Anything that cannot be reasoned about — a deletion, a changed `composer.json` —
widens back to the full suite rather than risking a test that silently did not run.

### Files the graph cannot reach

The dependency graph resolves **class references**. Anything reached by convention — an
asset, a Blade template, a translation file — is invisible to it, whatever its extension.
Declare those:

```php
->impactRule('resources/js',   ['browser'])
->impactRule('resources/lang', ['i18n'])
```

Rules are **additive**: they can add groups to a selection, never remove them, so a missing
rule is never worse than having none. A rule naming a group no test carries is reported
rather than silently doing nothing.

### Letting a build tool drive

Optionally, whoever already knows what changed can push a change set into a running watch
session:

```php
->retrigger()   // off by default
```

Watch then publishes a `.crucible.hot` file holding a loopback URL and token; its presence is
the liveness signal. [`integration/`](integration/README.md) ships a dependency-free Vite
plugin and a Node helper, plus the wire format so any producer — a build step, a git hook,
`curl` — can speak it.

---

## 9. Coverage

```bash
crucible --coverage
crucible --coverage-html build/coverage
crucible --coverage-clover build/clover.xml
```

Needs **pcov** (fast, line coverage) or **xdebug** (slower, also branch coverage via
`--coverage-branch`). Keep the extension out of your `php.ini` and load it only when asked,
so normal runs stay at full speed.

---

## 10. Browser testing

```php
->browser(enabled: true)
```

```php
$page = visit('/checkout');

$page->assertSee('Total')
    ->click('Place order')
    ->waitForNetworkIdle()
    ->assertSee('Thank you');
```

Backed by Playwright. The server runs **inside the test process**, so whatever the test
faked is visible to the pages the browser loads.

`waitForNetworkIdle()` waits until the page stops talking — no request in flight for a
continuous quiet period — built on the browser's own request lifecycle rather than a sleep.

Framework awareness:

```php
$page->assertInertiaComponent('Users/Index')
    ->assertInertiaProp('users.0.name', 'Grace');

$page->wireClick('Add one')->assertWireSet('count', 1);
```

Inertia keeps its state in the client adapter and never updates `data-page` after a visit,
so Crucible records the library's own events from before the app boots. Livewire does the
opposite — it rewrites `wire:snapshot` in the DOM every round trip — so it needs no recorder.
Both were established by running the real packages, not by assuming they behave alike.

---

## 11. Mutation testing

```bash
crucible --coverage      # first, to know which tests cover which lines
crucible mutate
```

Changes one operator at a time and re-runs only the tests that cover that line. A mutant that
survives is a line your tests execute but do not actually check. The score is
killed ÷ covered.

Covering tests run **fastest first**, so the cheapest test gets the first chance at the kill.
Coverage older than the source it maps is a lie, so a stale index warns rather than reporting
a confident wrong score.

---

## 12. JavaScript suites

```php
->vitest('resources/js')
```

A normal `crucible` run then also runs Vitest and folds every JS test into the same report,
tally and exit code. Under `--changed` the JS suite narrows through `vitest related`, letting
Vitest's own module graph decide — Crucible orchestrates rather than reimplementing a second
dependency walk.

---

## 13. Reporting and CI

| Want | Use |
|---|---|
| CI test results | `--log-junit build/junit.xml` |
| Machine-readable everything | `--log-events-json build/events.ndjson` |
| A human summary | `--log-markdown`, `--log-pdf` |
| Documentation view | `--testdox` |
| The slowest tests | `--profile` |
| Split across machines | `--shard 1/4` … `--shard 4/4` |

The NDJSON event stream is the contract: one versioned event per line, unbuffered. Every
reporter is a listener over it, and so can yours be.

Sharding is hash-stable — adding or removing tests never moves the others between shards.

---

## 14. Migrating from PHPUnit

1. `composer require --dev cruciblephp/crucible`
2. `./vendor/bin/crucible migrate-config` — converts `phpunit.xml` and reports anything it could
   not translate
3. `./vendor/bin/crucible` — the suite should be green
4. `composer remove phpunit/phpunit`

Step 4 is what turns on the compatibility aliases, so `PHPUnit\Framework\TestCase` keeps
resolving with no PHPUnit in the tree. While both are installed Crucible stands down and leaves
the namespace alone.

If step 3 isn't green, run `./vendor/bin/crucible compat-check` before digging in by hand —
while the incumbent is still installed (it's the oracle being compared against), it runs your
real suite through both engines and reports every test that passes on the incumbent but fails on
Crucible, with why. The oracle is `vendor/bin/pest` on a Pest project and `vendor/bin/phpunit`
otherwise, because PHPUnit's binary refuses to run a Pest suite at all. Classic PHPUnit classes in
a Pest project are read from the same run's PHPUnit event log, which names the class where Pest's
JUnit gives only a description, and placed by the file that declares it; if any test cannot be compared it is named and the run exits `2`,
because a verdict over part of a suite is not a clean one. `1` is reserved for the engines actually
disagreeing: a finding about your code, rather than about your setup. Most such failures turn out to assert PHPUnit's own internals (its file
layout, its internal method names) rather than your code's behavior — normal friction when
adopting any different test runner, not a Crucible bug. `--auto-fix` rewrites the shapes it
recognizes automatically (asks first by default); `--revert` undoes anything it changed. A clean
`compat-check` run ("No drift") is a stronger signal than the suite merely being green — it
means every test that ran under PHPUnit is asserting something Crucible actually agrees with,
not just that nothing errored.

A real Laravel 13 application runs green through exactly these steps — factories,
`RefreshDatabase`, Laravel's own constraints, mocks, `--parallel` — with the `composer
remove` as the only change to the project.

---

## 15. The command itself

### Reading the manual

```bash
crucible manual
```

This document, fullscreen in the terminal: ↑/↓ to scroll, `/` to search, number keys to follow
a link, ⌫ to go back, `q` to quit. It reads Crucible's own copy, installed beside the package,
so it works from any directory. Piped or in CI it prints the same text plainly, once.

`crucible --manual` is accepted too, since `--help` is spelled that way.

### As a PDF

```bash
crucible manual --out=crucible-manual.pdf
```

The same text, typeset monospaced, one page break where the page ends. It is deliberately not
a Markdown-to-PDF conversion: the manual is mostly tables and code samples, and a converter
that has no block for either would drop them without saying so. What you get on paper is what
the pager shows.

### The man page

Read it without installing anything — this form works on both man-db and BSD man, because an
argument containing a `/` is taken as a file:

```bash
man ./vendor/cruciblephp/crucible/crucible.1
```

To install it, ask your own system where man looks rather than trusting a path from a readme —
the directories differ across distributions, and on macOS they differ again between Intel and
Apple Silicon Homebrew:

```bash
manpath | tr ':' '\n'          # the directories man actually searches
```

Then put the page in the `man1` subdirectory of one of them. A per-user location needs no
`sudo` and is on the default path for man-db:

```bash
install -Dm644 vendor/cruciblephp/crucible/crucible.1 ~/.local/share/man/man1/crucible.1
```

On macOS with Homebrew, `$(brew --prefix)/share/man/man1` is the equivalent.

`crucible.1` is deliberately short: starting a run, the common options, the exit codes, and
where the real documentation is. It does not duplicate this manual — it points at
`crucible manual`, which is the whole of it and cannot fall out of date with the binary.

### Shell completion

The `eval` form needs no path and works the same everywhere:

```bash
eval "$(crucible completion bash)"   # in ~/.bashrc
eval "$(crucible completion zsh)"    # in ~/.zshrc, after compinit
crucible completion fish | source    # in ~/.config/fish/config.fish
```

Installing the scripts as files is faster to start a shell, and the locations are per-shell
rather than per-system:

```bash
# bash — $BASH_COMPLETION_USER_DIR, defaulting to $XDG_DATA_HOME/bash-completion
mkdir -p ~/.local/share/bash-completion/completions
crucible completion bash > ~/.local/share/bash-completion/completions/crucible.bash

# zsh — any directory on $fpath; a user-owned one avoids sudo
mkdir -p ~/.zfunc
crucible completion zsh > ~/.zfunc/_crucible
#   then in ~/.zshrc, BEFORE compinit:  fpath=(~/.zfunc $fpath)

# fish
crucible completion fish > ~/.config/fish/completions/crucible.fish
```

The zsh script leads with `#compdef crucible`, so the same output works either way — evaluated
in `~/.zshrc` after `compinit`, or autoloaded from a file on `$fpath`.

The script is generated from the parser itself, not written alongside it, so it cannot offer
an option the binary rejects or hide one it takes. Every alias is included, which means the
PHPUnit spellings complete too.
