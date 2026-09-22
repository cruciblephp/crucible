<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Bridge\Laravel;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Bridge\Laravel\CrucibleServiceProvider;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function explode;
use function is_dir;
use function json_decode;
use function shell_exec;
use function sprintf;
use function str_contains;
use function trim;

use const PHP_BINARY;

/**
 * The auto-discovered provider against a real Laravel application
 * (D-028). Nothing in this repository references it — Laravel resolves
 * it by name out of composer.json — so without this test the class
 * ships unexercised; the oracle's absence is a named skip instead.
 */
#[CoversClass(CrucibleServiceProvider::class)]
final class CrucibleServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(dirname(__DIR__, 4) . '/livewire-oracle/vendor/laravel/framework')) {
            self::markTestSkipped('No Laravel install available (livewire-oracle missing).');
        }
    }

    public function testTheArtisanTestCommandIsRegisteredOnBoot(): void
    {
        self::assertTrue($this->boot('')['registersTestCommand']);
    }

    /**
     * Laravel gates every ParallelTesting hook on this variable as well
     * as the token; inside a Crucible worker the bridge is what exports
     * it, standing in for Laravel's own paratest wrapper.
     */
    public function testAWorkerTokenArmsLaravelsParallelTestingFlag(): void
    {
        $observed = $this->boot('1');

        self::assertSame('1', $observed['parallelFlag']);
        self::assertSame('1', $observed['parallelEnv']);
    }

    public function testASequentialRunLeavesTheParallelFlagAlone(): void
    {
        $observed = $this->boot('');

        self::assertNull($observed['parallelFlag']);
        self::assertNull($observed['parallelEnv']);
        self::assertNull($observed['token']);
    }

    /**
     * One boot of the oracle application in its own process — the
     * oracle ships its own autoloader, and D-019 is about not having
     * two of those at once.
     *
     * @return array<string, mixed>
     */
    private function boot(string $token): array
    {
        $root = dirname(__DIR__, 4);

        $output = shell_exec(sprintf(
            '%s %s %s %s %s 2>&1',
            PHP_BINARY,
            $root . '/tests/_fixtures/laravel-bridge/service-provider.php',
            $root . '/livewire-oracle',
            $root,
            $token,
        ));

        self::assertIsString($output, 'The provider fixture produced no output.');

        $observed = [];

        foreach (explode("\n", trim($output)) as $line) {
            if (!str_contains($line, ' ')) {
                continue;
            }

            [$case, $json] = explode(' ', $line, 2);

            $observed[$case] = json_decode($json, true);
        }

        self::assertArrayHasKey('registersTestCommand', $observed, 'The provider fixture failed: ' . $output);

        return $observed;
    }
}
