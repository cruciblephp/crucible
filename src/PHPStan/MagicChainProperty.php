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
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

/**
 * A higher-order expectation step (D-050): any property name on the
 * Expectation descends into the value and returns another
 * Expectation — `expect($user)->name->toBe(...)`. Read-only, like
 * the runtime __get.
 */
final readonly class MagicChainProperty implements PropertyReflection
{
    public function __construct(
        private ClassReflection $declaringClass,
        private Type $type,
    ) {}

    #[Override]
    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    #[Override]
    public function getReadableType(): Type
    {
        return $this->type;
    }

    #[Override]
    public function getWritableType(): Type
    {
        return $this->type;
    }

    #[Override]
    public function canChangeTypeAfterAssignment(): bool
    {
        return false;
    }

    #[Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[Override]
    public function isWritable(): bool
    {
        return false;
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
    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    #[Override]
    public function getDocComment(): ?string
    {
        return null;
    }
}
