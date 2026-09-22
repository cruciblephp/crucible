<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * What pest 5.1.1 answers, obtained by EXECUTING it against
 * arch-fixture/. One character per target in arch-targets.php's
 * order (25 of them): `p` pass, `f` fail, `x` REFUSED.
 * `'p'` is the positive form, `'n'` the same call through `->not`.
 *
 * A matcher aimed at an unresolvable namespace RAISES; folding that
 * into `f` would record a verdict about a universe never built.
 *
 * Written by regenerate-arch.php, never by hand.
 *
 * @return array<non-empty-string, non-empty-string>
 */

return [
    'toBeAbstract'               => ['p' => 'fppffffffffffffffffffffpp', 'n' => 'pffpppppppppppppppppppppp'],
    'toBeCasedCorrectly'         => ['p' => 'ppppppppppppppppppppppppp', 'n' => 'fffffffffffffffffffffffff'],
    'toBeClass'                  => ['p' => 'ppfffffpppppppppppppppppp', 'n' => 'ffppfffffffffffffffffffpp'],
    'toBeClasses'                => ['p' => 'ppfffffpppppppppppppppppp', 'n' => 'ffppfffffffffffffffffffpp'],
    'toBeEnum'                   => ['p' => 'ffffpppffffffffffffffffpp', 'n' => 'ppppfffpppppppppppppppppp'],
    'toBeEnums'                  => ['p' => 'ffffpppffffffffffffffffpp', 'n' => 'ppppfffpppppppppppppppppp'],
    'toBeFinal'                  => ['p' => 'fffffffpppppppppppppppppp', 'n' => 'fpppfffffffffffffffffffpp'],
    'toBeIntBackedEnum'          => ['p' => 'fffffpfffffffffffffffffpp', 'n' => 'pppppfppppppppppppppppppp'],
    'toBeIntBackedEnums'         => ['p' => 'fffffpfffffffffffffffffpp', 'n' => 'pppppfppppppppppppppppppp'],
    'toBeInterface'              => ['p' => 'ffpffffffffffffffffffffpp', 'n' => 'ppfpppppppppppppppppppppp'],
    'toBeInterfaces'             => ['p' => 'ffpffffffffffffffffffffpp', 'n' => 'ppfpppppppppppppppppppppp'],
    'toBeInvokable'              => ['p' => 'ffffffffpffffffffffffffpp', 'n' => 'ppppppppfpppppppppppppppp'],
    'toBeReadonly'               => ['p' => 'fffffffpfffffffffffffffpp', 'n' => 'ppppffffppppppppppppppppp'],
    'toBeStringBackedEnum'       => ['p' => 'ffffffpffffffffffffffffpp', 'n' => 'ppppppfpppppppppppppppppp'],
    'toBeStringBackedEnums'      => ['p' => 'ffffffpffffffffffffffffpp', 'n' => 'ppppppfpppppppppppppppppp'],
    'toBeTrait'                  => ['p' => 'fffpfffffffffffffffffffpp', 'n' => 'pppfppppppppppppppppppppp'],
    'toBeTraits'                 => ['p' => 'fffpfffffffffffffffffffpp', 'n' => 'pppfppppppppppppppppppppp'],
    'toBeUsedInNothing'          => ['p' => 'pffpppppppppppfpfpppppppp', 'n' => 'xxxxxxxxxxxxxxxxxxxxxxxxx'],
    'toExtendNothing'            => ['p' => 'ppppppppppppfpppppppppppp', 'n' => 'ffffffffffffpffffffffffpp'],
    'toHaveConstructor'          => ['p' => 'fffffffpffpffffffffffffpp', 'n' => 'pppppppfppfpppppppppppppp'],
    'toHaveDestructor'           => ['p' => 'fffffffffffpfffffffffffpp', 'n' => 'pppppppppppfppppppppppppp'],
    'toHaveMethodsDocumented'    => ['p' => 'pppppppppppppppppppfppppp', 'n' => 'ffffpppfffffffffppfpffppp'],
    'toHavePropertiesDocumented' => ['p' => 'ppppppppppfppppppppfppppp', 'n' => 'ppppppppppppppppppfpppppp'],
    'toImplementNothing'         => ['p' => 'ppppfffppppppfppppppppppp', 'n' => 'ffffpppffffffpfffffffffpp'],
    'toUseNothing'               => ['p' => 'ppppppppppppffpfppppppppp', 'n' => 'xxxxxxxxxxxxxxxxxxxxxxxxx'],
    'toUseStrictEquality'        => ['p' => 'pppppppppppppppppppppfppp', 'n' => 'ppppppppppppppppppppfpppp'],
    'toUseStrictTypes'           => ['p' => 'pppppppppppppppppppppfppp', 'n' => 'fffffffffffffffffffffpfpp'],
];
