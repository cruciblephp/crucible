<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Bridge\PestLaravel;

use LucianoPereira\Crucible\Dialect\Pest\CurrentTest;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;
use stdClass;

use function dirname;
use function function_exists;

/**
 * A duck-typed stand-in for the real Testbench TestCase methods these
 * proxies forward to (Illuminate\Foundation\Testing\Concerns\*).
 * Visibility mirrors the real, installed traits exactly (verified by
 * reading them): assertDatabaseHas, handleExceptions,
 * withoutExceptionHandling, instance and swap are all protected
 * there — CurrentTest::call() must reach them anyway, via reflection,
 * the same way real Pest's own HigherOrderMessage::call() does; a
 * plain ->method() call fatals with "Call to protected method ...
 * from global scope" (the actual bug this test exists to catch —
 * found against the real spatie/laravel-data benchmark, where every
 * fixture method here happened to be public and so didn't catch it).
 * postJson/post are genuinely public upstream, kept that way here.
 *
 * Only exercises the functions whose signatures use native PHP types
 * — mock()/partialMock()/spy() (Mockery\MockInterface) and actingAs()
 * (Illuminate\Contracts\Auth\Authenticatable) reference classes that
 * are not, and should never become, dev-dependencies of the engine
 * itself (same reasoning as src/Bridge/Laravel, phpstan.neon), so
 * those are verified against the real spatie/laravel-data benchmark
 * instead, not this suite.
 */
final class FakeTestbenchLikeFixture
{
    /** @var list<array{non-empty-string, list<mixed>}> */
    public array $calls = [];

    /** @param array<array-key, mixed> $data */
    protected function assertDatabaseHas(mixed $table, array $data = [], ?string $connection = null): self
    {
        $this->calls[] = ['assertDatabaseHas', [$table, $data, $connection]];

        return $this;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public function post(string $uri, array $data = [], array $headers = []): array
    {
        $this->calls[] = ['post', [$uri, $data, $headers]];

        return ['uri' => $uri, 'data' => $data, 'headers' => $headers];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public function postJson(string $uri, array $data = [], array $headers = []): array
    {
        $this->calls[] = ['postJson', [$uri, $data, $headers]];

        return ['uri' => $uri, 'data' => $data, 'headers' => $headers];
    }

    /** @param array<array-key, mixed> $exceptions */
    protected function handleExceptions(array $exceptions): self
    {
        $this->calls[] = ['handleExceptions', [$exceptions]];

        return $this;
    }

    /** @param array<array-key, mixed> $except */
    protected function withoutExceptionHandling(array $except = []): self
    {
        $this->calls[] = ['withoutExceptionHandling', [$except]];

        return $this;
    }

    protected function instance(string $abstract, object $instance): object
    {
        $this->calls[] = ['instance', [$abstract, $instance]];

        return $instance;
    }

    protected function swap(string $abstract, object $instance): object
    {
        $this->calls[] = ['swap', [$abstract, $instance]];

        return $instance;
    }
}

final class FunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('Pest\Laravel\mock')) {
            require dirname(__DIR__, 4) . '/src/Bridge/PestLaravel/functions.php';
        }
    }

    protected function tearDown(): void
    {
        CurrentTest::set(null);
    }

    public function testEachProxyForwardsToTheCurrentTestInstance(): void
    {
        $fixture = new FakeTestbenchLikeFixture();

        CurrentTest::set($fixture);

        \Pest\Laravel\assertDatabaseHas('users', ['id' => 1], 'sqlite');
        \Pest\Laravel\post('/api/users', ['name' => 'Ada'], ['X-Test' => '1']);
        \Pest\Laravel\postJson('/api/users', ['name' => 'Ada'], ['X-Test' => '1']);
        \Pest\Laravel\handleExceptions([RuntimeException::class]);
        \Pest\Laravel\withoutExceptionHandling();

        $swapped = new stdClass();
        \Pest\Laravel\instance('some.abstract', $swapped);
        \Pest\Laravel\swap('other.abstract', $swapped);

        self::assertSame([
            ['assertDatabaseHas', ['users', ['id' => 1], 'sqlite']],
            ['post', ['/api/users', ['name'     => 'Ada'], ['X-Test' => '1']]],
            ['postJson', ['/api/users', ['name' => 'Ada'], ['X-Test' => '1']]],
            ['handleExceptions', [[RuntimeException::class]]],
            ['withoutExceptionHandling', [[]]],
            ['instance', ['some.abstract', $swapped]],
            ['swap', ['other.abstract', $swapped]],
        ], $fixture->calls);
    }

    public function testCallingAProxyOutsideARunningTestThrows(): void
    {
        $this->expectException(ConfigurationException::class);

        \Pest\Laravel\assertDatabaseHas('users');
    }
}
