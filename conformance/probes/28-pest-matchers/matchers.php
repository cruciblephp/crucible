<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The zero-argument matchers swept over the shared corpus.
 *
 * The exclusions are the point, so they are named rather than implied:
 *
 * - The arch family (`toBeClass`, `toBeFinal`, `toHaveConstructor`, …)
 *   sweeps a namespace's source files in the incumbent and passes
 *   vacuously on anything else. Crucible answers them one class-string
 *   at a time by decision (D-066), so every row would "diverge" and
 *   say nothing new.
 * - `toMatchSnapshot` / `toMatchInlineSnapshot` need a live test context
 *   and write files; a sweep would record where it ran, not what it does.
 * - The filesystem matchers answer about paths on the running machine,
 *   which differ between the two checkouts by construction.
 * - `toContain` / `toContainEqual` are variadic; called with no argument
 *   they are a usage error, not a verdict.
 *
 * @return list<non-empty-string>
 */

return [
    'toBeAlpha', 'toBeAlphaNumeric', 'toBeArray', 'toBeBool', 'toBeCallable',
    'toBeCamelCase', 'toBeDigits', 'toBeDomain', 'toBeEmail', 'toBeEmpty',
    'toBeFalse', 'toBeFalsy', 'toBeFloat', 'toBeHexadecimal', 'toBeHostname',
    'toBeInfinite', 'toBeInt', 'toBeIpAddress', 'toBeIterable', 'toBeJson',
    'toBeKebabCase', 'toBeList', 'toBeLowercase', 'toBeMacAddress', 'toBeNan',
    'toBeNull', 'toBeNumeric', 'toBeObject', 'toBeResource', 'toBeScalar',
    'toBeSlug', 'toBeSnakeCase', 'toBeString', 'toBeStudlyCase', 'toBeTrue',
    'toBeTruthy', 'toBeUlid', 'toBeUppercase', 'toBeUrl', 'toBeUuid',
    'toHaveCamelCaseKeys', 'toHaveKebabCaseKeys', 'toHaveSnakeCaseKeys',
    'toHaveStudlyCaseKeys',
];
