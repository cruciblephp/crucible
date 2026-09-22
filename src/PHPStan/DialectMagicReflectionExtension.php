<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Dialect\Pest\TestCall;
use Override;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\ObjectType;

use function array_key_exists;

/**
 * The dialect's magic grammar told to the analyzer (D-050): PHPStan
 * does not consult __get/__call return types for names it cannot
 * find, so higher-order expectations (`expect($user)->name->toBe()`)
 * and higher-order test chains (`check(...)->toBe(3)->group('x')`)
 * resolved to errors. This extension declares what the runtime
 * guarantees: on these classes, any member exists and continues the
 * chain in kind. Real members win — PHPStan asks extensions only for
 * names native reflection lacks. The honest cost, stated: a typo'd
 * matcher is now a runtime failure, not an analysis find, exactly as
 * it is for every Pest suite in existence.
 */
final readonly class DialectMagicReflectionExtension implements PropertiesClassReflectionExtension, MethodsClassReflectionExtension
{
    /**
     * class => what a magic step returns (the chain's own class).
     * DescribeCall is deliberately absent: it declares no __call, so
     * unknown members there are real errors.
     */
    private const array CHAINS = [
        Expectation::class => Expectation::class,
        TestCall::class    => TestCall::class,
    ];

    #[Override]
    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        // Property descent on the Expectation (higher-order members)
        // and on TestCall (property steps like ->not, collected by
        // its __get) — both continue the chain in kind.
        return array_key_exists($classReflection->getName(), self::CHAINS);
    }

    #[Override]
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        return new MagicChainProperty(
            $classReflection,
            new ObjectType(self::CHAINS[$classReflection->getName()] ?? Expectation::class),
        );
    }

    #[Override]
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return array_key_exists($classReflection->getName(), self::CHAINS);
    }

    #[Override]
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new MagicChainMethod(
            $methodName,
            $classReflection,
            new ObjectType(self::CHAINS[$classReflection->getName()] ?? Expectation::class),
        );
    }
}
