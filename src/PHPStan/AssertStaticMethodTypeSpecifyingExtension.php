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
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\StaticMethodTypeSpecifyingExtension;

/**
 * Narrowing for the static call forms — self::assertNotNull($x),
 * Assert::assertInstanceOf(...) — including every TestCase subclass,
 * since the assert methods declare on Assert (D-049). The narrowing
 * itself lives in AssertConditions; this class is the PHPStan wiring.
 */
final class AssertStaticMethodTypeSpecifyingExtension implements StaticMethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    private TypeSpecifier $typeSpecifier;

    public function __construct(private readonly ExpectationNarrowing $narrowing) {}

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
    public function isStaticMethodSupported(MethodReflection $staticMethodReflection, StaticCall $node, TypeSpecifierContext $context): bool
    {
        // Assertions narrow as statements, never inside a condition.
        return $context->null() && ExpectationNarrowing::readsAssertion($staticMethodReflection->getName());
    }

    #[Override]
    public function specifyTypes(MethodReflection $staticMethodReflection, StaticCall $node, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
    {
        return $this->narrowing->afterAssertion($staticMethodReflection->getName(), $node->getArgs(), $scope, $this->typeSpecifier);
    }
}
