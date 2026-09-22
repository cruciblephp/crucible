<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * What Pest 5.1.1 answers for every zero-argument matcher over every
 * value in the shared corpus, obtained by EXECUTING it. One character
 * per value, in corpus order: `p` pass, `f` fail, `x` REFUSED — the
 * incumbent declined to answer at all, raising InvalidExpectationValue
 * because the subject is the wrong type for that matcher.
 *
 * The third state is not decoration. Catching Throwable and calling it
 * `f` records "the assertion failed" for a case where nothing was
 * asserted, and a matcher that does not exist would read the same way.
 * 373 of these cells were recorded as failures before the distinction
 * existed.
 *
 * A grid rather than a row-per-case list so a change shows up as a
 * single flipped character on one line, which is what makes a diff of
 * this file readable when the incumbent moves.
 *
 * Written by regenerate.php, never by hand: editing a row here would
 * make the probe agree with whatever Crucible does, which is the one
 * thing it exists to prevent.
 *
 * @return array<non-empty-string, non-empty-string>
 */

return [
    'toBeAlpha'            => 'fffpppffffffffffffffppfffpfffpffffpffpfffffffffffffppppffffffffffppffffxxxpp',
    'toBeAlphaNumeric'     => 'fpppppffpfffffffffffppffppfffpffffpfpppffffppfpfpffppppffffppffffppfpppxxxpp',
    'toBeArray'            => 'fffffffffffffffffffffffffffffffffffffffffffffffffffppppfffffffffffffffffffff',
    'toBeBool'             => 'ffffffffffffffffffffffffffffffffffffffffffffffffppffffffffffffffffffffffffff',
    'toBeCallable'         => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffpfff',
    'toBeCamelCase'        => 'fffpffpfffffffffffffpffffffffpffffpffpffffffffffffffffffffffffpppffffffxxxpp',
    'toBeDigits'           => 'fppfffffpffffffffffffffffffffffffffffffffffppfpfpffffffffffpffffffffpppxxxff',
    'toBeDomain'           => 'fffffffffpfffffffffffffffffffffffpfffffffpfxxxxxxxxxxxxfffffffffffffxxxxxxxx',
    'toBeEmail'            => 'fffffffffffffffffffffffffffffffffffffffpfffffffffffffffffffffffffffffffxxxff',
    'toBeEmpty'            => 'ppfffffffffffffffffffffffffffffffffffffffffpffpffpppffffffffffffffffffffffff',
    'toBeFalse'            => 'fffffffffffffffffffffffffffffffffffffffffffffffffpffffffffffffffffffffffffff',
    'toBeFalsy'            => 'ppfffffffffffffffffffffffffffffffffffffffffpffpffpppffffffffffffffffffffffff',
    'toBeFloat'            => 'ffffffffffffffffffffffffffffffffffffffffffffffppffffffffffffffffffffffpfffff',
    'toBeHexadecimal'      => 'fpppppffpfffffffffffffffpffffffffffffpfffffxxxxxxxxxxxxffffppffffppfxxxxxxxx',
    'toBeHostname'         => 'ffppppffppffpfffffpfpppfppfffpfffppppppffpfxxxxxxxxxxxxffffppffffppfxxxxxxxx',
    'toBeInfinite'         => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
    'toBeInt'              => 'fffffffffffffffffffffffffffffffffffffffffffpppffffffffffffffffffffffppffffff',
    'toBeIpAddress'        => 'fffffffffffffffffffffffffffffffffffffffffpfxxxxxxxxxxxxfffffffffffffxxxxxxxx',
    'toBeIterable'         => 'fffffffffffffffffffffffffffffffffffffffffffffffffffppppffffffffffffffffffpff',
    'toBeJson'             => 'fppfffffpppffffffffffffffffpfppfffffffffffffffffffffffffffffffffffffffffffff',
    'toBeKebabCase'        => 'fffpffpfffffpfppffpfffpfffpffpffffpffpffffffffffffffffffffpffppppffffffxxxpp',
    'toBeList'             => 'fffffffffffffffffffffffffffffffffffffffffffffffffffppfpfffffffffffffffffffff',
    'toBeLowercase'        => 'fffpfffffffffffffffffffffffffpffffpffpfffffffffffffffffffffffffffffffffxxxpp',
    'toBeMacAddress'       => 'ffffffffffffffffffffffffffffffffffffffffffpxxxxxxxxxxxxfffffffffffffxxxxxxxx',
    'toBeNan'              => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
    'toBeNull'             => 'ffffffffffffffffffffffffffffffffffffffffffffffffffpfffffffffffffffffffffffff',
    'toBeNumeric'          => 'fppfffffpppffffffffffffffffffffffffffffffffpppppfffffffffffpffffffffpppfffff',
    'toBeObject'           => 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffppppp',
    'toBeResource'         => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
    'toBeScalar'           => 'ppppppppppppppppppppppppppppppppppppppppppppppppppfffffppppppppppppppppfffff',
    'toBeSlug'             => 'ffppppppppppppppppppppppppfppppppppppppppppfppfppffppppffffppfppfppppppxxxpp',
    'toBeSnakeCase'        => 'fffpffpffffffpffppfpfffpffpffpffffpffpfffffffffffffffffffffffppppffffffxxxpp',
    'toBeString'           => 'pppppppppppppppppppppppppppppppppppppppppppffffffffffffpppppppppppppffffffff',
    'toBeStudlyCase'       => 'fffffpfffffffffffffffpfffffffffffffffffffffffffffffppppfffffffffffppfffxxxff',
    'toBeTrue'             => 'ffffffffffffffffffffffffffffffffffffffffffffffffpfffffffffffffffffffffffffff',
    'toBeTruthy'           => 'ffpppppppppppppppppppppppppppppppppppppppppfppfppfffpppppppppppppppppppppppp',
    'toBeUlid'             => 'ffffffffffffffffffffffffffffffffffffpffffffxxxxxxxxxxxxfffffffffffffxxxxxxxx',
    'toBeUppercase'        => 'ffffpffffffffffffffffffffpfffffffffffffffffffffffffffffffffffffffffffffxxxff',
    'toBeUrl'              => 'fffffffffffffffffffffffffffffffpfffffffffffffffffffffffffffffffffffffffxxxff',
    'toBeUuid'             => 'fffffffffffffffffffffffffffffffffffpfffffffxxxxxxxxxxxxfffffffffffffxxxxxxxx',
    'toHaveCamelCaseKeys'  => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxppfpxxxxxxxxxxxxxxxxxxpxx',
    'toHaveKebabCaseKeys'  => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxppppxxxxxxxxxxxxxxxxxxfxx',
    'toHaveSnakeCaseKeys'  => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxppppxxxxxxxxxxxxxxxxxxfxx',
    'toHaveStudlyCaseKeys' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxppfpxxxxxxxxxxxxxxxxxxfxx',
];
