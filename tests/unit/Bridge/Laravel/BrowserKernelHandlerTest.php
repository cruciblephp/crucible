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
use LucianoPereira\Crucible\Bridge\Laravel\BrowserKernelHandler;
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
 * The Laravel browser bridge against a real Laravel (D-065), which the
 * engine deliberately does not depend on — so the oracle's absence is a
 * named skip, never a missing test. Driven in a subprocess: the oracle
 * ships its own autoloader and its own mockery, and D-019 is about not
 * having two of either in one process.
 */
#[CoversClass(BrowserKernelHandler::class)]
final class BrowserKernelHandlerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $observed = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);

        if (!is_dir($root . '/livewire-oracle/vendor/laravel/framework')) {
            self::markTestSkipped('No Laravel install available (livewire-oracle missing).');
        }

        $output = shell_exec(sprintf(
            '%s %s %s %s 2>&1',
            PHP_BINARY,
            $root . '/tests/_fixtures/laravel-bridge/kernel-roundtrip.php',
            $root . '/livewire-oracle',
            $root,
        ));

        self::assertIsString($output, 'The bridge fixture produced no output.');

        foreach (explode("\n", trim($output)) as $line) {
            if (!str_contains($line, ' ')) {
                continue;
            }

            [$case, $json] = explode(' ', $line, 2);

            $this->observed[$case] = json_decode($json, true);
        }

        self::assertArrayHasKey('status', $this->observed, 'The bridge fixture failed: ' . $output);
    }

    public function testAnUnbootedApplicationIsNamedRatherThanFatal(): void
    {
        self::assertSame(
            'No booted Laravel application in this process — browser tests need the application bootstrapped by the test (extend the framework TestCase).',
            $this->observed['unbooted'],
        );
    }

    public function testTheKernelsResponseIsCarriedBackWhole(): void
    {
        self::assertSame('<h1>POST /orders?page=2</h1>', $this->observed['body']);
        self::assertSame(201, $this->observed['status']);
        self::assertSame('text/html; charset=UTF-8', $this->observed['contentType']);
    }

    /**
     * The renderer writes content-type and content-length from the
     * response itself; a second copy out of the bag would contradict it.
     */
    public function testTheResponseHeadersArriveWithoutTheOnesTheRendererOwns(): void
    {
        $headers = $this->observed['headers'];

        self::assertIsArray($headers);
        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
        self::assertArrayNotHasKey('Content-Type', $headers);
        self::assertArrayNotHasKey('Content-Length', $headers);
    }

    public function testTheKernelIsTerminatedAfterTheResponse(): void
    {
        self::assertTrue($this->observed['terminated']);
    }

    /**
     * The CGI translation reaching a real kernel: the host header, an
     * arbitrary header, and the body all survive the round trip.
     */
    public function testTheRequestReachesTheKernelIntact(): void
    {
        self::assertSame('example.test', $this->observed['kernelSawHost']);
        self::assertSame('XMLHttpRequest', $this->observed['kernelSawAjax']);
        self::assertSame('a=1', $this->observed['kernelSawBody']);
    }
}
