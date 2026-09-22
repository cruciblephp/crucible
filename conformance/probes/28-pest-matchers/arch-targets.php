<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The shared corpus every ARCH matcher is run over, in both engines.
 *
 * The value grid could not ask these matchers anything, and
 * `excluded.php` says why in one line: the corpus holds values, and an
 * arch matcher takes a namespace and answers about the resolved SET.
 * `expect('abc')->toBeClasses()` records a verdict about the string
 * 'abc'. So this is a second AXIS, not a second grid — every target
 * below names a namespace in `arch-fixture/src`, and the same `cell()`
 * classification, the same `inverted()` relation and the same
 * write-run-unlink transport carry it.
 *
 * One namespace per question, and each is small enough that its answer
 * is unambiguous. A target holding both an abstract and a final class
 * answers `f` to both `toBeAbstract` and `toBeFinal`, which records the
 * mixture rather than either property.
 *
 * `ArchFixture\Nothing` matches no symbol on purpose: a target that
 * resolves to nothing is the case D1 settled (both engines pass, and
 * warn), and it is the one target whose column should be uniform.
 *
 * APPEND ONLY, never insert: findings address targets by index, exactly
 * as divergences.php does for values.
 *
 * @return list<non-empty-string>
 */

return [
    'ArchFixture\Kind\Classes',
    'ArchFixture\Kind\Abstracts',
    'ArchFixture\Kind\Interfaces',
    'ArchFixture\Kind\Traits',
    'ArchFixture\Kind\PureEnums',
    'ArchFixture\Kind\IntEnums',
    'ArchFixture\Kind\StringEnums',
    'ArchFixture\Kind\Immutable',
    'ArchFixture\Kind\Invokable',
    'ArchFixture\Shape\Bare',
    'ArchFixture\Shape\WithCtor',
    'ArchFixture\Shape\WithDtor',
    'ArchFixture\Shape\Extending',
    'ArchFixture\Shape\Implementing',
    'ArchFixture\Deps\Pure',
    'ArchFixture\Deps\Crossing',
    'ArchFixture\Deps\Used',
    'ArchFixture\Deps\Unused',
    'ArchFixture\Docs\Documented',
    'ArchFixture\Docs\Undocumented',
    'ArchFixture\Strictness\Strict',
    'ArchFixture\Strictness\Loose',
    'ArchFixture\Casing\Correct',
    'ArchFixture\Casing\Wrong',
    'ArchFixture\Nothing',
];
