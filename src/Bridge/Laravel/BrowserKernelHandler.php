<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Bridge\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\Server\CgiVariables;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Browser\Server\HttpResponse;
use LucianoPereira\Crucible\Browser\Server\RequestHandler;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

use function class_exists;
use function is_string;
use function strtoupper;

/**
 * The Laravel browser bridge (D-065): relative visit() URLs served
 * by the SAME booted application the test is manipulating — the
 * spec's shared-state contract (Event::fake(), RefreshDatabase,
 * factories all demonstrably reach the served requests) solved by
 * D-057's architecture: the in-process server pumps inside the
 * driver's select loop, so page loads are handled while PHP waits
 * for the navigation reply, one process, one world.
 *
 * Wire it with one crucible.php line:
 *   ->browser(requestHandler: BrowserKernelHandler::class)
 *
 * The test's own TestCase boots the application (CreatesApplication /
 * the framework's bootstrap); this handler only asks the container
 * for the HTTP kernel per request.
 *
 * @phpcpd-keep Named as a class-string in the user's crucible.php, so a code
 * reference cannot exist by construction. `keep` rather than `planned`: this is
 * how it is meant to be wired, not a step on the way to something else.
 */
final readonly class BrowserKernelHandler implements RequestHandler
{
    public function handle(HttpRequest $request): HttpResponse
    {
        if (!class_exists(Container::class)) {
            throw new BrowserProtocolException('The Laravel browser handler needs laravel/framework installed.');
        }

        $app = Container::getInstance();

        if (!$app->bound(HttpKernel::class)) {
            throw new BrowserProtocolException(
                'No booted Laravel application in this process — browser tests need the application bootstrapped by the test (extend the framework TestCase).',
            );
        }

        $kernel = $app->make(HttpKernel::class);

        $server = CgiVariables::forRequest($request);

        $symfony    = SymfonyRequest::create($request->uri, strtoupper($request->method), [], [], [], $server, $request->body);
        $illuminate = Request::createFromBase($symfony);

        $response = $kernel->handle($illuminate);
        $kernel->terminate($illuminate, $response);

        $headers = CgiVariables::fromResponseHeaders($response->headers->allPreserveCase());

        $contentType = $response->headers->get('Content-Type');

        return new HttpResponse(
            (string) $response->getContent(),
            $response->getStatusCode(),
            is_string($contentType) && $contentType !== '' ? $contentType : 'text/html; charset=utf-8',
            $headers,
        );
    }
}
