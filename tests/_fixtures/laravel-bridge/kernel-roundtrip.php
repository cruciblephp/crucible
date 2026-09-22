<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * Run in a subprocess by BrowserKernelHandlerTest against the Laravel
 * oracle (ORACLES.md). A separate process on purpose: the oracle ships
 * its own autoloader and its own mockery, and D-019's coexistence rule
 * is about not having two of those in one process.
 */

use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use LucianoPereira\Crucible\Bridge\Laravel\BrowserKernelHandler;
use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use Symfony\Component\HttpFoundation\Response;

$oracle    = $argv[1] ?? '';
$crucible  = $argv[2] ?? '';

require $oracle . '/vendor/autoload.php';
require $crucible . '/vendor/autoload.php';

$emit = static function (string $case, mixed $value): void {
    echo $case, ' ', json_encode($value, JSON_UNESCAPED_SLASHES), "\n";
};

// The unbooted path: Laravel is present, no kernel is bound.
Container::setInstance(new Container());

try {
    (new BrowserKernelHandler())->handle(new HttpRequest('GET', '/', []));
    $emit('unbooted', 'no exception');
} catch (BrowserProtocolException $e) {
    $emit('unbooted', $e->getMessage());
}

// The booted path: a kernel that echoes back what it was handed.
$kernel = new class implements HttpKernel {
    public mixed $seen = null;

    public function bootstrap(): void {}

    public function handle($request): Response
    {
        $this->seen = $request;

        return new Response('<h1>' . $request->getMethod() . ' ' . $request->getRequestUri() . '</h1>', 201, [
            'Content-Type'    => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Length'  => '999',
        ]);
    }

    public function terminate($request, $response): void
    {
        $GLOBALS['terminated'] = true;
    }

    public function getApplication(): mixed
    {
        return null;
    }
};

$container = new Container();
$container->instance(HttpKernel::class, $kernel);
Container::setInstance($container);

$response = (new BrowserKernelHandler())->handle(
    new HttpRequest('POST', '/orders?page=2', ['host' => 'example.test', 'x-requested-with' => 'XMLHttpRequest'], 'a=1'),
);

$emit('body', $response->body);
$emit('status', $response->status);
$emit('contentType', $response->contentType);
$emit('headers', $response->headers);
$emit('terminated', $GLOBALS['terminated'] ?? false);
$emit('kernelSawHost', $kernel->seen?->getHost());
$emit('kernelSawAjax', $kernel->seen?->headers->get('X-Requested-With'));
$emit('kernelSawBody', $kernel->seen?->getContent());
