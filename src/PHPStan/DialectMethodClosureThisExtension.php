<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Dialect\Pest\ScopeRegistration;
use LucianoPereira\Crucible\Dialect\Pest\TestCall;
use Override;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\MethodParameterClosureThisExtension;
use PHPStan\Type\Type;

use function in_array;

/**
 * The method-level halves of the binding story (D-050):
 *
 * - TestCall higher-order steps (`->expect(fn () => $this->...)`,
 *   collected via __call, surfaced by the magic reflection extension
 *   as the 'arguments' parameter) and closure skips (`->skip(fn ()
 *   => ...)`, evaluated after beforeEach) both run bound to the test
 *   instance;
 * - ScopeRegistration's per-test hooks (`pest()->beforeEach(...)`)
 *   bind the same way.
 *
 * Dataset factories and rows are deliberately absent: factories run
 * unbound, and row closures live inside array literals, beyond any
 * parameter-level extension point.
 */
final readonly class DialectMethodClosureThisExtension implements MethodParameterClosureThisExtension
{
    public function __construct(
        private DialectThisResolver $resolver,
    ) {}

    #[Override]
    public function isMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        $class = $methodReflection->getDeclaringClass()->getName();

        if ($class === TestCall::class) {
            // 'arguments' is every magic chain step; 'condition' is
            // the real skip(bool|string|Closure $condition) surface.
            return in_array($parameter->getName(), ['arguments', 'condition'], true);
        }

        if ($class === ScopeRegistration::class) {
            return $parameter->getName() === 'hook'
                && in_array($methodReflection->getName(), ['beforeEach', 'afterEach'], true);
        }

        return false;
    }

    #[Override]
    public function getClosureThisTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return $this->resolver->forFile($scope->getFile());
    }
}
