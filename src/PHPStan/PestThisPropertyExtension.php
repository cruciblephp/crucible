<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\PHPStan;

use Override;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\IgnoreErrorExtension;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

use function preg_match;

/**
 * `$this->name` inside a Pest closure, for a property the bound class does
 * not declare (D-144). Pest's test class allows dynamic properties, and
 * pest-plugin-phpstan answers this way, measured: the type a `beforeEach`
 * of the same file assigned, otherwise mixed, never an undefined property.
 * Declared properties, and anything the scope already knows from an
 * assignment, are left to PHPStan.
 */
final readonly class PestThisPropertyExtension implements ExpressionTypeResolverExtension, IgnoreErrorExtension
{
    public function __construct(
        private PestHookProperties $properties,
    ) {}

    #[Override]
    public function getType(Expr $expr, Scope $scope): ?Type
    {
        $name = $this->dynamicProperty($expr, $scope);

        if ($name === null || $scope->hasExpressionType($expr)->yes()) {
            return null;
        }

        return $this->properties->typeOf($scope->getFile(), $name) ?? new MixedType();
    }

    #[Override]
    public function shouldIgnore(Error $error, Node $node, Scope $scope): bool
    {
        if ($error->getIdentifier() !== 'property.notFound') {
            return false;
        }

        if ($node instanceof PropertyFetch) {
            return $this->dynamicProperty($node, $scope) !== null;
        }

        // A write is reported on an internal node outside PHPStan's API, so
        // it is matched by the property its message names, on $this.
        if (preg_match('/::\$(\w+)\.$/', $error->getMessage(), $match) !== 1) {
            return false;
        }

        return $this->dynamicProperty(new PropertyFetch(new Variable('this'), $match[1]), $scope) !== null;
    }

    /**
     * The property's name when the node reads or writes an undeclared
     * property of `$this` inside a Pest closure, otherwise null.
     */
    private function dynamicProperty(Node $node, Scope $scope): ?string
    {
        if (!$node instanceof PropertyFetch
            || !$node->var instanceof Variable
            || $node->var->name !== 'this'
            || !$node->name instanceof Identifier
            || !$scope->isInAnonymousFunction()
            || !$this->properties->isDialectFile($scope->getFile())
        ) {
            return null;
        }

        $name = $node->name->toString();

        // Declared, not merely reachable: PestTestCase is a universal object
        // crate, on which every name would otherwise read as declared.
        foreach ($scope->getType($node->var)->getObjectClassReflections() as $class) {
            if ($class->hasNativeProperty($name)) {
                return null;
            }
        }

        return $name;
    }
}
