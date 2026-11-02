<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;
use Traversable;

use function array_key_exists;
use function array_key_last;
use function array_pop;
use function count;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_contains;

/**
 * What the probes saw: for each function a test run called, the types its
 * parameters received and it returned, and the first value that a phpdoc
 * did not admit. Only a call that returned counts: one that threw is a
 * test probing the edge of the contract, not the contract.
 *
 * Never the reason code under test fails: anything going wrong in here is
 * swallowed, and the call goes on as if it had not been watched.
 *
 * @internal driven by the probes {@see ProbeRewriter} writes
 */
final class Observer
{
    /** Distinct types kept per slot: past it a slot keeps counting the ones it has. */
    private const int TYPES = 24;

    /** Values kept per slot to check once the suite is over, when they needed a class not yet loaded. */
    private const int DEFERRED = 8;

    /**
     * Values checked per slot and observed type. A check walks the whole
     * value, so checking every call is quadratic in a loop over large
     * arrays; a seventeenth value of one observed type rarely refutes
     * what sixteen did not.
     */
    private const int CHECKS = 16;

    /** @var list<array{key: string, arguments: array<int, mixed>, value: mixed, returned: bool, threw: bool}> */
    private static array $stack = [];

    /** @var array<string, ObservedFunction> */
    private static array $functions = [];

    /** @var ?Closure(string): never */
    private static ?Closure $barrier = null;

    /** @param array<int, mixed> $arguments */
    public static function enter(string $class, string $function, array $arguments): void
    {
        self::$stack[] = [
            'key'       => $class === '' ? $function : $class . '::' . $function,
            'arguments' => $arguments,
            'value'     => null,
            'returned'  => false,
            'threw'     => false,
        ];
    }

    public static function returned(mixed $value): mixed
    {
        $top = array_key_last(self::$stack);

        if ($top !== null) {
            self::$stack[$top]['value']    = $value;
            self::$stack[$top]['returned'] = true;
        }

        return $value;
    }

    public static function threw(): void
    {
        $top = array_key_last(self::$stack);

        if ($top !== null) {
            self::$stack[$top]['threw'] = true;
        }
    }

    public static function leave(): void
    {
        $frame = array_pop(self::$stack);

        if ($frame === null || $frame['threw']) {
            return;
        }

        try {
            self::record($frame['key'], $frame['arguments'], $frame['returned'], $frame['value']);
        } catch (Throwable) {
            // Watching must not change what is watched.
        }
    }

    /** @return array<string, ObservedFunction> */
    public static function functions(): array
    {
        return self::$functions;
    }

    /**
     * Check the values put off, now that the suite is over and loading a
     * class changes nothing; a value that fits is forgotten.
     */
    public static function settle(): void
    {
        foreach (self::$functions as $function) {
            foreach ($function->deferred as $slot => $values) {
                $doc = $function->doc($slot);

                if ($doc === null) {
                    continue;
                }

                foreach ($values as $value) {
                    try {
                        $mismatch = $doc[1]->mismatch($value);
                    } catch (Throwable) {
                        continue;
                    }

                    if ($mismatch !== null) {
                        $function->refused($slot, $doc[0], $mismatch);
                    }
                }
            }

            $function->deferred = [];
        }
    }

    private static function refuseAutoload(string $class): never
    {
        throw new DeferredCheck($class);
    }

    public static function reset(): void
    {
        self::$stack     = [];
        self::$functions = [];
    }

    /** @param array<int, mixed> $arguments */
    private static function record(string $key, array $arguments, bool $returned, mixed $value): void
    {
        $function = self::$functions[$key] ??= self::describe($key);
        $function->calls++;

        foreach ($function->parameters as $index => $name) {
            if (array_key_exists($index, $arguments)) {
                self::slot($function, '$' . $name, $arguments[$index]);
            }
        }

        if ($returned) {
            self::slot($function, 'return', $value);
        }
    }

    private static function describe(string $key): ObservedFunction
    {
        $reflection = str_contains($key, '::') ? new ReflectionMethod($key) : new ReflectionFunction($key);
        $names      = [];

        foreach ($reflection->getParameters() as $parameter) {
            if (!$parameter->isVariadic()) {
                $names[] = $parameter->getName();
            }
        }

        return new ObservedFunction((string) $reflection->getFileName(), (int) $reflection->getStartLine(), $names, DocTypes::of($reflection));
    }

    private static function slot(ObservedFunction $function, string $slot, mixed $value): void
    {
        $type = ValueTypes::of($value);

        if (isset($function->types[$slot][$type]) || count($function->types[$slot] ?? []) < self::TYPES) {
            $function->types[$slot][$type] = ($function->types[$slot][$type] ?? 0) + 1;
        }

        $doc = $function->doc($slot);

        // Checking an iterator would consume it, and the caller still needs it.
        if ($doc === null || $value instanceof Traversable || ($function->types[$slot][$type] ?? 0) > self::CHECKS) {
            return;
        }

        // A check that would autoload is put off: loading a class here
        // changes when it loads, and the runner may require its file next.
        self::$barrier ??= self::refuseAutoload(...);

        try {
            spl_autoload_register(self::$barrier, true, true);
            $mismatch = $doc[1]->mismatch($value);
        } catch (DeferredCheck) {
            if (count($function->deferred[$slot] ?? []) < self::DEFERRED) {
                $function->deferred[$slot][] = $value;
            }

            return;
        } finally {
            spl_autoload_unregister(self::$barrier);
        }

        // The first value it refused is the example; the count is every one.
        if ($mismatch !== null) {
            $function->refused($slot, $doc[0], $mismatch);
        }
    }
}
