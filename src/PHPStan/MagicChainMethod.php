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
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Type;

/**
 * A magic chain step (D-050): any method name, any arguments, and the
 * chain continues — TestCall::__call collects higher-order steps and
 * returns itself; Expectation::__call runs a matcher or forwards to
 * the value and returns an Expectation. The return type is the
 * chain's own class, which is what keeps `check(...)->toBe(3)
 * ->group('x')` fully typed.
 */
final readonly class MagicChainMethod implements MethodReflection
{
    public function __construct(
        private string $name,
        private ClassReflection $declaringClass,
        private Type $returnType,
    ) {}

    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    #[Override]
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /**
     * @return list<FunctionVariant>
     */
    #[Override]
    public function getVariants(): array
    {
        return [new FunctionVariant(
            TemplateTypeMap::createEmpty(),
            null,
            [new MagicChainParameter()],
            true,
            $this->returnType,
        )];
    }

    #[Override]
    public function isStatic(): bool
    {
        return false;
    }

    #[Override]
    public function isPrivate(): bool
    {
        return false;
    }

    #[Override]
    public function isPublic(): bool
    {
        return true;
    }

    #[Override]
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    #[Override]
    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    #[Override]
    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    #[Override]
    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    #[Override]
    public function getThrowType(): ?Type
    {
        return null;
    }

    #[Override]
    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createYes();
    }

    #[Override]
    public function getDocComment(): ?string
    {
        return null;
    }
}
