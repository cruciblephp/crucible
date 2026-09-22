<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use Closure;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function class_exists;
use function in_array;
use function interface_exists;
use function sprintf;

/**
 * Derived return values for unconfigured double methods, per
 * the spec: scalar zero-values, empty arrays, null where nullable,
 * the double itself for self/static, and recursively created stubs
 * for object return types.
 */
final readonly class ReturnDefaults
{
    /**
     * @param Closure(class-string): object $stubFactory
     */
    public function __construct(
        private Closure $stubFactory,
    ) {}

    public function forType(?ReflectionType $type, object $double): mixed
    {
        if (!$type instanceof ReflectionType || $type->allowsNull()) {
            return null;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType) {
                    return $this->forNamed($member, $double);
                }
            }

            return null;
        }

        if ($type instanceof ReflectionIntersectionType) {
            throw new DoubleCreationException(
                'Cannot auto-generate a return value for an intersection type; configure the method with willReturn().',
            );
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->forNamed($type, $double);
        }

        return null;
    }

    private function forNamed(ReflectionNamedType $type, object $double): mixed
    {
        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'int'    => 0,
                'float'  => 0.0,
                'string' => '',
                'bool'   => false,
                'array',
                'iterable' => [],
                'callable' => static fn(): mixed => null,
                'true'     => true,
                'false'    => false,
                default    => null, // void, null, mixed, never (never throws upstream)
            };
        }

        if (in_array($name, ['self', 'static', 'parent'], true)) {
            return $double;
        }

        if (interface_exists($name) || class_exists($name)) {
            /** @var class-string $name */
            return ($this->stubFactory)($name);
        }

        throw new DoubleCreationException(sprintf(
            'Cannot auto-generate a return value of type %s; configure the method with willReturn().',
            $name,
        ));
    }
}
