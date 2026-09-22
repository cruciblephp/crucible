# Spec: Mockery API (third spec — the doubles growth half)

> Behavioral specification for Crucible's Mockery-compatible surface, established from
> Mockery's official documentation (docs.mockery.io) and **black-box probe runs against
> the real binary** — clean-room rule: the API surface and observed semantics are the
> spec; Mockery's implementation is never read. Probe scripts exercise the gitignored
> `mockery-main/` reference install and results are recorded here verbatim.
>
> Probed 2026-07-16: **mockery/mockery 1.6.12** (2024-05-16 — no stable release since;
> the project is release-stalled), BSD-3, running on **PHP 8.5.7** — the "PHP 8.5
> support unverified" flag from RESEARCH.md §6 is resolved: core paths run clean, but
> 8.5-era language features crash it (readonly classes fatal, see §12). Mockery is the
> de-facto doubles contract Laravel builds on (`$this->mock/partialMock/spy`, facade
> `shouldReceive()`); a compatible surface completes the third of Laravel's three
> testing contracts (RESEARCH.md §6).
>
> Feature inventory cross-checked 2026-07-16 against the full docs.mockery.io page
> tree (reference + configuration + cookbook); every docs-surfaced feature not covered
> by the first probe batteries was probed before being recorded here (batteries 6–7).

## Scope (decided 2026-07-16): the Pest gap only

**The project goal is replacing pest+mockery with Crucible.** Pest ships no mocker — its
docs say "you will need to install a mocking library. We recommend Mockery"
(pestphp.com/docs/mocking, checked 2026-07-16). The planned scope is exactly that
documented recommendation, nothing more:

- **M2 core grammar** (planned): §1 creation (plain forms), §2 expectation grammar,
  §3 dispatch, §5 counts/verification, §15 configuration toggles.
- **M3 matchers** (planned): §4 argument matching.
- Together M2+M3 cover everything the Pest mocking chapter demonstrates — the pest
  dialect gains the documented Pest mocking experience natively, no third-party
  package, `composer require mockery/mockery` unnecessary.

**Everything else in this document is pinned reference, not planned work.** The
former M4–M7 (spies/ordering §6–§7, partials §8–§10, alias/overload statics §11,
Laravel bridge §13) served the Laravel contract and are **ditched by decision** —
they get built only if a piece falls out for free from the M2/M3 machinery (nice if
so, never a goal). The probe findings stay recorded because they were expensive to
pin and cost nothing to keep. §14's named-error rule applies to every unimplemented
Mockery spelling, ditched or long-tail alike.

## 1. Creation surface

- `Mockery::mock()` — anonymous mock (probe: class `Mockery_0`, no parent).
- `Mockery::mock('ClassOrInterface')` — full mock; **every** call must be configured
  (unconfigured → `BadMethodCallException` "Method X::f() does not exist on this mock
  object").
- `Mockery::mock('Nonexistent\Type')` — mocking unknown types is **supported** (probe:
  Mockery declares the named parent itself and extends it). PHPUnit 13 removed this;
  the Mockery surface keeps it.
- `Mockery::mock('IfA, IfB')` — comma-separated multi-interface mock; `instanceof` both.
  Also `mock('Class', 'IfA, IfB', [$ctorArgs])` — class + interfaces as a separate
  string argument. Docs gotcha: differing case variants of one interface name =
  undefined behavior (`class_exists` case-insensitivity).
- **Quick definitions**: `mock('Name', ['f' => 'v'])` — method⇒return sugar at
  creation; matches any args (probe: `g('any','args')` returned the value). Default is
  stub-flavored (never called = clean close); the configuration toggle (§15) turns
  them into at-least-once mocks (probe-verified both ways).
- `Mockery::mock('Class[a,b]')` — partial by inclusion list: listed methods mocked,
  everything else real. Probe: **runs the original constructor** (no-arg).
- `Mockery::mock('Class[!a]')` — partial by exclusion. Probe footgun: `__construct` is
  in the mocked remainder — instantiation itself throws `BadMethodCallException`
  "Received __construct(), but no expectations were specified".
- `Mockery::mock('Class', [$ctorArgs])` — **runs the original constructor with args**
  (probe: partial saw `ctor` promoted property initialized to the given arg).
- `Mockery::mock($instance)` — **proxied partial**: wraps a live object; stubbed methods
  intercept, others hit the real object. Probe: `instanceof` holds for non-final
  targets (generated subclass), **fails for final targets** (pure proxy) — the
  documented Mockery limit that native lazy proxies will lift (§12).
- `Mockery::spy('Type')` — see §7.
- `Mockery::namedMock('Exact\Class\Name', 'ParentOrInterface')` — probe: class is
  exactly the given name, `instanceof` parent holds.
- `Mockery::instanceMock('Class')` — instance mock, behaves as a mock (probe: works).
- `alias:`/`overload:` prefixes — §11.
- `->makePartial()` — runtime partial, §8. `->shouldIgnoreMissing($default = null)` —
  unstubbed calls return `$default` (probe: `null` default, any explicit value honored);
  `->asUndefined()` switches to a chainable `Mockery\Undefined` (probe:
  `$m->a()->b()->c()` chains). On typed methods ignore-missing returns type-derived
  zero-values, `static` → the mock itself (probe-verified).

## 2. Expectation grammar

- `$m->shouldReceive('f')` → expectation, default count **zero-or-more** (stub-like).
- `$m->shouldReceive('a', 'b')` — one expectation per method, shared configuration.
- `$m->shouldReceive(['a' => 1, 'b' => 2])` — method⇒return sugar. Same for
  `allows(['a' => 1])`.
- `$m->allows('f')` / `$m->allows()` (fluent: `$m->allows()->f(1)->andReturns('one')` —
  probe-verified) — stub verb, never verified.
- `$m->expects('f')` / `$m->expects()->f()` — mock verb, **default count exactly once**
  (probe: unmet AND called-twice both raise `InvalidCountException` at close).
- `$m->shouldNotReceive('f')` ≡ `never()`. Probe: a violating call **returns null and
  does not throw**; the failure surfaces at close (§5).
- Returns: `andReturn($v)`, `andReturn($v1, $v2, ...)` (consecutive, **last value
  repeats** — probe: `1,2,3,3`), `andReturns(...)`, `andReturnValues([$v1, $v2])`
  (same repeat rule), `andReturnNull()`, `andReturnFalse()`, `andReturnTrue()`,
  `andReturnSelf()`, `andReturnArg($i)`, `andReturnUsing($fn)` (receives call args;
  by-ref args writable — probe-verified), `andThrow($e)` / `andThrow($class, $msg)` /
  `andThrows(...)`, `andSet($prop, $v)` (probe: property set on the mock **after** the
  call, absent before).
- `passthru()` — the expectation matches (args filter, counts verify) but the **real
  method runs** (probe: `once()->passthru()` returned real code's value and settled
  the count; `with(5)->passthru()` still refuses non-matching args). Requires a
  concrete doubled class — the runtime-conditional forwarding mode partials need (§8).
- `andReturnUndefined()` — returns the chainable `Mockery\Undefined` (probe-verified).
- `set($prop, $v)` — alias of `andSet()` (probe-verified). Public properties may also
  be **assigned directly on the mock** at any time (probe-verified). Magic/virtual
  properties: mock them as if declared — magic methods themselves are not mockable
  (§12).
- `getMock()` — escape hatch off the expectation chain back to the mock instance
  (probe: identical instance).
- No `andReturn` configured → `null`; on **typed** methods a type-derived default:
  `int` → `0`, `string` → `''`, object type → **an auto-mock of that type** (probe:
  `Mockery_14_DateTimeImmutable`), `static` → the mock itself, `void` → null.

## 3. Dispatch semantics (probe dividend — opposite of PHPUnit in three places)

1. **First-defined wins.** Two catch-all expectations on one method: every call hits
   the first (probe: `['first','first']`). PHPUnit/Crucible native is latest-wins — this
   is a per-double **matching strategy**, not a shared rule.
2. **Count exhaustion moves matching to the next expectation.** `once()->andReturn('first')`
   then catch-all `'second'`: calls yield `first, second, second` (probe-verified).
   An exhausted expectation with **no** successor still handles the call (returns its
   value) and fails only at close (probe: exceeded `once()` returned the value).
3. **No specificity ranking.** A catch-all defined *before* `with(1)` shadows it
   forever (probe: `['any','any']`, and the `with(1)` expectation then fails
   its implicit match at nothing — order of definition is the only priority).
   `with(1)` defined first + catch-all after behaves as users expect (`one`, `any`).
4. `byDefault()` ranks lowest: a later plain expectation replaces it (probe:
   `'override'`); alone, it serves (`'default'`).
5. Unconfigured method on a plain mock → `BadMethodCallException` (§1); configured
   method called with non-matching args → `Mockery\Exception\NoMatchingExpectationException`
   ("No matching handler found for X::f(2). Either the method was unexpected or its
   arguments matched no expected argument list for this method").

## 4. Argument matching

- **Scalars/arrays compare loosely** (`==`): probe — `with(1)` matches `'1'`;
  `with(1.0)` matches `1`; `with(['a' => 1])` matches `['a' => '1']`.
- **Objects compare by identity**: probe — two equal-by-value `DateTimeImmutable`
  instances do **not** match. (PHPUnit's equality spec is the opposite on both counts.)
- Matcher algebra (all probe-verified):
  `Mockery::any()`; `type('int')` (strict — `'5'` refused); `on($closure)`;
  `pattern('/re/')`; `ducktype('method')`; `subset(['a'=>1])` (extra keys allowed,
  **strict** comparison inside — `'1'` refused, unlike bare `with()`);
  `contains(1, 2)` (order-insensitive values); `hasKey($k)`; `hasValue($v)`;
  `capture($var)` (by-ref capture of the argument — no PHPUnit equivalent);
  `not($v)`; `anyOf(...)`; `notAnyOf(...)`.
- `withArgs($closure)` (all args to one predicate), `withArgs([$a, $b])` (array form),
  `withNoArgs()`, `withAnyArgs()`, `withSomeOfArgs(2)` (match if present anywhere).
- Battery 8 (probed 2026-07-17, 1.6.12 — the strictness edges the algebra list left open):
  - **The strictness map is asymmetric, matcher by matcher**: `contains` **loose**
    (`contains(1)` matches `['1']` and vice versa; objects `==`); `hasValue` **strict**
    (`hasValue(1)` refuses `['1']`; objects by identity); `anyOf` **strict** (both
    directions probed); `notAnyOf` **loose** (`notAnyOf(1, 2)` refuses `'1'` — it
    considers `'1'` a member; matches `3`); `not` **strict** (`not(1)` **matches** `'1'`;
    equal-by-value objects match — identity falls out of `!==`);
    `withSomeOfArgs` **strict** (`'1'` does not find `1`).
  - `subset` is **recursive**: nested part arrays are themselves subset-compared (extra
    keys allowed at every depth), strict throughout; the documented second parameter
    `subset($part, strict: false)` flips the whole comparison loose (probed at depth).
    Keys are positional for lists (`subset([0 => 1])` refuses `[2, 1]` — value at the
    wrong index). Empty part matches any array; a non-array argument is a clean
    no-match (not a crash).
  - `type($t)` follows the **`is_{$t}()` function-existence rule**, case-insensitive:
    `int`/`integer`/`long`/`double`/`float`/`numeric`/`callable`/`scalar`/`iterable`/
    `null`/`object`/… all work; `float` refuses `int` (strict); any other name —
    class/interface or unknown — is an **`instanceof` at match time** (`'boolean'` never
    matches: `is_boolean()` does not exist; unknown names are a silent no-match, not an
    error).
  - `pattern` **casts**: scalars and `Stringable` objects are matched via `(string)`
    (`pattern('/12/')` matches int `123` and float `12.5`); a non-stringable object is
    an upstream **crash** (`Error: could not be converted to string`).
  - `ducktype` = `method_exists` on every named method, all required; non-objects are a
    no-match; a Mockery mock's magic `__call` methods are **not** seen.
  - `capture($var)` matches anything and **assigns during the match attempt** — the
    variable is written even when a later matcher in the same `with()` refuses the call
    (probe: second-arg mismatch threw, the capture var held the first arg). Arity
    mismatch never reaches the matcher (var untouched). Across calls the last match
    wins. Composes with counts and `andReturnUsing`.
  - `any()` is a **per-argument** matcher — arity still enforced (`with(any())` refuses
    `f()` and `f(1, 2)`; matches `f(null)`).
  - `withArgs($closure)`: the predicate result must be **`=== true`** (returning `1`
    refuses — same rule probed for `on($closure)`). The closure is called with the
    actual args, so an arity underflow is the user's own `ArgumentCountError`,
    propagated. Array form ≡ bare `with(...$array)` (loose). Any other argument type:
    `InvalidArgumentException` "Call to Mockery\Expectation::withArgs with an invalid
    argument (…), only array and closure are allowed".
  - `withSomeOfArgs(...)`: **every** given value must be present among the args, any
    position, order-insensitive, strict; the zero-argument form matches any call.
  - `contains` on a non-array argument is an upstream **crash** (`TypeError` from
    `array_values()`), string args included.
  - **Crucible policy on the two crashes** (§12 posture — better-than-incumbent, recorded):
    `contains` on a non-array and `pattern` on a non-stringable object are clean
    no-matches, never engine crashes.
  - `mustBe(1)` still functions in 1.6.12 (deprecated) — stays a named error per §14.
- Long-tail: `mustBe()` (deprecated upstream) → named error, §14. Hamcrest matchers →
  out of scope, §14.

## 5. Counts and verification timing

- Vocabulary: `once` `twice` `times($n)` (`times(0)` ≡ `never` — probe),
  `never` `zeroOrMoreTimes` `atLeast()->once()/twice()/times($n)`
  `atMost()->...` `between($m, $n)`.
- **All count violations settle at `Mockery::close()`** — including *exceeded* maxima
  and `shouldNotReceive` (probes: violating calls return normally). Nothing
  count-related fails at call time. Exception: `Mockery\Exception\InvalidCountException`
  ("Method f(<Any Arguments>) from <class> should be called …").
  *(Crucible native/PHPUnit-spec doubles fail exceeded counts at call time — the two
  brains keep their own timing; this is per-grammar behavior.)*
- **Order violations fail at call time**: `Mockery\Exception\InvalidOrderException`
  ("called out of order: expected order 1, was 2") — §6.
- In Crucible, settlement rides the existing `TestCase::verifyTestDoubles()` seam (D-046)
  on both lifecycles; `Mockery::close()` exists as an idempotent alias so suites that
  call it in `tearDown()` keep working. Each verified expectation counts as one
  assertion (host parity, §13).

## 6. Ordering

- `->ordered()` — global (per-container) sequence among ordered expectations; violation
  at call time (§5). Probe: `first(); second();` clean, inverted throws.
- `->ordered('group')` — expectations sharing a group name may interleave **within**
  the group (probe: `b(); a();` in one group is clean); distinct groups/unnamed
  ordered expectations keep their relative sequence.
- `->globally()->ordered()` — one sequence across **all mocks** in the container
  (probe: cross-mock inversion throws `InvalidOrderException` naming the method).

## 7. Spies

- `Mockery::spy('Type')` — record-then-assert. Unstubbed calls return `null` (typed:
  zero-values per §2) and are recorded; **real code never runs** (probe: spy on a
  concrete class returned `''` from a real method, not its body).
- `$spy->shouldHaveReceived('f')` (+ `->with(...)`, `->twice()`, …) — asserts
  immediately, throws `InvalidCountException` on mismatch (probe: wrong args throw at
  the assertion call, not at close).
- `$spy->shouldNotHaveReceived('f')` — inverse (probe-verified).
- Fluent no-arg forms (probe-verified): `$spy->shouldHaveReceived()->f(1, 2)` — the
  args ARE the matcher; terminal `shouldHaveBeenCalled()` / `shouldNotHaveBeenCalled()`
  close that chain (both in the reserved-name list, §12).

## 8. Partials (three forms, different constructor rules — all probed)

| Form | Mocked set | Original constructor |
|---|---|---|
| `mock('C[a,b]')` | listed only, rest real | **runs** (no args) |
| `mock('C', [$args])->makePartial()` | none until stubbed | **runs with args** |
| `mock(C::class)->makePartial()` | none until stubbed | **not run** (probe: typed promoted property uninitialized) |
| `mock($instance)` proxied | stubbed intercept, rest hit the real object | caller already ran it |

- `makePartial()`: which methods intercept is a **runtime** decision — expectation
  exists → intercept, none → real code. (This is the state-consulted forwarding mode
  the Crucible generator needs; `onlyMethods()`-style absent-override generation cannot
  express it.)
- Proxied partials: `instanceof` holds except for final targets (§12).

## 9. Protected methods

- Mocking a protected method without opt-in → `InvalidArgumentException` with the
  upstream's own remediation text (probe: "…mocking protected methods is not enabled…
  Use shouldAllowMockingProtectedMethods()…").
- `$m->shouldAllowMockingProtectedMethods()->shouldReceive('secret')` — works,
  including on partials (probe: real public caller received the stubbed value).

## 10. Demeter chains

- `shouldReceive('a->b->c')->andReturn($v)` — intermediate auto-stubs generated
  (probe: `$m->a()->b()->c()` returns the value). Final/typed intermediates: pin
  per-case in conformance fixtures when M5 lands.

## 11. Static mocks: `alias:` and `overload:` (probed in fresh processes)

- `mock('alias:Full\Name')` — **declares** the named class; static calls route to the
  most recent alias mock (probe: static `sum()` returned 42).
  **Re-aliasing the same name in the same process works** (probe: second alias
  returned 99) — the handler is swappable; the real constraint is elsewhere:
  - Name already genuinely loaded → `Mockery\Exception\RuntimeException`
    "Could not load mock X, class already exists".
  - Once aliased, the *real* class can never load again in that process — the true
    reason upstream docs demand `@runTestsInSeparateProcesses`. Crucible: same
    swappable-handler declaration, plus the already-shipped per-test process
    isolation when a suite genuinely needs the real class back; the
    already-declared case stays a **named error**.
  - Alias expectations bind to the returned mock for instance calls; `new`-ing the
    aliased class yields an instance whose methods are **not** stubbed (probe:
    `BadMethodCallException`). Alias is a static-call surface.
- `mock('overload:Full\Name')` — intercepts `new` inside code under test (probe:
  consumer's `new Acme\Mailer()` produced the double). Semantics (all probed):
  - The template's expectations are **copied to every new instance**; counts and
    consecutive-return sequences are **per-instance** (probe: two instances each
    returned the first consecutive value, and a `twice()` failed per-instance at
    close).
  - Constructor args are accepted silently; unstubbed methods on an overloaded
    instance → `BadMethodCallException`.
  - Re-overloading the same class in one process works (probe-verified).
- On regular (non-alias) mocks of classes with static methods, `shouldReceive` on the
  static works and dispatches for both `$m->sf()` and `$m::sf()` (probe-verified).

## 12. Boundaries: upstream limits, upstream crashes, Crucible policy

| Target | Mockery 1.6.12 (probed) | Crucible policy |
|---|---|---|
| Final class | `Mockery\Exception`, helpful message | same: named `DoubleCreationException` |
| Enum | same final-class error | same (D-017 already refuses) |
| Final method | **silently left real** — `shouldReceive` accepted, real code runs | named error at configuration time — a silent no-op stub is the worst outcome (D-046 precedent: better-than-incumbent, recorded) |
| Readonly class | **PHP fatal** ("Non-readonly class cannot extend readonly") | generate a readonly subclass — 8.5-era feature the stalled upstream predates |
| Target declares `shouldReceive`/API names | **PHP fatal** ("Cannot redeclare") — upstream reserves `shouldReceive` `shouldNotReceive` `allows` `expects` `shouldAllowMockingMethod` `shouldIgnoreMissing` `asUndefined` `shouldAllowMockingProtectedMethods` `makePartial` `byDefault` `shouldHaveReceived` `shouldHaveBeenCalled` `shouldNotHaveReceived` `shouldNotHaveBeenCalled` plus the `mockery_` method / `_mockery` property prefixes | named rejection (D-017 collision rule already does this) |
| Magic methods (`__call`, `__get`, …) | **not mockable by design** — docs: mock the virtual methods/properties they simulate, never the magic method itself; the mock machinery owns them | same rule, same wording in the error |
| Public `__wakeup` | mockable but the mock's `__wakeup` is a **silent no-op** (docs gotcha) | pin in conformance fixture |
| Alias/instance mock + `require()`-loaded class | **fatal** — docs: autoloading only | named error (the already-loaded check, §11) |
| Proxied partial of final class | works, `instanceof` **fails** typehints | native lazy proxy → `instanceof` holds (RESEARCH §6 harvest) |
| By-ref parameters | preserved via reflection (probe: writable through `andReturnUsing`); **internal classes** need `setInternalClassMethodParamMap()` (reflection can't see their signatures); protected by-ref = documented proxy-method workaround | preserve for the Mockery grammar (generator gains by-ref rendering; lifts the D-017 by-value note) |
| Mock `__clone` | real `__clone` runs on clone; clone keeps stubs (probe) | pin in conformance fixture |

## 13. Host integration (M7)

- Class/trait names suites reference and must alias: `Mockery`,
  `Mockery\MockInterface`, `Mockery\LegacyMockInterface`, `Mockery\Expectation`,
  `Mockery\Adapter\Phpunit\MockeryTestCase`, `MockeryPHPUnitIntegration` (trait),
  the §5 exception taxonomy (`Mockery\Exception`, `…\InvalidCountException`,
  `…\InvalidOrderException`, `…\NoMatchingExpectationException`,
  `…\BadMethodCallException`, `…\RuntimeException`).
- Settlement is native (§5); `Mockery::close()` idempotent alias; verified
  expectations feed the assertion count (upstream's PHPUnit adapter does the same via
  `mockery_getExpectationCount()`).
- Upstream ships a TestListener that fails tests which never called `close()`, with a
  documented blind spot under process isolation (listener runs in the wrong process).
  Crucible needs neither: settlement is unconditional on both lifecycles — including
  inside `#[RunInSeparateProcess]` children — so the listener aliases to a no-op
  shim and the blind spot closes by construction.
- Coexistence per D-019: `mockery/mockery` installed → surface off, one-line note;
  absent → aliases on. `composer remove mockery/mockery` is the migration.
- Laravel: `$this->mock()/partialMock()/spy()/forgetMock()` and facade
  `shouldReceive()` call the `Mockery` entry points — the real-app acceptance run
  (RELEASE.md app, mockery removed) closes the slice.

## 14. Long tail — named errors until a conformance scenario demands them

`mustBe()` (deprecated upstream), hamcrest global matchers, `mockery_*` global
helper functions, `Mockery::getContainer()` internals beyond expectation counting,
`Mockery::globalHelpers()`, `andYield` -style generator sugar (not in 1.6),
`setBackwardCompatibleClassMap`. Never silent no-ops (D-046 rule: a silent
configurator is worse than an error).

## 15. Configuration surface (`Mockery::getConfiguration()`)

- `allowMockingNonExistentMethods(bool)` — default **true** (probe: unknown-method
  stubbing works out of the box); `false` → probe: `Mockery\Exception` "configuration
  currently forbids mocking the method X as it does not exist on the class or object
  being mocked". Both states supported (strict suites set false).
- `getQuickDefinitions()->shouldBeCalledAtLeastOnce(bool)` — default false (quick
  definitions are stubs); true → each quick definition is an at-least-once mock
  (probe: unmet → `InvalidCountException` at close).
- `setInternalClassMethodParamMap($class, $method, $params)` /
  `getInternalClassMethodParamMap(...)` — by-ref signatures for internal classes (§12).
- `setConstantsMap([...])` — **probe correction to the docs**: the map applies only to
  `alias:` mocks (the generated class declares the constants — probe: aliased read
  returned the mapped value); on a regular mock of a loaded class the real constant is
  inherited and the map is silently ignored, and unknown-class mocks don't receive the
  constants at all ("Undefined constant"). Crucible pins the alias-only behavior and
  makes the other two cases **named errors** instead of silent ignores.
- `disableReflectionCache()`/`enableReflectionCache()` — upstream-internal knob (its
  own reflection memoization vs PHPUnit `--static-backup`); Crucible's generator cache is
  its own concern (D-017) — accepted as documented no-ops, the one long-tail exception
  to "never silent" because they promise nothing observable.

## Appendix: implementation reuse map (Crucible-side, binding for M2–M7)

Not spec — the build rule: **the Mockery surface is a grammar over existing Crucible
machinery; nothing below gets a second implementation.** Every slice states its deltas
against this table; a parallel mechanism is a design error (D-017's one-state-brain
invariant extended to the third spec).

| Mockery need | Already equipped (reuse as-is) | Actual delta |
|---|---|---|
| Class generation, typed rendering, process-global cache | `Double\Generator` + `DoubleSpecification` (`classIdentity()` cache key) | new switches on the specification: state-consulted forwarding (`makePartial`/`passthru`/spies), by-ref rendering, readonly targets |
| Count vocabulary `once/twice/times/never/atLeast/atMost/between/zeroOrMore` | `Double\InvocationCount` {min,max} — the one shape covers all of it | a `between($m,$n)` factory; nothing else |
| Per-instance expectation storage, dispatch, call recording | `Double\DoubleState` (`configureMethod`, `dispatch`, `callsTo`, `verifyExpectations`) | a matching-strategy switch: first-defined-wins + count-exhaustion fall-through + `byDefault` floor (§3) beside the native latest-wins; close-time-only count settlement (§5) |
| Expectation object behind `shouldReceive/allows/expects` | `Double\MethodConfigurator` (with/appliesTo/registerInvocation/invoke/verify) | Mockery verb spellings delegate to it; new return sugar maps to existing `willReturn*` internals (`andReturnArg`→`willReturnArgument`, `andReturnUsing`→`willReturnCallback`, consecutive-with-last-repeat variant of `willReturnOnConsecutiveCalls`) |
| Unconfigured typed returns (`int` 0, `string` '', object→recursive stub, self/static→double) | `Double\ReturnDefaults` — probe battery 1 matched it behavior-for-behavior | none |
| Argument matchers | `Assert\Constraint\*`: `type`→IsType/IsInstanceOf, `pattern`→MatchesRegularExpression, `on`/`withArgs`→Callback, `hasKey`→ArrayHasKey, `contains`→TraversableContains, `not`→LogicalNot, loose/identity equality→IsEqual/IsIdentical (`MethodConfigurator::with()` already accepts Constraint instances directly) | thin new constraints only where none exists: `subset`, `hasValue`, `anyOf`/`notAnyOf`, `ducktype`, stateful `capture`; a default-equality composer (loose scalars, identical objects — §4) |
| End-of-test settlement on both lifecycles | `TestCase::verifyTestDoubles()` seam (D-046) — invokeTest + pest dialect + separate-process children | Mockery-flavored doubles register in the same container; `Mockery::close()` = no-op alias |
| Failure taxonomy | `DoubleCreationException`/`DoubleConfigurationException`/`AssertionFailedError` | Mockery exception names alias onto them (suites catch by upstream name, §13) |
| Coexistence gating | D-019 tri-state package detection | same check against `mockery/mockery` |
| Static alias/overload isolation | VMVM snapshot/restore + pollution detection + `#[RunInSeparateProcess]` parity | the swappable static-handler registry (§11) — the one genuinely new mechanism, and it still stores per-test state in `DoubleState` |
| `T&MockInterface` static analysis | PHPStan extension + `Mocked` marker (D-049..052) | `MockInterface` aliases the `Mocked` marker |
| Assertion counting per met expectation | D-017 already counts each settled expectation | none |
