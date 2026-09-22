<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Watch;

use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Browser\Server\HttpResponse;
use LucianoPereira\Crucible\Browser\Server\RequestHandler;
use Override;

use function hash_equals;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;

/**
 * What the retrigger endpoint accepts (D-083): a change set pushed by
 * whoever already knows what changed — a bundler plugin, a build step,
 * a git hook — instead of Crucible reconstructing one from artifacts it
 * would have to understand.
 *
 * Pure: it parses, authorizes, and accumulates. The socket, the hot
 * file, and the run belong to {@see RetriggerListener} and the loop.
 *
 * The payload is **paths only**. Never a filter, a group, or anything
 * command-shaped: the worst a caller can achieve is making the
 * developer run their own suite against their own files.
 */
final class RetriggerEndpoint implements RequestHandler
{
    /** @var list<non-empty-string> */
    private array $pending = [];

    public function __construct(private readonly string $token) {}

    #[Override]
    public function handle(HttpRequest $request): HttpResponse
    {
        if ($request->method !== 'POST') {
            return $this->refuse(405, 'Use POST.');
        }

        // hash_equals over a plain comparison: the token is a secret,
        // and a loopback socket is still reachable by every process on
        // the machine.
        $offered = $request->query()['token'] ?? null;

        if (!is_string($offered) || !hash_equals($this->token, $offered)) {
            return $this->refuse(403, 'Bad or missing token.');
        }

        $decoded = json_decode($request->body, true);

        if (!is_array($decoded) || !is_array($decoded['changed'] ?? null)) {
            return $this->refuse(400, 'Expected {"changed": [...]}.');
        }

        $accepted = 0;

        foreach ($decoded['changed'] as $path) {
            // A path is data, never a command; anything else is dropped
            // rather than coerced into one.
            if (!is_string($path) || ltrim($path) === '') {
                continue;
            }

            $this->pending[] = $path;
            ++$accepted;
        }

        return new HttpResponse(
            '{"accepted":' . $accepted . '}',
            202,
            'application/json',
        );
    }

    /**
     * Everything pushed since the last call, draining the buffer — the
     * loop asks once per tick and owns what it takes.
     *
     * @return list<non-empty-string>
     */
    public function take(): array
    {
        $pending       = $this->pending;
        $this->pending = [];

        return $pending;
    }

    private function refuse(int $status, string $why): HttpResponse
    {
        return new HttpResponse($why, $status, 'text/plain; charset=utf-8');
    }
}
