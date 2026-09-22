<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert;

use function gettype;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_iterable;
use function is_numeric;
use function is_object;
use function is_resource;
use function is_scalar;
use function is_string;

/**
 * The native types of the assertIs*() family, as an enum: the spec's
 * closed string set made unrepresentable-when-invalid.
 */
enum ValueType: string
{
    case Array          = 'array';
    case Bool           = 'bool';
    case Callable       = 'callable';
    case ClosedResource = 'resource (closed)';
    case Float          = 'float';
    case Int            = 'int';
    case Iterable       = 'iterable';
    case Null           = 'null';
    case Numeric        = 'numeric';
    case Object         = 'object';
    case Resource       = 'resource';
    case Scalar         = 'scalar';
    case String         = 'string';

    public function check(mixed $value): bool
    {
        return match ($this) {
            self::Array          => is_array($value),
            self::Bool           => is_bool($value),
            self::Callable       => is_callable($value),
            self::ClosedResource => gettype($value) === 'resource (closed)',
            self::Float          => is_float($value),
            self::Int            => is_int($value),
            self::Iterable       => is_iterable($value),
            self::Null           => $value === null,
            self::Numeric        => is_numeric($value),
            self::Object         => is_object($value),
            self::Resource       => is_resource($value) || gettype($value) === 'resource (closed)',
            self::Scalar         => is_scalar($value),
            self::String         => is_string($value),
        };
    }
}
