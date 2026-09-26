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
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * The acceptance half the closure-type extension point cannot do
 * (D-051's measured limit, closed in D-067): a check closure whose
 * DECLARED parameter type cannot accept what its generator produces
 * is reported — `forAll(Gen::int())->check(fn (string $s) => ...)`
 * is wrong at the keyboard, not at the first drawn case. Undeclared
 * parameters stay the inference extension's job; a required parameter no
 * generator feeds is reported too. The acceptance itself is DataRows',
 * shared with every dataset form (D-137), so a draw and a row are judged
 * by one rule.
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final readonly class PropertyParameterRule implements Rule
{
    #[Override]
    public function getNodeType(): string
    {
        return Node\Expr\CallLike::class;
    }

    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $call = $this->propertyCall($node, $scope);

        if ($call === null) {
            return [];
        }

        [$types, $closure, $spelling] = $call;

        if ($types === null || $closure === null) {
            return [];
        }

        // One acceptance rule for every value that feeds a closure (D-137):
        // a generator's draw here, a dataset row there. This rule once used
        // its own, stricter reading and reported Gen::int() into a float
        // parameter, which PHP passes even under strict types.
        $values = [];

        foreach ($types as $position => $type) {
            $values[$position] = [$type, $closure->getStartLine()];
        }

        return DataRows::check(
            DataRows::fromNodes($closure->getParams(), $scope),
            $values,
            $spelling,
            'its generator',
            $closure->getStartLine(),
            $scope,
            'crucible.property',
        );
    }

    /**
     * @return ?array{?list<Type>, ?(ArrowFunction|Closure), non-empty-string} null when the node is neither call
     */
    private function propertyCall(Node $node, Scope $scope): ?array
    {
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'check'
            && (new ObjectType(Property::class))->isSuperTypeOf($scope->getType($node->var))->yes()
        ) {
            $arguments = $node->getArgs();
            $closure   = isset($arguments[0]) ? $arguments[0]->value : null;

            return [
                PropertyGenerators::fromCheckCall($node, $scope),
                $closure instanceof ArrowFunction || $closure instanceof Closure ? $closure : null,
                'The check() closure',
            ];
        }

        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $scope->resolveName($node->name) === 'property'
        ) {
            $closure = null;

            foreach ($node->getArgs() as $argument) {
                if ($argument->value instanceof ArrowFunction || $argument->value instanceof Closure) {
                    $closure = $argument->value;
                }
            }

            return [
                PropertyGenerators::fromPropertyCall($node, $scope),
                $closure,
                'The property() closure',
            ];
        }

        return null;
    }
}
