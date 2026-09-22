<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Server;

use function sprintf;
use function strlen;

/**
 * One response for the in-process server to write. Connection: close
 * always — one request per connection keeps the cooperative loop
 * trivially correct; browsers reconnect cheaply on 127.0.0.1.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public string $contentType = 'text/html; charset=utf-8',
        public array $headers = [],
    ) {}

    public static function html(string $body): self
    {
        return new self($body);
    }

    public static function notFound(): self
    {
        return new self('Not Found', 404, 'text/plain; charset=utf-8');
    }

    public function render(): string
    {
        $head = sprintf("HTTP/1.1 %d %s\r\n", $this->status, $this->status === 200 ? 'OK' : 'Status');
        $head .= sprintf("Content-Type: %s\r\n", $this->contentType);
        $head .= sprintf("Content-Length: %d\r\n", strlen($this->body));
        $head .= "Connection: close\r\n";

        foreach ($this->headers as $name => $value) {
            $head .= sprintf("%s: %s\r\n", $name, $value);
        }

        return $head . "\r\n" . $this->body;
    }
}
