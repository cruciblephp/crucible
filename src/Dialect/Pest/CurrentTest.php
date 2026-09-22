<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use ReflectionMethod;

use function get_debug_type;
use function is_object;
use function sprintf;

/**
 * The currently-running test instance, scoped by PestBuilder around
 * each test's body — mirrors real Pest's own
 * TestSuite::getInstance()->test, which its own plugin packages
 * reach through test()'s zero-argument form. Crucible's own test()
 * (functions.php) keeps its existing non-nullable signature rather
 * than growing that dual meaning, so the Pest\Laravel bridge
 * (src/Bridge/PestLaravel) reads this holder directly instead.
 */
final class CurrentTest
{
    private static ?object $instance = null;

    public static function set(?object $instance): void
    {
        self::$instance = $instance;
    }

    public static function get(): object
    {
        if (self::$instance === null) {
            throw new ConfigurationException(
                'No test is currently running: Pest\Laravel\* and Pest\Livewire\* functions can only be called from inside a test body.',
            );
        }

        return self::$instance;
    }

    /**
     * Real Laravel testing-trait methods (assertDatabaseHas, mock,
     * handleExceptions, …) are declared protected — meant to be
     * called via $this-> from inside a TestCase subclass, not from a
     * global function (verified against the real, installed
     * Illuminate\Foundation\Testing\Concerns\* traits). A plain
     * ->method() call from Pest\Laravel\* would fatal with "Call to
     * protected method ... from global scope"; real Pest's own
     * plugin proxies reach them through reflection instead
     * (Pest\Support\HigherOrderMessage::call()), which is what this
     * mirrors.
     *
     * @param list<mixed> $arguments
     */
    public static function call(string $method, array $arguments): mixed
    {
        $instance = self::get();

        return (new ReflectionMethod($instance, $method))->invoke($instance, ...$arguments);
    }

    /**
     * The same call where the caller's signature promises an object of
     * a given type. Reflection returns mixed, so a proxy declaring a
     * concrete return type is making a claim nothing checks — this
     * checks it, once, instead of each proxy asserting its way past
     * the analyzer. A Laravel that returned something else would
     * otherwise surface as a type error inside the caller's code.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     * @param list<mixed>     $arguments
     *
     * @return T
     */
    public static function callReturning(string $type, string $method, array $arguments): object
    {
        $value = self::call($method, $arguments);

        if (!$value instanceof $type) {
            throw new ConfigurationException(sprintf(
                'Expected %s() to return %s, got %s.',
                $method,
                $type,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * The same, for a proxy that promises only "an object" — the
     * container's swap/instance surface, which is typed by the caller's
     * own argument rather than by a class this bridge could name.
     *
     * @param list<mixed> $arguments
     */
    public static function callReturningObject(string $method, array $arguments): object
    {
        $value = self::call($method, $arguments);

        if (!is_object($value)) {
            throw new ConfigurationException(sprintf(
                'Expected %s() to return an object, got %s.',
                $method,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
