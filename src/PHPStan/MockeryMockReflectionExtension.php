<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Double\Mockery\MockeryExpectation;
use LucianoPereira\Crucible\Double\Mockery\MockeryMock;
use Override;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;

use function in_array;

/**
 * The Mockery-surface runtime contract told to the analyzer (D-060):
 * a MockeryMock accepts any method — configured ones dispatch,
 * unconfigured ones are the grammar's own named runtime errors — and
 * the four verbs open an expectation chain. Real methods win; PHPStan
 * asks extensions only for names native reflection lacks. The honest
 * cost is the same as D-050 stated for the dialect grammar: a typo'd
 * method on a mock is a runtime failure, not an analysis find,
 * exactly as it is for every Mockery suite in existence.
 */
final readonly class MockeryMockReflectionExtension implements MethodsClassReflectionExtension
{
    private const array VERBS = ['shouldReceive', 'shouldNotReceive', 'allows', 'expects'];

    #[Override]
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->is(MockeryMock::class);
    }

    #[Override]
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new MagicChainMethod(
            $methodName,
            $classReflection,
            in_array($methodName, self::VERBS, true)
                ? new ObjectType(MockeryExpectation::class)
                : new MixedType(),
        );
    }
}
