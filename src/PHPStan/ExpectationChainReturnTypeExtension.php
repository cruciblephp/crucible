<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use Override;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function in_array;

/**
 * The expectation chain keeps its value's type (D-128): `expect($x)`
 * is `Expectation<typeof $x>`, every step returns the same
 * `Expectation<TValue>` unless it changes the subject, and a type
 * matcher narrows it — `expect($v)->toBeInt()->value` is `int`. What
 * a migrated Pest suite's analysis knew under pest-plugin-phpstan,
 * held line by line against it by probe 30.
 *
 * Narrowing is an intersection with the matcher's type, never a guess:
 * a value that cannot be the matcher's type reads as never, exactly as
 * PHPStan reads an impossible `is_*()`. A negated receiver (`->not`,
 * `->not()`) narrows nothing, as the incumbent does.
 */
final readonly class ExpectationChainReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(private ExpectationNarrowing $narrowing) {}

    #[Override]
    public function getClass(): string
    {
        return Expectation::class;
    }

    /**
     * Only the chain's own steps: a method declared to return an
     * Expectation. The class's helpers that answer a constraint or the
     * items keep their declared types.
     */
    #[Override]
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        $chain = new ObjectType(Expectation::class);

        foreach ($methodReflection->getVariants() as $variant) {
            if (!$chain->isSuperTypeOf($variant->getReturnType())->yes()) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        $name = $methodReflection->getName();

        if ($name === 'and') {
            $args = $methodCall->getArgs();

            return self::chainOf(isset($args[0]) ? self::subject($scope->getType($args[0]->value)) : new MixedType());
        }

        if (in_array($name, ExpectationSteps::NEW_SUBJECT, true)) {
            return self::chainOf(new MixedType());
        }

        $receiver = $methodCall->var;

        // `->not` and `->each` are properties the magic reflection answers
        // without the chain's type argument; the value is the one before
        // them.
        $modifier = $this->modifier($receiver);

        $value = self::valueOf($scope->getType($modifier ?? $receiver));

        // A negated matcher proves nothing about the type; after a spread,
        // no matcher is about the value at all (ExpectationSteps).
        if (ExpectationSteps::negates($receiver) || ExpectationSteps::spreadBefore($receiver)) {
            return self::chainOf($value);
        }

        return self::chainOf($this->narrowing->afterMatcher($value, $name, $methodCall, new PropertyFetch($receiver, 'value'), $scope));
    }

    /** `Expectation<$value>`. */
    public static function chainOf(Type $value): Type
    {
        return new GenericObjectType(Expectation::class, [$value]);
    }

    /**
     * A subject's type as the chain carries it. A type the analyser
     * already reported as an error (an undefined method, a missing
     * property) becomes mixed, so the chain adds no second error on top.
     */
    public static function subject(Type $type): Type
    {
        return $type instanceof ErrorType ? new MixedType() : $type;
    }

    /** The value type an `Expectation<...>` carries; mixed when unknown. */
    public static function valueOf(Type $chain): Type
    {
        $value = $chain->getTemplateType(Expectation::class, 'TValue');

        return $value instanceof ErrorType ? new MixedType() : $value;
    }

    /**
     * The expectation before a `->not` or `->each` property step, or null
     * when the receiver is no such step.
     */
    private function modifier(Expr $receiver): ?Expr
    {
        return $receiver instanceof PropertyFetch
            && $receiver->name instanceof Identifier
            && in_array($receiver->name->toString(), ['not', 'each'], true)
                ? $receiver->var
                : null;
    }
}
