# src/Polyfill

Backfills language features from newer PHP versions so the floor in `composer.json` can sit
below them, without touching call sites anywhere else in the tree. See DESIGN.md D-001 for
why the current floor is what it is.

Distinct from `src/Compat/`, which solves a different problem: PHPUnit/Mockery *namespace
coexistence* via `class_alias()`, not language-version gaps.

**Convention:**

- One subfolder per PHP version being backfilled — `Php84/`, `Php85/`, and so on. Deleting
  the whole subfolder is the entire removal the day that version becomes the floor and the
  backfill stops being needed.
- Inside each version folder, one file per backfilled capability (e.g. `ArrayFunctions.php`).
- Every file defines real global functions, `function_exists()`-guarded — never a class —
  since these stand in for actual PHP built-ins and call sites everywhere else call them as
  if they were native. That's what keeps removal call-site-free: delete the folder, the
  native function (already present on the real floor) takes over with zero other changes.
- Loaded unconditionally and early via `composer.json`'s `autoload.files`, not lazily —
  call sites can run before any other bootstrap path touches them.
