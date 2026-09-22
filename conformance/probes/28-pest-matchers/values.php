<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The shared corpus every zero-argument matcher is run over, in BOTH
 * engines, indexed by position so a value's type survives the trip —
 * base64 of a string cannot carry `NAN`, `[]` or an object.
 *
 * Chosen for boundaries rather than coverage: the empty string, `'0'`
 * (falsy where `'9'` is not), digits-as-string, non-ASCII case, each
 * case convention and its malformed neighbours, and one value per PHP
 * type. Divergences hide at edges, not in the middle.
 *
 * The last three rows were added after the first sweep, because it had
 * found the mechanisms but could not separate them:
 *
 * - `'-0-'`, `'#0#'`, `'0!'`, `'---'` reduce to `'0'` or to nothing, so
 *   they reach the incumbent's falsy-`'0'` trap through a value that is
 *   not itself `'0'`. The first corpus could only see the trap where
 *   someone had already thought to look for it.
 * - `'ÿ'`, `'ßa'`, `'aÿ'`, `'çé'`, `'Ab'`, `'Abc'`, `'Ábc'` pin the
 *   case minimums and their alphabet: one non-ASCII lowercase letter is
 *   kebab-case but not camelCase, and `'Ab'` is not StudlyCase while
 *   `'Abc'` is.
 * - `98`, `65` and `1.0` separate a `(string)` cast from `ctype_*`,
 *   which reads an int in that range as a codepoint. `98` is `'98'` to
 *   one and `'b'` to the other, and that single value is what proved
 *   the incumbent casts.
 *
 * The last five are objects, and until the transport changed they could
 * not be here at all: the corpus used to reach the oracle through
 * `var_export()`, and an SplFileInfo, an ArrayObject or a Closure all
 * export as a `__set_state()` call the far side cannot resolve. Both
 * entry points now `require` this file by absolute path instead, so the
 * value axis is no longer bounded by what can survive a round trip.
 *
 * That bound had cost real bugs. Every value above is an array or a
 * scalar, and that blind spot hid the ArrayObject key-case divergence
 * (D-104) and all 36 cells of the cast family's false-green on an
 * unrenderable object (D-105) -- both in matchers the sweep already
 * covered. Each group below exists to put one of those under the grid:
 *
 * - `stdClass` and a Closure are the subjects PHP refuses to render, so
 *   the cast family must decline rather than answer. That is D-105's
 *   guard, which until now only the suite could exercise.
 * - `ArrayObject` is both at once: unrenderable as a string AND
 *   iterable, so the cast family declines it while the key-case family
 *   answers about its keys. One value, two requirements.
 * - `SplFileInfo` and the anonymous Stringable are renderable, and are
 *   read by default rather than through a quirk (D-106) -- PHP accepts
 *   a Stringable wherever a string parameter is declared. The second
 *   returns `'localhost'` on purpose: the cast family reads it, while
 *   the `is_string` family refuses it exactly as the incumbent does,
 *   and that pair is the asymmetry recorded in D-106.
 *
 * No Generator, and the reason is mechanical rather than principled: one
 * corpus is swept by every matcher in turn, and a Generator is consumed
 * by the first of them. A non-array iterable that survives reuse is what
 * ArrayObject is doing here.
 *
 * A resource is absent for its own reason -- it would need an `fopen` at
 * require time that nothing ever closes. `Quirk::StringifiedSubject`
 * covers it in the suite instead.
 *
 * APPEND ONLY, never insert: divergences.php addresses cells by index.
 *
 * @return list<mixed>
 */

return [
    '', '0', '9', 'abc', 'ABC', 'AbC', 'ábc', 'ÁBC', '123', '12.3', '-1',
    'a b', 'a-b', 'a_b', '-a', 'a-', '_a', 'a_', 'a--b', 'a__b',
    'camelCase', 'PascalCase', 'kebab-case', 'snake_case', 'c1', 'S', 'ß',
    '{"a":1}', '{oops', 'null', '"s"',
    'https://a.example', 'not a url', 'a.example', 'localhost',
    '123e4567-e89b-12d3-a456-426614174000', '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'ff', '0xff', 'lucho@x.example', 'bad@@x', '192.168.1.1', '3D:F2:C9:A6:B3:4F',
    0, 1, -1, 0.0, 1.5, true, false, null,
    [], [1, 2], ['a' => 1], ['a', 'b'],
    '-0-', '#0#', '0!', '---', '00', 'a0',
    'ÿ', 'ßa', 'aÿ', 'çé', 'Ab', 'Abc', 'Ábc',
    98, 65, 1.0,
    new stdClass(),
    static fn(): int => 1,
    new ArrayObject(['camelKey' => 1]),
    new SplFileInfo('abc'),
    new class implements Stringable {
        public function __toString(): string
        {
            return 'localhost';
        }
    },
];
