<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Assert\Assert;
use Override;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\MethodTypeSpecifyingExtension;

/**
 * Narrowing for the instance-call spelling — $this->assertNotNull($x)
 * — the spec's xUnit idiom of calling static assert methods
 * dynamically (the same idiom the strict-rules opt-out in this
 * repository's own phpstan.neon exists for). Same translation table
 * as the static twin.
 */
final class AssertMethodTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    private TypeSpecifier $typeSpecifier;

    #[Override]
    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }

    #[Override]
    public function getClass(): string
    {
        return Assert::class;
    }

    #[Override]
    public function isMethodSupported(MethodReflection $methodReflection, MethodCall $node, TypeSpecifierContext $context): bool
    {
        // Assertions narrow as statements, never inside a condition.
        return $context->null() && AssertConditions::supports($methodReflection->getName());
    }

    #[Override]
    public function specifyTypes(MethodReflection $methodReflection, MethodCall $node, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
    {
        $condition = AssertConditions::condition($methodReflection->getName(), $node->getArgs());

        // No sound condition (named or unpacked arguments): narrow
        // from `true`, which specifies nothing.
        $condition ??= new ConstFetch(new Name('true'));

        return $this->typeSpecifier->specifyTypesInCondition($scope, $condition, TypeSpecifierContext::createTruthy());
    }
}
