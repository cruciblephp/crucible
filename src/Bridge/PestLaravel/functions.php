<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * A thin proxy layer for the real pestphp/pest-plugin-laravel's
 * function surface (growth G2 follow-up). That package hard-requires
 * pestphp/pest itself, which D-019's own PestBuilder::ensureUsable()
 * guard already refuses to coexist with — so it can never actually be
 * installed alongside Crucible's pest dialect, and every one of its
 * exported functions is, in the real package, a one-line proxy:
 * `return test()->methodName(...func_get_args());`, where real
 * Pest's zero-arg test() returns the currently-running instance. The
 * underlying methods are native to Illuminate\Foundation\Testing\TestCase
 * (via its own bundled Concerns\* traits), which Orchestra Testbench's
 * TestCase extends — PestBuilder already instantiates and
 * lifecycle-manages real Testbench TestCase instances, so no
 * trait-mixing or other new machinery is needed here, only a way to
 * reach "the instance currently running a test" (CurrentTest, since
 * Crucible's own test() keeps its existing non-nullable signature).
 * Most of those Concerns\* methods are declared protected — meant to
 * be called via $this-> from inside a TestCase subclass — so
 * CurrentTest::call() dispatches through reflection rather than a
 * plain ->method() call, the same way real Pest's own
 * HigherOrderMessage::call() does; a direct call fatals with "Call
 * to protected method ... from global scope" (confirmed against the
 * real, installed Illuminate\Foundation\Testing\Concerns\* traits —
 * assertDatabaseHas, mock, swap, handleExceptions and
 * withoutExceptionHandling are all protected there; actingAs and
 * postJson happen to be public, but are routed the same way for
 * uniformity, matching real Pest's own unconditional mechanism).
 *
 * Loaded only when a *.pest.php file is about to be built (see
 * PestBuilder::ensureUsable()), each function guarded by
 * function_exists, matching src/Dialect/Pest/functions.php's own
 * convention.
 *
 * Scoped to the functions real-world Laravel package test suites
 * actually exercise (verified against spatie/laravel-data), not the
 * plugin's full surface — growing this list is adding one more
 * proxy line, not a design decision.
 */

namespace Pest\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use LucianoPereira\Crucible\Dialect\Pest\CurrentTest;
use Mockery\MockInterface;
use Throwable;

use function func_get_args;
use function function_exists;

if (!function_exists('Pest\Laravel\mock')) {
    function mock(string $abstract, ?Closure $mock = null): MockInterface
    {
        return CurrentTest::callReturning(MockInterface::class, 'mock', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\partialMock')) {
    function partialMock(string $abstract, ?Closure $mock = null): MockInterface
    {
        return CurrentTest::callReturning(MockInterface::class, 'partialMock', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\spy')) {
    function spy(string $abstract, ?Closure $mock = null): MockInterface
    {
        return CurrentTest::callReturning(MockInterface::class, 'spy', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\instance')) {
    function instance(string $abstract, object $instance): object
    {
        return CurrentTest::callReturningObject('instance', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\swap')) {
    function swap(string $abstract, object $instance): object
    {
        return CurrentTest::callReturningObject('swap', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\actingAs')) {
    function actingAs(Authenticatable $user, ?string $driver = null): mixed
    {
        return CurrentTest::call('actingAs', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\assertDatabaseHas')) {
    /**
     * @param array<string, mixed> $data
     */
    function assertDatabaseHas(mixed $table, array $data = [], ?string $connection = null): mixed
    {
        return CurrentTest::call('assertDatabaseHas', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\post')) {
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    function post(string $uri, array $data = [], array $headers = []): mixed
    {
        return CurrentTest::call('post', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\postJson')) {
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    function postJson(string $uri, array $data = [], array $headers = []): mixed
    {
        return CurrentTest::call('postJson', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\handleExceptions')) {
    /**
     * @param list<class-string<Throwable>> $exceptions
     */
    function handleExceptions(array $exceptions): mixed
    {
        return CurrentTest::call('handleExceptions', func_get_args());
    }
}

if (!function_exists('Pest\Laravel\withoutExceptionHandling')) {
    /**
     * @param list<class-string<Throwable>> $except
     */
    function withoutExceptionHandling(array $except = []): mixed
    {
        return CurrentTest::call('withoutExceptionHandling', func_get_args());
    }
}
