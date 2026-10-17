# Crucible PHP — the manual

Crucible is an independent test framework for PHP 8.3+ whose observable behavior targets
drop-in compatibility with PHPUnit 13 suites.

Every code sample in this manual comes from [`examples/`](examples) or from Crucible's own
source, and both are **executed by the test suite**. An example that stopped working would turn
the suite red, so nothing here can quietly drift out of date.

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
    ->source(include: ['src']);
```

Return the builder as it is. Crucible calls `->build()` for you, which is why
`crucible init` and `crucible migrate-config` write the file without it. Calling it yourself
does no harm.

`->source()` says which directories hold **the code you own**, and three features read it:

- coverage reports on it;
- architecture rules treat it as the whole world (see §7);
- inline tests are looked for in it (see §3).

So adding a directory to make an architecture rule see it also lets Crucible find any
`#[Check]` or `@crucible` test written there. That's usually what you want, but it is worth
knowing.

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

| What you're testing | Dialect |
|---|---|
| Pure functions and value objects | inline: `#[Check]` rows and `@crucible` doctests on the code itself |
| Anything that needs the framework (container, database, HTTP) | PHPUnit or Pest files with your app's `TestCase` |
| Module boundaries | `arch()` rules (§7) |

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

The prelude also defines Crucible's own `test()`, `expect()` and friends before Composer loads
any package. That matters for plugins: `pestphp/pest-plugin-laravel` calls `expect()->extend()`
while it loads, and its helpers (`get()`, `actingAs()`, …) call `test()` with no arguments to
reach the running test. Both now land on Crucible.

Pest brings PHPUnit with it, so a Pest project also needs `->phpunitCompatibility()` in
`crucible.php` to re-enable the PHPUnit-namespace aliases. With both in place Crucible runs a
Pest suite while Pest is still installed — which is what `compat-check` needs, since comparing
two engines requires both of them present.

### The inline dialect

Tests that live in the code they test. There are three forms.

**`check()`** is a nameless test. The name comes from the source line:

```php
check(fn () => expect(strtoupper('a'))->toBe('A'));
```

**`#[Check]`** puts input and output rows on a function or method, from
`src/Reporting/PrettyName.php`:

```php
#[Check(['tests/unit/Runner/SchedulerTest.php'], returns: 'Scheduler')]
#[Check(['Test.php'], returns: 'Test', name: 'a file just called Test keeps its name')]
public static function ofFile(string $file): string
```

- `args` are positional, or named by parameter (string keys).
- `returns` is compared the way `toEqual()` compares, and `returns: null` is a real claim.
- `throws` expects that exception class instead.
- With neither, the row only claims the call finishes without throwing.
- Repeat the attribute for more rows. A row without `name:` is called `check 1`, `check 2`, …
- The method may be private. For an instance method Crucible makes a fresh object per row, so
  the class needs a constructor that takes no arguments; otherwise check a static factory.

Attribute arguments must be constant expressions. Anything that needs computing belongs in a
doctest or a test file.

**`@crucible` doctests** are one expression per docblock line, from `src/Test/TestId.php`:

```php
/**
 * @crucible expect(TestId::fromString('a.php::sums#row 1')?->dataset)->toBe('row 1')
 * @crucible expect(TestId::fromString('no separator'))->toBeNull()
 */
public static function fromString(string $id): ?self
```

The expression is compiled in the file's namespace, but the file's `use` imports don't apply to
it, so write names relative to that namespace or fully qualified. Other `@crucible-…` tags, such
as `@crucible-equivalent`, are not doctests. `crucible lint-inline` type-checks doctests with
PHPStan.

How they're found and named:

- Crucible looks for inline tests in `->source()`, and only loads a file that contains a
  `#[Check]` or a `@crucible` line.
- A row's id is `path/File.php::Class::method#<name>`; a doctest's is `…#crucible 1`, `#crucible 2`.
- Inline tests don't run inside a `TestCase`, so no application is booted for them. Use them for
  pure code, and keep code that needs the container in a test file.
- A library can carry `#[Check]` without making Crucible a dependency of its users. PHP only looks
  for an attribute's class when something reads the attribute, so the class runs fine where
  Crucible isn't installed. Doctests are comments, so they never need it.

### Where the Pest dialect differs from Pest

The goal is that a Pest suite gets Pest's answers. Probe 28 holds the matchers to that, cell by
cell. These are the known differences:

| What | Pest | Crucible |
|---|---|---|
| `toThrow()` with an interface, e.g. `toThrow(Throwable::class)` | reads the interface name as a message fragment, so it fails, and `not->toThrow(Throwable::class)` passes, even when an exception was thrown | matches the interface as a type. `->quirks(Quirk::ThrowInterfaceAsMessage)` in `crucible.php` restores Pest's reading (`LucianoPereira\Crucible\Configuration\Quirk`) |
| Arch rules with `->not` | `->not->toUse(...)` | no `not` on arch rules: state the allowed set with `toOnlyUse()`, or use `toUseNothing()` / `toBeUsedInNothing()` |
| A plugin's custom expectation typed with Pest's own class, like pest-plugin-laravel's `toBeCollection()` | works | fails when used: its closure declares `Pest\Expectation` as the return type, and Crucible can't pretend to be that class without hiding the real one |

Only one of these is Pest giving a wrong answer. `toThrow()` contradicts itself: for the same
thrown `InvalidArgumentException`, `toThrow(InvalidArgumentException::class)` passes and so does
`not->toThrow(Throwable::class)`. That's why Crucible corrects it by default, and why the quirk
exists for a suite that depends on the old answer.

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

### Shapes

A DTO's export, a JSON response, a `validated()` array: the keys and their types are what drift.
[`examples/05-shapes`](examples/05-shapes/shapes.pest.php) states the shape once, as a PHPStan
type string:

```php
expect($user)->toMatchShape('array{id: positive-int, email: non-empty-string, tags: list<string>}');
```

Crucible checks the value when the test runs and names the first place it stops fitting —
`$.email: expected non-empty-string, got ''`. PHPStan, with Crucible's extension, then knows
the shape: after that line `$user['tags']` is `list<string>`. `$this->assertMatchesShape($type,
$value)` is the PHPUnit-dialect spelling. The reading follows PHPStan's: a shape allows keys it
does not name, and class names in the string are fully qualified. It is for structure whose
values vary; for exact values, `toMatchArray()` says more.

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

With Crucible's PHPStan extension, a row the test cannot take is reported before anything runs:
`['1', 2, 3]` for `function (int $a, int $b, int $sum)`, or a row with too few values. The same
check covers `#[TestWith]`, `#[TestWithJson]`, `#[Check(args:, returns:)]` and `table()`.

Property tests draw their data from generators, and `Gen::of()` draws from a type string — the
same grammar as `toMatchShape()`, so a failing case shrinks to the smallest value the type allows:

```php
property(
    'draws data from the same type',
    Gen::of('array{id: positive-int, email: non-empty-string, tags: list<string>}'),
    fn (array $input) => expect(exportUser($input['id'], $input['email'], $input['tags']))
        ->toMatchShape('array{id: positive-int, email: non-empty-string, tags: list<string>}'),
);
```

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

"Your own source" means classes that exist. A reference to a class nobody has written yet,
say `Modules\Chat\Missing`, is not a dependency on anything, so `toOnlyUse()` stays green until
that class appears. If a rule is green while you can see the reference, check that the class
exists.

**There is no `not`.** Arch rules state what is allowed rather than what is forbidden: use
`toOnlyUse()` for the allowed set, and `toUseNothing()` or `toBeUsedInNothing()` for none.

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

pcov 1.0.12, the version the distributions ship, works on every supported PHP from 1.1.5 on.
If a worker process is killed during a coverage run, its tests say which signal killed it and
which driver was loaded, so a crashing extension doesn't look like a crashing test.

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

A line that compiles to no code of its own, such as an attribute's arguments, a property
default or an inner line of an array literal, never appears in coverage, so its mutant runs
the tests of its method, or of the class outside any method. A line that could run and that
no test ran stays not covered.

Covering tests run **fastest first**, so the cheapest test gets the first chance at the kill.
An interrupted run resumes where it stopped. A mutant is judged again when its covering tests,
or any file they ran (fixtures included, vendor never), have changed since its verdict.
Coverage older than the source it maps is a lie, so a stale index warns rather than reporting
a confident wrong score.

Besides operators, a returned array literal loses one string key at a time: a survivor says no
test checks that the key is there — the gap `toMatchShape()` closes.

Some survivors cannot be killed: a change no test could observe. Declare them where they are,
with the reason, and they leave the score while staying in the report —
[`examples/06-equivalent-mutants`](examples/06-equivalent-mutants/InventoryTest.php):

```php
/**
 * @param list<string> $items
 *
 * @crucible-equivalent the loop returns before the bound matters
 */
public function first(array $items): ?string
{
    for ($i = 0; $i < count($items); $i++) {
        return $items[$i];
    }

    return null;
}

public function restock(int $count): int
{
    $this->log[] = 'restocked ' . ($count + 1); // crucible-equivalent-line the log wording is not behaviour

    return $count;
}
```

The reason follows a space or a colon (`@crucible-equivalent: <reason>`).
`// crucible-equivalent-start <reason>` … `// crucible-equivalent-end` marks a region. A marker
without a reason declares nothing, and a marker that covers no mutant is reported as stale.

---

## 12. JavaScript suites

```php
->vitest('resources/js')
```

A normal `crucible` run then also runs Vitest and folds every JS test into the same report,
tally and exit code. Under `--changed` the JS suite narrows through `vitest related`, letting
Vitest's own module graph decide — Crucible orchestrates rather than reimplementing a second
dependency walk.

The suite is named `vitest`, and selected by that name:

```bash
crucible --exclude-testsuite vitest          # the PHP tests alone
crucible --testsuite vitest                  # the JS tests alone
crucible --filter Cart                       # PHP tests matching Cart; the JS suite is left out, and a line says so
crucible --testsuite unit,vitest --filter Cart   # both, filtered: Vitest gets the pattern as its own
```

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

### PHPStan

Crucible's own PHPStan extension needs no wiring with `phpstan/extension-installer`: Crucible
declares it, and the installer loads it. Without the installer, `crucible phpstan-init` writes the
one include line. It covers what a migrated suite had from phpstan-phpunit and pest-plugin-phpstan
— assertions narrow, `expect()` is generic and its type matchers narrow the chain, `$this` is typed
in Pest closures — held line by line against both by probe 30. And some things neither does:

- after `expect($x)->toBeString();` the variable `$x` itself is a string, not only the chain;
- dataset rows, `#[TestWith]`, `#[TestWithJson]`, `#[Check]` and `table()` rows are checked
  against the parameters they feed;
- `toMatchShape()` narrows to its type string, and `Gen::of()` produces it.

In a Pest file, a property a `beforeEach` sets on `$this` keeps the type it was given in that
file's tests: after `$this->user = User::factory()->create();`, `$this->user` is a `User`. A
property nothing sets is `mixed`, never an "undefined property" error. Both answers are
pest-plugin-phpstan's.

PHPStan does not read an `@param` docblock on a closure, wherever it is written (measured on
PHPStan 2.2; pest-plugin-phpstan reports the same). To narrow a dataset parameter beyond its
native type, put a `/** @var array<string, mixed> $payload */` line inside the closure's body,
above the first use.

A matcher narrows exactly as its assertion does: `toBeString()` is `assertIsString()`, and
`toBeList()` and `assertIsList()` both read a list of the value's elements. Narrowing stops where the
matchers stop being about the value: after `and()` or `json()`, and after a spread `each`
(`->each`, or `each()` without a callback), whose matchers are about the items. What the matchers
before that point proved still holds.

The crucible dialect's own globals — `check()`, `property()`, `table()` — are declared by a second
file, so a project with its own global `check()` is never read as Crucible's:

```yaml
includes:
    - vendor/cruciblephp/crucible/phpstan/crucible-dialect.neon
```

### Type tests

```php
->typeTests('tests/Types')
```

Every call to one of PHPStan's assertion functions — `assertType()`, `assertNativeType()`,
`assertSuperType()`, `assertVariableCertainty()` — in a `*.types.php` file under that directory is a
test in the run, and so is every line ending `// crucible-type-error <identifier>`, which passes
only when PHPStan reports that error there —
[`examples/07-type-tests`](examples/07-type-tests/ids.types.php):

```php
assertType('list<int>', ids());

$sum = ids()[0] + 'x'; // crucible-type-error binaryOp.invalid
```

PHPStan analyses the files once, through your phpstan.neon. An error on no assertion's line, or a
file with no assertion at all, is the file's own errored entry: a type test that tests nothing is
not a pass. The suite is named `types`, so `--exclude-testsuite types` keeps a quick edit loop
quick.

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

If step 3 isn't green, run `./vendor/bin/crucible compat-check` before digging in by hand.
While the incumbent is still installed (it's the oracle being compared against), it runs your
real suite through both engines and reports every test that passes on the incumbent but fails on
Crucible, with Crucible's own failure message beside it. If Crucible couldn't build the suite at
all, the first thing it prints is the error that stopped it.

The oracle is `vendor/bin/pest` on a Pest project and `vendor/bin/phpunit` otherwise, because
PHPUnit's binary refuses to run a Pest suite at all. Classic PHPUnit classes in a Pest project are
read from the same run's PHPUnit event log, which names the class where Pest's JUnit gives only a
description, and placed by the file that declares it.

The exit code says what kind of finding it is:

- `0` — "No drift". Every test that passed under the incumbent also passes under Crucible. That's a
  stronger signal than the suite merely being green: each of those tests asserts something both
  engines agree on.
- `1` — the engines disagree, which is a finding about your code. On a PHPUnit suite such a test
  usually asserts PHPUnit's own internals (its file layout, its internal method names) rather than
  your code's behaviour: normal friction when changing test runner, not a Crucible bug.
- `2` — the comparison couldn't be made, which is a finding about your setup. That includes any
  test that couldn't be lined up between the two runs: it's named, because a verdict over part of
  a suite isn't a clean one.

`--auto-fix` rewrites the shapes it recognises (it asks first by default), and `--revert` undoes
anything it changed.

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
