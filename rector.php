<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests/unit',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
    )
    ->withSkip([
        // Empty methods are often load-bearing here: attribute
        // carriers in fixtures, overridable lifecycle hooks.
        RemoveEmptyClassMethodRector::class,
        // Alias targets must stay strings: those class names do not
        // exist until class_alias() creates them. Same for the
        // migration tests, where a PHPUnit class name is the expected
        // *text* of generated source, not a reference to a class this
        // package has (D-019: phpunit/phpunit is not a dependency).
        \Rector\Php55\Rector\String_\StringClassNameToClassConstantRector::class => [
            __DIR__ . '/src/Compat',
            __DIR__ . '/tests/unit/Compat/Migration',
        ],
        // The double fixtures OBSERVE constructor execution: inlining
        // ctor-assigned defaults onto the properties (or promoting
        // them) would make "was the constructor bypassed?" invisible —
        // the exact thing the builder tests assert.
        \Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector::class => [
            __DIR__ . '/tests/unit/Double/MockBuilderTest.php',
        ],
        \Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__ . '/tests/unit/Double/MockBuilderTest.php',
        ],
        // Same fixture, same reason: the declared defaults are the
        // observable pre-construction state the builder tests assert
        // (assertFalse($mock->constructed), assertSame('unset', ...)).
        // Stripping them turns those properties uninitialized, which
        // throws instead of returning the default on an unconstructed
        // double.
        RemoveDefaultValueFromAssignedPropertyRector::class => [
            __DIR__ . '/tests/unit/Double/MockBuilderTest.php',
        ],
        // FakeRealPhpUnitCase duck-types real PHPUnit\Framework\TestCase's
        // four private expectException()-family properties so the test
        // can exercise reflection reading them by name, cross-file.
        // Statically they are written and never read; removing them
        // guts the fixture (verified: 9 of its 12 tests then fail).
        // phpstan.neon carries the mirror of this exemption.
        \Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector::class => [
            __DIR__ . '/tests/unit/Dialect/Pest/RealPhpUnitExceptionExpectationsTest.php',
        ],
        // testTableNamesItsSubjectByWhateverKindOfCallableItIs enumerates
        // the five callable spellings table() must name, and
        // [Subject::class, 'thrice'] is the static-method-array one.
        // Rewriting it to Subject::thrice(...) makes it a second copy of
        // the first-class-callable case already on the line above, so the
        // array form goes untested while the assertion still passes.
        \Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector::class => [
            __DIR__ . '/tests/unit/Dialect/Pest/PestRegistryTest.php',
        ],
        // Rector cannot see through the trait-composed urlPart(): string
        // and would add a (string) cast PHPStan then flags as useless —
        // the two tools deadlock on this one file (5th rector-vs-code
        // gotcha, see D-046 for the pattern).
        \Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class => [
            __DIR__ . '/src/Browser/Playwright/PageAssertions.php',
        ],
    ]);
