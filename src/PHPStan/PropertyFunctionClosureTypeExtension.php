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
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ClosureType;
use PHPStan\Type\FunctionParameterClosureTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

use function sprintf;

/**
 * The dialect sugar's closure typing (D-067, closing the D-051
 * leftover): `property('name', Gen::int(), Gen::string(), fn)` hands
 * the closure its per-position parameter types straight from the
 * generator arguments of the same call — the function-level twin of
 * PropertyClosureTypeExtension's chain walk.
 */
final readonly class PropertyFunctionClosureTypeExtension implements FunctionParameterClosureTypeExtension
{
    #[Override]
    public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
    {
        return $functionReflection->getName() === 'property'
            && $parameter->getName() === 'arguments';
    }

    #[Override]
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): ?Type
    {
        $types = PropertyGenerators::fromPropertyCall($functionCall, $scope);

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

        return new ClosureType($parameters, new MixedType(), false);
    }
}
