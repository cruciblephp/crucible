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
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\Type;

/**
 * `$this` inside the dialect globals' closures (D-050): the runtime
 * binds test, hook and check bodies onto the uses() class via
 * Closure::bind — this extension tells PHPStan the same story,
 * through the shared per-file resolver.
 */
final readonly class DialectClosureThisExtension implements FunctionParameterClosureThisExtension
{
    /**
     * function name => the closure parameter the runtime binds.
     */
    private const array BOUND_PARAMETERS = [
        'test'       => 'closure',
        'it'         => 'closure',
        'check'      => 'test',
        'beforeEach' => 'hook',
        'afterEach'  => 'hook',
        'property'   => 'arguments',
    ];

    public function __construct(
        private DialectThisResolver $resolver,
    ) {}

    #[Override]
    public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
    {
        return (self::BOUND_PARAMETERS[$functionReflection->getName()] ?? null) === $parameter->getName();
    }

    #[Override]
    public function getClosureThisTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return $this->resolver->forFile($scope->getFile());
    }
}
