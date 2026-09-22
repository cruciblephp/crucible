<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

use Attribute;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;

/**
 * An inline I/O-table test on the function or method it annotates
 * (growth G2c, DESIGN.md D-014/D-035): call it with `args`, then
 * either the return value equals `returns` (toEqual semantics), or
 * an instance of `throws` comes out, or — neither given — the call
 * simply completes without throwing (a smoke check).
 *
 * PHP attributes accept only constant expressions; that constraint
 * defines this tier's shape. Claims that need computation belong in
 * a `@crucible` doctest or a test file.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Check implements CrucibleAttribute
{
    /**
     * `returns: null` is a real claim, so "not given" needs a value no
     * user would pass: a NUL-framed marker string.
     */
    public const string NOTHING = "\x00crucible\x00nothing\x00";

    /**
     * @param array<array-key, mixed> $args    arguments for the call; string keys bind by parameter name
     * @param mixed                   $returns expected return value, compared with the equality spec
     * @param ?class-string           $throws  expected exception type instead of a return value
     * @param ?non-empty-string       $name    names the case in test ids; defaults to `check N`
     */
    public function __construct(
        public array $args = [],
        public mixed $returns = self::NOTHING,
        public ?string $throws = null,
        public ?string $name = null,
    ) {}

    public function expectsReturn(): bool
    {
        return $this->returns !== self::NOTHING;
    }
}
