<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Property\Gen;
use LucianoPereira\Crucible\Property\Property;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function in_array;

/**
 * The one place that recovers a property's per-position value types
 * from a call site (D-051/D-067): the closure-type extensions feed
 * inference from it, the acceptance rule checks declarations against
 * it. Anything unusual — named arguments, spreads, a Property that
 * traveled through a variable — answers null, never a guess.
 */
final readonly class PropertyGenerators
{
    /**
     * Walks a `Property::forAll(...)->cases()->seed()->check(fn)`
     * chain back to forAll() and reads each generator's T.
     *
     * @return ?list<Type>
     */
    public static function fromCheckCall(MethodCall $methodCall, Scope $scope): ?array
    {
        $receiver = $methodCall->var;

        // cases() and seed() return $this — transparent for the walk.
        while ($receiver instanceof MethodCall
            && $receiver->name instanceof Identifier
            && in_array($receiver->name->toString(), ['cases', 'seed'], true)
        ) {
            $receiver = $receiver->var;
        }

        if (!$receiver instanceof StaticCall
            || !$receiver->class instanceof Name
            || !$receiver->name instanceof Identifier
            || $receiver->name->toString() !== 'forAll'
            || $scope->resolveName($receiver->class) !== Property::class
        ) {
            return null;
        }

        return self::generatorTypes($receiver->getArgs(), $scope, skip: 0);
    }

    /**
     * The dialect sugar: `property('name', Gen..., $closure)` — the
     * generators sit right in the same call, before the closure.
     *
     * @return ?list<Type>
     */
    public static function fromPropertyCall(FuncCall $call, Scope $scope): ?array
    {
        return self::generatorTypes($call->getArgs(), $scope, skip: 1);
    }

    /**
     * @param array<\PhpParser\Node\Arg> $arguments
     *
     * @return ?list<Type>
     */
    private static function generatorTypes(array $arguments, Scope $scope, int $skip): ?array
    {
        $types = [];
        $seen  = 0;

        foreach ($arguments as $argument) {
            if ($argument->name !== null || $argument->unpack) {
                return null;
            }

            if ($seen++ < $skip) {
                continue;
            }

            $type = $scope->getType($argument->value);

            if (!(new ObjectType(Gen::class))->isSuperTypeOf($type)->yes()) {
                continue; // the closure itself, in the sugar form
            }

            $types[] = $type->getTemplateType(Gen::class, 'T');
        }

        return $types === [] ? null : $types;
    }
}
