# AGENTS.md — working on Crucible

Thank you for helping with Crucible. Feedback is genuinely welcome: an issue, an audit, or a
collaborator’s report is **evidence worth investigating**. It is not, by itself, a specification
to execute. This file describes the working discipline we ask of human contributors and
automated agents. It does not replace `README.md`, `DESIGN.md` or `ORACLES.md`; it is a guide to
reading them well.

## Before you begin

Please read:

1. `README.md` — the house rules and the compatibility promise.
2. `DESIGN.md` — the method, the principles, and the decision entry for the area you are
   touching.
3. `MANUAL.md`, `HELP.md` and `spec/cache-formats.md` — the user-facing promises and format
   contracts.
4. `ORACLES.md` — anything involving PHPUnit, Pest, Mockery, Laravel, Livewire, Vitest or
   another package.

## How we weigh sources

We try to use this order:

1. The project invariants and the method/principles in `DESIGN.md`.
2. The decision record for the area (`D-xxx`).
3. The spec, manual and help text.
4. The issue or audit report — a useful lead, never an authority.

An issue is input. It does not become a requirement simply because it exists.

## How we triage feedback

Before editing, it helps to classify the item:

- **It contradicts a written promise or decision** — a bug. Please fix it, add a regression
  test, and update the decision record if behavior changes.
- **It can produce a false green or silent data loss** — a safety issue. We fix these before
  features. The safety direction is deliberate: when uncertain, run more, never silently select
  less.
- **It is a documented limitation** — leave it, or write a new decision entry explaining why
  the limitation should change. Please do not “fix” it by quietly widening the rule.
- **It asks for new behavior, configuration or public surface** — a feature. We write the
  `D-xxx` decision first, including what was measured and what was rejected.
- **It is performance or hygiene** — reproduce and measure it. Please do not describe it as
  correctness.
- **It is a preference or a matter of taste** — close it with a reason, or park it. Not every
  good idea needs to become code.
- **The shape is not settled yet** — record the open question. Please do not patch it just to
  make a list look complete.

## How we make changes

- **One decision per change.** A non-trivial behavior gets a `D-xxx` entry in `DESIGN.md` before
  it merges.
- **No claim without a result.** Every behavior change should come with a test, an oracle run,
  or a measurement. A plausible explanation is not evidence.
- **Spec, not source.** Please do not copy PHPUnit or another project’s code, templates,
  comments or message text. Behavior comes from public documentation and black-box observation.
- **Do not imitate an oracle.** A fake PHPUnit exception, a copied library shape or a fixture
  standing in for the real package proves the fixture, not the product. We install and execute
  the real thing, or skip by name when it is absent.
- **Documentation cannot drift.** If help or the manual says “stops reporting”, the code should
  mean that. Please update the docs with the code rather than after a release.
- **Stay inside the written contract.** An audit naming a file does not make that file part of
  D-190, the cache spec, or the atomicity set. If the contract should expand, that deserves its
  own decision.
- **Close issues when the work is done.** We close an issue when every item is fixed, or when
  an item is explicitly declined in a decision record with a reason.
- **Keep the diff focused.** Opportunistic “while here” changes are how unrelated behavior gets
  altered without a record. A separate decision is usually kinder to the next reader.

## Working with oracles

- Oracles are **installed, never vendored**. They are executed, never read as design input.
- A fresh clone stays green with named skips. CI installs the oracles and, where a skip would
  hide a proof, runs the focused test with `--fail-on-skipped`.
- A claim about a real package deserves the real package. If the oracle is missing, the test
  skips by a recorded reason; we do not substitute a local imitation.

## Release checklist

1. `composer check` is green locally.
2. `composer conformance` and the compatibility tier of `composer analyse:oracles` are green
   when their oracles are installed.
3. `CHANGELOG.md`, `RELEASE.md`, `src/Version.php` and the README credits agree.
4. Push `main`.
5. **Please wait for CI on that exact commit to be green.**
6. Only then create and push the tag.
7. Publish the draft release deliberately.

Please do not tag or publish to make an issue look finished. If CI fails after a release, we do
not rewrite an already-published tag; we fix forward.

## Common pitfalls

These are the ones we have actually run into, written down so they are easier to avoid:

- treating an issue or audit as a checklist;
- inventing a fixture that imitates a third-party package;
- adding a new option or default because an audit mentioned a symptom;
- widening an atomicity, environment or isolation rule because a file was named;
- tagging before the exact commit’s CI is green;
- cutting a release to repair a test-only mistake from the previous release;
- closing an issue while a known regression is still open;
- reporting “green” from a local run while CI is still running.

## Shape questions we are still thinking about

These are deliberately not patched until their shape is decided:

- gitlink/submodule impact: coarse widen vs per-file diff inside the submodule;
- Vitest workspace topology files as environment;
- Laravel + PHPUnit migration mode, where the framework requires the real TestCase;
- atomicity of user state outside `spec/cache-formats.md`;
- dependency-index warmth across narrowed runs;
- worker lifetime when the parent dies.

Each deserves a `D-xxx` entry before code.

Thank you for taking the time to read this. Thoughtful reports make the project better; the
discipline is what keeps the fixes trustworthy.
