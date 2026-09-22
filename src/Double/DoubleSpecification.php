<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use function implode;
use function sort;

/**
 * Everything that distinguishes one double from another (the spec's
 * MockBuilder knobs, D-046): the doubled type(s), which methods are
 * doubled, how the instance is constructed, and how unconfigured
 * calls behave. The generation identity — what makes two doubles
 * share a generated class — is a strict subset: constructor and
 * auto-return settings are instantiation state, not code.
 */
final readonly class DoubleSpecification
{
    /**
     * @param non-empty-list<class-string>  $types                   one class or interface, or
     *                                                               several interfaces (intersection)
     * @param list<class-string>            $traits                  mixed into the double itself, so their
     *                                                               own methods stay REAL — ✓ measured, this
     *                                                               is what the real mockery does for
     *                                                               Mockery::mock(SomeTrait::class)
     * @param ?list<non-empty-string>       $onlyMethods             null = every doubleable method,
     *                                                               [] = none (everything stays real)
     * @param ?list<mixed>                  $constructorArguments
     * @param ?non-empty-string             $className               explicit generated-class name
     * @param bool                          $mockerySurface          the generated class carries the
     *                                                               Mockery verbs and a catch-all
     *                                                               __call instead of the PHPUnit
     *                                                               configuration API — the third
     *                                                               spec's grammar over the same
     *                                                               brain (D-060)
     * @param bool                          $mergeConfigurations     dispatch falls back through
     *                                                               older matching configurators
     *                                                               for a return value when the
     *                                                               newest one carries none, instead
     *                                                               of fabricating a default —
     *                                                               additive, off by default;
     *                                                               the PHPUnit-spec construction
     *                                                               path opts in explicitly
     * @param bool                          $prefixArgumentMatching  with() constraints match as a
     *                                                               positional prefix — extra real
     *                                                               arguments beyond the given
     *                                                               constraints are unconstrained,
     *                                                               not a mismatch (PHPUnit's own
     *                                                               Parameters rule, oracle-verified) —
     *                                                               off by default: the Mockery
     *                                                               grammar's with()/any() is
     *                                                               oracle-pinned to exact arity
     *                                                               (spec/mockery-api.md); the
     *                                                               PHPUnit-spec construction path
     *                                                               opts in explicitly
     */
    public function __construct(
        public array $types,
        public ?array $onlyMethods = null,
        public bool $callOriginalConstructor = false,
        public ?array $constructorArguments = null,
        public bool $callOriginalClone = true,
        public bool $autoReturnValues = true,
        public ?string $className = null,
        public bool $mockerySurface = false,
        public bool $mergeConfigurations = false,
        public bool $prefixArgumentMatching = false,
        public array $traits = [],
    ) {}

    /**
     * @return non-empty-string cache key for the generated class
     */
    public function classIdentity(): string
    {
        $methods = $this->onlyMethods;

        if ($methods !== null) {
            sort($methods);
        }

        return implode('&', $this->types)
            . '@' . implode(',', $this->traits)
            . '#' . ($methods === null ? '*' : implode(',', $methods))
            . '#' . ($this->callOriginalClone ? 'clone' : 'noclone')
            . '#' . ($this->className ?? '')
            . '#' . ($this->mockerySurface ? 'mockery' : 'phpunit');
    }
}
