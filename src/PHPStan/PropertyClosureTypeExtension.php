<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Property\Property;
use Override;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ClosureType;
use PHPStan\Type\MethodParameterClosureTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

use function sprintf;

/**
 * The forAll signature check (D-051): variadic generics do not exist
 * in PHPStan's type grammar, so `Property::forAll(Gen<int>,
 * Gen<string>)` cannot carry "check() takes callable(int, string)"
 * through a declared type. This extension recovers it from the call
 * site instead: when check() closes a fluent chain, walk back through
 * cases()/seed() to the forAll() call, read each generator argument's
 * T, and hand the closure that exact signature. Undeclared closure
 * parameters infer to the real per-position types; a parameter the
 * user declares keeps its declaration (the extension point feeds
 * inference, not acceptance checking — measured, not assumed). A
 * Property that traveled through a variable is left untyped, never
 * guessed.
 */
final readonly class PropertyClosureTypeExtension implements MethodParameterClosureTypeExtension
{
    #[Override]
    public function isMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return $methodReflection->getDeclaringClass()->getName() === Property::class
            && $methodReflection->getName() === 'check'
            && $parameter->getName() === 'property';
    }

    #[Override]
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, ParameterReflection $parameter, Scope $scope): ?Type
    {
        $types = PropertyGenerators::fromCheckCall($methodCall, $scope);

        if ($types === null) {
            return null;
        }

        $parameters = [];

        foreach ($types as $index => $type) {
            $parameters[] = new MagicChainParameter(
                sprintf('value%d', $index + 1),
                $type,
                variadic: false,
                optional: false,
            );
        }

        // The property may assert, return false to falsify, or return
        // anything else to pass — mixed is its honest return type.
        return new ClosureType($parameters, new MixedType(), false);
    }
}
