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
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/**
 * A synthesized parameter (D-050/D-051): by default the one
 * accept-anything variadic of a magic chain step — the runtime
 * signature of __call is exactly "any arguments" — and, given a name
 * and a type, one positional parameter of a synthesized closure
 * signature (the property closures of D-051).
 */
final readonly class MagicChainParameter implements ParameterReflection
{
    public function __construct(
        private string $name = 'arguments',
        private ?Type $type = null,
        private bool $variadic = true,
        private bool $optional = true,
    ) {}

    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function isOptional(): bool
    {
        return $this->optional;
    }

    #[Override]
    public function getType(): Type
    {
        return $this->type ?? new MixedType();
    }

    #[Override]
    public function passedByReference(): PassedByReference
    {
        return PassedByReference::createNo();
    }

    #[Override]
    public function isVariadic(): bool
    {
        return $this->variadic;
    }

    #[Override]
    public function getDefaultValue(): ?Type
    {
        return null;
    }
}
