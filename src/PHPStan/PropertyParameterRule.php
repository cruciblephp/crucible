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
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

use function count;
use function is_string;
use function sprintf;

/**
 * The acceptance half the closure-type extension point cannot do
 * (D-051's measured limit, closed in D-067): a check closure whose
 * DECLARED parameter type cannot accept what its generator produces
 * is reported — `forAll(Gen::int())->check(fn (string $s) => ...)`
 * is wrong at the keyboard, not at the first drawn case. Undeclared
 * parameters stay the inference extension's job; extra parameters
 * beyond the generators are reported too (they would draw nothing).
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
        [$types, $closure, $spelling] = $this->propertyCall($node, $scope);

        if ($types === null || $closure === null) {
            return [];
        }

        $errors     = [];
        $parameters = $closure->getParams();

        if (count($parameters) > count($types)) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s declares %d parameters but only %d generator(s) feed it.',
                $spelling,
                count($parameters),
                count($types),
            ))->identifier('crucible.propertyArity')->line($closure->getStartLine())->build();
        }

        foreach ($parameters as $index => $parameter) {
            if ($parameter->type === null || !isset($types[$index])) {
                continue;
            }

            $declared = $scope->getFunctionType($parameter->type, false, false);
            $expected = $types[$index];

            if ($declared->isSuperTypeOf($expected)->yes()) {
                continue;
            }

            $name = $parameter->var instanceof Variable && is_string($parameter->var->name)
                ? '$' . $parameter->var->name
                : sprintf('#%d', $index + 1);

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s parameter %s declares %s, but its generator produces %s.',
                $spelling,
                $name,
                $declared->describe(VerbosityLevel::typeOnly()),
                $expected->describe(VerbosityLevel::typeOnly()),
            ))->identifier('crucible.propertyParameter')->line($parameter->getStartLine())->build();
        }

        return $errors;
    }

    /**
     * @return array{?list<Type>, ?(ArrowFunction|Closure), string}
     */
    private function propertyCall(Node $node, Scope $scope): array
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

        return [null, null, ''];
    }
}
