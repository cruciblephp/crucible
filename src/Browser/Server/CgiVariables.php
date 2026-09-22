<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Server;

use function is_iterable;
use function is_string;
use function str_replace;
use function strtoupper;
use function ucwords;

/**
 * The two translations between the in-process server's own request and
 * response shapes and the CGI conventions a framework kernel expects.
 *
 * Neither needs a framework, so neither lives in the bridge that does
 * (D-065): a header name mangled the wrong way is a bug reachable with
 * an array and no booted application, and a test that needs one would
 * never have been written.
 */
final readonly class CgiVariables
{
    /**
     * The `$_SERVER` entries a kernel reads, from one parsed request.
     *
     * @return array<string, string>
     */
    public static function forRequest(HttpRequest $request): array
    {
        $server = [
            'REQUEST_METHOD' => strtoupper($request->method),
            'REQUEST_URI'    => $request->uri,
            'SERVER_NAME'    => '127.0.0.1',
            'HTTP_HOST'      => $request->headers['host'] ?? '127.0.0.1',
        ];

        foreach ($request->headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        // CGI carries the entity headers unprefixed; a kernel that reads
        // CONTENT_TYPE would not find HTTP_CONTENT_TYPE.
        if (isset($request->headers['content-type'])) {
            $server['CONTENT_TYPE'] = $request->headers['content-type'];
        }

        return $server;
    }

    /**
     * A response's headers, in the casing a client expects, minus the
     * two the server's own renderer owns.
     *
     * @param iterable<array-key, mixed> $headers a header bag's preserved-case entries
     *
     * @return array<string, string>
     */
    public static function fromResponseHeaders(iterable $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $values) {
            $name = (string) $key;

            if ($name === '' || strtoupper($name) === 'CONTENT-TYPE' || strtoupper($name) === 'CONTENT-LENGTH') {
                continue;
            }

            // A header bag declares no value type, so each entry really
            // is unknown here rather than merely un-narrowed.
            if (!is_iterable($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value)) {
                    $normalized[ucwords($name, '-')] = $value;
                }
            }
        }

        return $normalized;
    }
}
