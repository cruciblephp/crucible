<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * What the incumbent's value matchers actually do, one row per input.
 *
 * Written down because the names do not determine the behaviour and
 * guessing got four of the eight wrong (D-103). `toBeSlug` asks whether
 * a slug can be MADE, not whether one is already there; `toBeDomain` is
 * `toBeHostname` plus a dot; `toBeHexadecimal` refuses the `0x` prefix;
 * `toBeUlid` is uppercase-only with no range on the first character.
 *
 * Every verdict here was obtained by EXECUTING Pest 5.1.1, never by
 * reading it — the same clean-room discipline as every other oracle. The
 * table is the record, so the result survives on machines where the
 * oracle is not installed (ORACLES.md), and `compare.php` re-proves it
 * wherever it is.
 *
 * `quirk` names the row where the two engines disagree ON PURPOSE, and
 * which `->quirks()` value reconciles them. A row where the verdicts
 * differ and no quirk is named is a finding, not a footnote — that is
 * the whole point of recording the expected disagreement rather than
 * just the agreements.
 *
 * NOT covered here, and deliberately: the arch-flavoured matchers
 * (`toBeClass`, `toHaveConstructor`, `toHaveMethods`, `toBeCasedCorrectly`,
 * `toHaveLineCountLessThan`, `toHaveFileSystemPermissions`). Executed
 * against a real namespace they sweep its source files; handed anything
 * else they match nothing and PASS. A table of inputs cannot express a
 * matcher whose subject is a namespace, and Crucible answers those one
 * class-string at a time by a decision (D-066) rather than by accident.
 * `toHaveSuspiciousCharacters` is absent because it does not exist in
 * 5.1.1 at all.
 *
 * @return array<string, list<array{value: string, pest: 'pass'|'fail', quirk?: non-empty-string}>>
 */

return [
    'toBeEmail' => [
        ['value' => 'lucho@x.example', 'pest' => 'pass'],
        ['value' => 'bad@@x', 'pest' => 'fail'],
        ['value' => 'a@b.c', 'pest' => 'pass'],
        ['value' => '', 'pest' => 'fail'],
        ['value' => 'plain', 'pest' => 'fail'],
    ],
    'toBeHostname' => [
        ['value' => 'localhost', 'pest' => 'pass'],
        ['value' => 'crucible.example', 'pest' => 'pass'],
        ['value' => '127.0.0.1', 'pest' => 'pass'],
        ['value' => 'a.', 'pest' => 'pass'],
        ['value' => '.a', 'pest' => 'fail'],
        ['value' => 'a..b', 'pest' => 'fail'],
        ['value' => '-a.b', 'pest' => 'fail'],
        ['value' => 'not a host', 'pest' => 'fail'],
        ['value' => 'a-b.c', 'pest' => 'pass'],
        ['value' => '', 'pest' => 'fail'],
        ['value' => '0', 'pest' => 'fail'],
        ['value' => '9', 'pest' => 'pass'],
    ],
    'toBeDomain' => [
        ['value' => 'localhost', 'pest' => 'fail'],
        ['value' => 'crucible.example', 'pest' => 'pass'],
        ['value' => '127.0.0.1', 'pest' => 'pass'],
        ['value' => 'a.', 'pest' => 'pass'],
        ['value' => '.a', 'pest' => 'fail'],
        ['value' => 'a..b', 'pest' => 'fail'],
        ['value' => '-a.b', 'pest' => 'fail'],
        ['value' => 'not a host', 'pest' => 'fail'],
        ['value' => 'a-b.c', 'pest' => 'pass'],
        ['value' => '', 'pest' => 'fail'],
    ],
    'toBeIpAddress' => [
        ['value' => '192.168.1.1', 'pest' => 'pass'],
        ['value' => '::1', 'pest' => 'pass'],
        ['value' => '192.168.1.256', 'pest' => 'fail'],
        ['value' => '', 'pest' => 'fail'],
        ['value' => '0.0.0.0', 'pest' => 'pass'],
    ],
    'toBeMacAddress' => [
        ['value' => '3D:F2:C9:A6:B3:4F', 'pest' => 'pass'],
        ['value' => '3D:F2:C9:A6:B3', 'pest' => 'fail'],
        ['value' => '', 'pest' => 'fail'],
        ['value' => '00-1B-63-84-45-E6', 'pest' => 'pass'],
    ],
    'toBeSlug' => [
        ['value' => 'run-tests', 'pest' => 'pass'],
        ['value' => 'Run Tests!', 'pest' => 'pass'],
        ['value' => '---a---', 'pest' => 'pass'],
        ['value' => 'ß', 'pest' => 'fail'],
        ['value' => '日本', 'pest' => 'fail'],
        ['value' => '-_-', 'pest' => 'fail'],
        ['value' => '', 'pest' => 'fail'],
        ['value' => '0', 'pest' => 'fail'],
        ['value' => '9', 'pest' => 'pass'],
        ['value' => 'a@b', 'pest' => 'pass'],
        ['value' => '  a  ', 'pest' => 'pass'],
        ['value' => '_a_', 'pest' => 'pass'],
        ['value' => '1.2', 'pest' => 'pass'],
    ],
    'toBeUlid' => [
        ['value' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'pest' => 'pass'],
        ['value' => '01arz3ndektsv4rrffq69g5fav', 'pest' => 'fail'],
        ['value' => '0123456789ABCDEFGHIJKMNPQR', 'pest' => 'fail'],
        ['value' => '0123456789ABCDEFGHJKMNPQR', 'pest' => 'fail'],
        ['value' => 'ZZZZZZZZZZZZZZZZZZZZZZZZZZ', 'pest' => 'pass'],
        ['value' => '0123456789ABCDEFGHJKMNPQRU', 'pest' => 'fail'],
        ['value' => '', 'pest' => 'fail'],
    ],
    'toBeHexadecimal' => [
        ['value' => 'ff', 'pest' => 'pass'],
        ['value' => 'FF', 'pest' => 'pass'],
        ['value' => '0xff', 'pest' => 'fail'],
        ['value' => '0XFF', 'pest' => 'fail'],
        ['value' => '#ff', 'pest' => 'fail'],
        ['value' => '0', 'pest' => 'pass'],
        ['value' => '', 'pest' => 'fail'],
        ['value' => 'f f', 'pest' => 'fail'],
        ['value' => 'deadBEEF', 'pest' => 'pass'],
    ],
];
