<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Double;

use BackedEnum;
use ReflectionMethod;
use UnitEnum;

use function bin2hex;
use function count;
use function get_debug_type;
use function get_mangled_object_vars;
use function get_resource_id;
use function get_resource_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function ltrim;
use function mb_strlen;
use function mb_substr;
use function ord;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function str_replace;
use function var_export;

/**
 * A call to a double as PHPUnit 13.4.3 writes it in a mock failure:
 * `App\Repo::find(7, [...]): object`, measured case by case. Omitted
 * optional arguments read as their defaults, as PHPUnit compares them.
 */
final class PhpUnitInvocation
{
    /**
     * The arguments with every omitted optional parameter's default.
     *
     * @param list<mixed> $arguments
     *
     * @return list<mixed>
     */
    public static function withDefaults(?ReflectionMethod $method, array $arguments): array
    {
        if (!$method instanceof ReflectionMethod) {
            return $arguments;
        }

        foreach ($method->getParameters() as $index => $parameter) {
            if ($index < count($arguments)) {
                continue;
            }

            if ($parameter->isVariadic() || !$parameter->isDefaultValueAvailable()) {
                break;
            }

            $arguments[] = $parameter->getDefaultValue();
        }

        return $arguments;
    }

    /**
     * @param class-string $class
     * @param list<mixed>  $arguments already given their defaults
     */
    public static function describe(string $class, ?ReflectionMethod $method, string $name, array $arguments): string
    {
        $exported = [];

        foreach ($arguments as $argument) {
            $exported[] = self::shortened($argument);
        }

        $type = $method?->getReturnType() ?? $method?->getTentativeReturnType();

        return sprintf('%s::%s(%s)%s', $class, $name, implode(', ', $exported), $type === null ? '' : ': ' . ltrim((string) $type, '?'));
    }

    /** How PHPUnit names the parameter at $index: `$name`, or the position past the declared ones. */
    public static function parameter(?ReflectionMethod $method, int $index): string
    {
        $parameters = $method?->getParameters() ?? [];

        return isset($parameters[$index]) ? '$' . $parameters[$index]->getName() : (string) $index;
    }

    public static function times(int $count): string
    {
        return $count === 1 ? '1 time' : $count . ' times';
    }

    public static function invoked(int $count): string
    {
        return match ($count) {
            0       => 'never invoked',
            1       => 'invoked once',
            default => sprintf('invoked %d times', $count),
        };
    }

    public static function shortened(mixed $value): string
    {
        return match (true) {
            $value === null            => 'null',
            is_bool($value)            => $value ? 'true' : 'false',
            is_int($value)             => (string) $value,
            is_float($value)           => var_export($value, true),
            is_string($value)          => self::string($value),
            is_array($value)           => $value === [] ? '[]' : '[...]',
            $value instanceof UnitEnum => sprintf('%s Enum (%s%s)', $value::class, $value->name, $value instanceof BackedEnum ? ', ' . self::shortened($value->value) : ''),
            is_object($value)          => sprintf('%s Object (%s)', $value::class, get_mangled_object_vars($value) === [] ? '' : '...'),
            is_resource($value)        => sprintf('resource(%d) of type (%s)', get_resource_id($value), get_resource_type($value)),
            default                    => get_debug_type($value),
        };
    }

    /**
     * Quoted as is, or as hex when it holds a NUL; cut to its first 30 and
     * last 7 characters past 40; then line breaks and other control
     * characters escaped, so the cut counts the raw characters.
     */
    private static function string(string $value): string
    {
        $binary   = str_contains($value, "\0");
        $exported = $binary ? 'Binary String: 0x' . bin2hex($value) : "'" . $value . "'";

        if (mb_strlen($exported) > 40) {
            $exported = mb_substr($exported, 0, 30) . '...' . mb_substr($exported, -7);
        }

        if ($binary) {
            return $exported;
        }

        return (string) preg_replace_callback(
            '/[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/',
            static fn(array $match): string => sprintf('\\u{%04X}', ord($match[0])),
            str_replace(["\n", "\r"], ['\n', '\r'], $exported),
        );
    }
}
