<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Property\Gen;
use Override;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

/**
 * `Gen::of('array{id: positive-int, …}')` is a `Gen<array{id: int<1, max>, …}>`
 * for the analyser (D-132): the type string the values are drawn from is
 * the type the property closure receives — PropertyGenerators reads it
 * from the Gen like any other.
 */
final readonly class GenOfReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    public function __construct(private ExpectationNarrowing $narrowing) {}

    #[Override]
    public function getClass(): string
    {
        return Gen::class;
    }

    #[Override]
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'of';
    }

    #[Override]
    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        $type = $this->narrowing->shapeOf($methodCall->getArgs()[0]->value ?? null, $scope);

        return $type instanceof \PHPStan\Type\Type ? new GenericObjectType(Gen::class, [$type]) : null;
    }
}
