# Crucible v2 — proposal

**Status:** proposal, not a decision. No v2 code lands until a `D-xxx` records the
contract. This document is input for that decision; it is not a roadmap.

## Why a major

v1's promise is parity: a PHPUnit 13 suite runs unchanged. v2's thesis is different:

> The native Crucible shape is the primary API. PHPUnit/Pest compatibility is a
> migration substrate, frozen at PHPUnit 13 / Pest 5, not a downstream mirror.

The old shapes exist so tests already written and working keep running. They are
maintained for runtime/bug compatibility, not for upstream's future features. New
work goes to the native shape; a project that wants PHPUnit 14 or Pest 6 features
migrates, it does not wait for the bridge to mirror them.

## The native tiers

| Tier | Shape | Production execution | Notes |
|---|---|---|---|
| Static contract | docblock + PHPStan plugin | none | types and constants only |
| Mild in-code | `@crucible expect(...)` comment | none | one expression; `save_comments` caveat |
| Literate docs | Markdown + sigilmd markers | none | the document is the test and the report |
| Attribute | `#[Test]` method beside the code | none | private access; method/metadata footprint |
| Separate file | the existing suite shape | none | integration and legacy |

The tiers are deliberate: static analysis proves types; a doctest proves a value; a
document proves an example; an attribute test proves behaviour with private access;
a separate file is the integration surface.

## Literate docs: the sigilmd grammar

No TT2, no `+++` frontmatter, no new template language. The document shape is
[sigilmd](https://github.com/lucianofedericopereira/sigilmd)'s marker grammar,
reimplemented in PHP (no Perl/CPAN dependency):

- `<!-- [[ name ]] -->` declares a table; the body is the string-only TOML subset
  (`key = "value"`, `#` comments; no arrays, numbers, booleans or nested tables).
- `<!-- [[ $table.key ]] -->` inserts a value; `<!-- [[ @table.key ]] -->` reads a
  project-relative file; references chain by juxtaposition.
- `<!--/-->` closes a marker body; reruns replace only the body; `check` fails when
  regeneration would differ.

The same document is the test and the report:

````md
<!-- [[ test ]] -->
title = "Cart totals"
suite = "docs"
tags = "cart,money"
<!--/-->

A cart with two items totals the sum.

```php
$cart = new Cart();
$cart->add(1);
$cart->add(2);
```

Total: <!-- [[ $cart.total ]] -->3<!--/-->
````

The runner executes the block, produces `cart.total`, and writes it between the
markers. `crucible --docs --check` fails in CI if the document is stale. The source
stays prose; only marker bodies change, so the diff is reviewable and idempotent.

## Static contracts

PHPStan is incremental, parallel and cached per file; a static tier rides that same
run, not a second execution path.

```php
/**
 * @crucible expect(Cart::total(1, 2))->toBeInt()
 * @crucible expect($name)->toBeString()
 */
```

`toBe('b')` is **not** a static test in general; it is a doctest or an attribute test.
Static analysis proves types and constant expressions, not arbitrary runtime values.
Do not call a static assertion a test if it never runs; call it a contract.

## Attributes and overhead

PHP attributes are available now; 8.6 is not required. A co-located `#[Test]` method
gives private access and no separate file.

The honest overhead:

- **Execution:** zero in production; the method is not called.
- **Compile/opcache:** method opcodes and attribute metadata; real, proportional to
  the test code. A strip step is the only way to zero footprint.
- **Discovery:** a short ctags-style prefilter (`str_contains`/token pass) finds
  candidates; parse only those. Test-time only.
- **Comments:** zero execution; docblock text is retained only with
  `opcache.save_comments=1`. With `save_comments=0`, reflection loses the doctest;
  the runner must read source or require the setting.
- **Helpers:** a test-only helper is production code unless marked/grouped; a strip
  rule must know it. Individual method attributes cannot strip a shared helper.

Measure before claiming: a fixture with N test methods versus N doctests, opcache
memory and require time.

## Git flow

- `main` — released 1.x; tags `v1.x.y`.
- `develop` — v2 integration; protected.
- `feature/*` — short-lived, from `develop`, one decision per branch.
- `release/2.0` — stabilization; tag only after CI on the exact commit.
- `hotfix/1.x` — from `main`; tag and merge back to `develop`.

No direct pushes; PR + green CI; never rewrite a published tag. The
`AGENTS.md` release checklist still applies.

## Open questions

1. sigilmd grammar parity in PHP: exact subset, error messages, `check` output.
2. Test declaration versus report insertion: is a test a declared table, a fenced
   block, or both?
3. Fixtures and setup for a docs block; where `APP_ENV`/bootstrap live.
4. Report renderer: reuse the HTML/PDF writers or delegate to the docs generator.
5. Static analysis of docs blocks: shadow files like `lint-inline` or a dedicated
   rule.
6. Source rewriting: the default is no; an explicit update mode needs atomicity and
   diff review.
7. Overhead measurement for attributes vs doctests.
8. Bridge support window and migration path to the native shape.

## Non-goals

- PHPUnit 14 / Pest 6 feature tracking.
- Reading `phpunit.xml` or `.env` at runtime; the environment is explicit
  (`crucible.php`, `->env()`, `->environmentFile()`).
- A generator command for env or docs files.
- TT2, `+++` frontmatter, or a new template language.
- Rewriting source documents by default.
- v2 engine code before the decision entry.

## Gate

One prototype document with one code block, one hidden expectation and one generated
report value. One static contract. One co-located attribute test. Measure extraction,
analysis and render. Then write the `D-xxx`; only then code.

The pending 1.5.0 release (`->environmentFile()`) is separate from this proposal.
