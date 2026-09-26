<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use Override;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/**
 * `expect($value)` is `Expectation<typeof $value>` (D-128). An extension
 * rather than `@template` on the function: a template the analyser
 * cannot resolve — an argument that is already an error, an undefined
 * method's result — is reported as a second error on a line that has
 * one, measured on spatie/laravel-data's suite (53 of them). Here such
 * an argument simply carries mixed.
 */
final class ExpectFunctionReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    #[Override]
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'expect';
    }

    #[Override]
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): Type
    {
        $args = $functionCall->getArgs();

        return ExpectationChainReturnTypeExtension::chainOf(
            isset($args[0]) ? ExpectationChainReturnTypeExtension::subject($scope->getType($args[0]->value)) : new MixedType(),
        );
    }
}
