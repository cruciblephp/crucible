<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert;

use Closure;
use ReflectionFunction;
use SensitiveParameter;

use function count;

/**
 * The arguments of a call as Crucible's own output writes them, a value
 * bound to a `#[\SensitiveParameter]` replaced by what PHP's own trace
 * writes for it. The dialects that copy an incumbent keep its answer
 * (D-167); what Crucible alone prints redacts.
 */
final class SensitiveArguments
{
    public const string REDACTED = 'Object(SensitiveParameterValue)';

    /**
     * Each argument rendered by $render, or redacted when its parameter is marked sensitive.
     *
     * @param list<mixed>              $arguments
     * @param Closure(mixed): string   $render
     *
     * @return list<string>
     */
    public static function render(callable $callable, array $arguments, Closure $render): array
    {
        $sensitive = self::positions($callable, count($arguments));
        $rendered  = [];

        foreach ($arguments as $position => $argument) {
            $rendered[] = isset($sensitive[$position]) ? self::REDACTED : $render($argument);
        }

        return $rendered;
    }

    /**
     * The positions a sensitive parameter takes, a variadic one taking every position from its own.
     *
     * @return array<int, true>
     */
    public static function positions(callable $callable, int $count): array
    {
        $positions  = [];
        $parameters = (new ReflectionFunction(Closure::fromCallable($callable)))->getParameters();

        foreach ($parameters as $index => $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) === []) {
                continue;
            }

            for ($position = $index; $position < ($parameter->isVariadic() ? $count : $index + 1); $position++) {
                $positions[$position] = true;
            }
        }

        return $positions;
    }
}
