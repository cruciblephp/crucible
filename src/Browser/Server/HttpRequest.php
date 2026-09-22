<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Server;

use function explode;
use function is_string;
use function parse_str;
use function parse_url;
use function str_contains;
use function strtolower;

use const PHP_URL_PATH;

/**
 * One parsed HTTP request as the in-process server saw it. Header
 * names are lowercased; the query is pre-parsed because handlers
 * almost always want it.
 */
final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers lowercased names
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers,
        public string $body = '',
    ) {}

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);

        return is_string($path) ? $path : '/';
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        if (!str_contains($this->uri, '?')) {
            return [];
        }

        parse_str(explode('?', $this->uri, 2)[1], $query);
        $parameters = [];

        foreach ($query as $name => $value) {
            $parameters[(string) $name] = $value;
        }

        return $parameters;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
