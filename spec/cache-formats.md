# Spec: cache formats other tools read

> What Crucible writes into its cache directory for tools outside it, and what another runner
> would have to write for those tools to read it the same way. The cache directory is
> `.crucible.cache` unless `->cacheDirectory()` or `--cache-directory` says otherwise.
>
> Each file is JSON with an integer `version`. A reader that finds a version it does not know
> reads the file as empty, never as an error: a stale or foreign cache must not fail a run or an
> analysis. Paths are relative to the project root, with `/` separators. Hashes are SHA-256 of
> the file's bytes, lowercase hex.

## manifest.json

The index. A reader hashes this one file to know whether any artifact changed.

```json
{
    "version": 1,
    "crucible": "1.2.0",
    "artifacts": {
        "coverage-lines.json": {"version": 2, "sha256": "…"},
        "coverage-map.json": {"version": 2, "sha256": "…"}
    }
}
```

- `crucible`: the version of the writer. Another runner writes its own name and version here.
- `artifacts`: each file of the directory listed, keyed by file name, with the version of its
  format and the hash of the file as written. Sorted by name.
- Rewritten whole each time an artifact is written: to a file beside it, then renamed over it,
  so a reader never sees half of one.

## coverage-lines.json, version 2

Which lines each test executed. Written by every run with `--coverage`.

```json
{
    "version": 2,
    "tests": {
        "tests/Unit/CartTest.php::testTotal": {"src/Cart.php": [12, 13, 15]}
    },
    "executable": {
        "src/Cart.php": [12, 13, 15, 18]
    },
    "loaded": {
        "tests/Unit/CartTest.php::testTotal": ["tests/Fixtures/Prices.php"]
    },
    "hashes": {
        "src/Cart.php": "…",
        "tests/Fixtures/Prices.php": "…"
    }
}
```

- `tests`: test id ⇒ file ⇒ the lines it executed. A test id is `file::method`, with
  `#dataset` appended for a data set (`tests/Unit/CartTest.php::testTotal#empty cart`).
- `executable`: every line the coverage driver reported for a file, executed or not. A line
  missing here compiles to no code of its own (an attribute argument, a property default).
- `loaded`: test id ⇒ files in the test directories, outside the source, that the test executed.
- `hashes`: every file named in `executable` or `loaded` ⇒ its hash when the run read it. A file
  whose hash differs now has lines that may have moved: skip it, and say so; never report on
  its old line numbers.

Version 1 had no `hashes` and is read as empty.

## coverage-map.json, version 2

Which source files each test file executed, at file granularity.

```json
{
    "version": 2,
    "edges": {
        "tests/Unit/CartTest.php": ["src/Cart.php", "src/Money.php"]
    },
    "hashes": {
        "src/Cart.php": "…",
        "src/Money.php": "…"
    }
}
```

- `edges`: test file ⇒ the source files its tests executed, in the order first seen.
- `hashes`: as in `coverage-lines.json`.

Version 1 had no `hashes` and is read as empty.

## observed-types.json, version 1

What each function of the source received and returned during a `--observe-types` run.

```json
{
    "version": 1,
    "functions": {
        "App\\Shelf::find": {
            "file": "src/Shelf.php",
            "line": 19,
            "calls": 2,
            "types": {
                "$isbn": {"non-empty-string": 2},
                "return": {"\\App\\Book": 1, "null": 1}
            }
        }
    }
}
```

- `functions`: `Class::method`, or a function's name, ⇒ where it is declared, how many calls
  returned, and per slot (`$name` or `return`) each type seen with how many calls it was seen in.
  Only calls that returned are counted; a call that threw is not.
- A type is the narrowest PHPStan type naming the value: `positive-int`, `0`, `negative-int`,
  `non-empty-string`, `''`, `class-string<\Foo>`, `array{}`, `non-empty-list<…>`, `array{key: …}`
  for up to 12 string keys, `non-empty-array<K, V>` otherwise, `\Foo` for an object. An array is
  read to its first 16 elements and three levels deep. A slot keeps at most 24 distinct types.
- Variadic parameters, generators and closures are not recorded.

## mutation-verdicts.ndjson

Not versioned, and not for other tools to rely on yet: one JSON object per line, appended as
each verdict lands so an interrupted `crucible mutate` resumes. A run that decides every mutant
rewrites it to that run's verdicts, one line per mutant.
