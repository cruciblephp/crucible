<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Server;

use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\Pumpable;
use Override;

use function array_slice;
use function count;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function is_int;
use function parse_url;
use function sprintf;
use function str_contains;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const PHP_URL_PORT;

/**
 * The dependency-free HTTP/1.1 server that lives INSIDE the test
 * process. Bound to 127.0.0.1 on an ephemeral port; pumped from the
 * driver transport's select loop, so the browser's page loads are
 * served while PHP waits for the navigation reply — one cooperative
 * loop, no second process, and therefore one shared world: whatever
 * the test faked, the served request sees.
 *
 * Deliberately simple where simple is correct: one request per
 * connection (Connection: close), bodies via Content-Length only.
 * Honest limit: a handler that blocks forever starves the loop —
 * the handler runs app code in-process by design.
 *
 * Nothing here is browser-specific beyond where it was first needed:
 * the watch loop's retrigger endpoint (D-083) is its second consumer,
 * pumping the same cooperative loop from `stream_select`.
 */
final class InProcessServer implements Pumpable
{
    /** @var resource */
    private $listener;

    private readonly int $port;

    /** @var array<int, array{stream: resource, buffer: string}> */
    private array $connections = [];

    /**
     * @param int $port 0 = an ephemeral port; a fixed one only where the
     *                  environment demands it (a container, a firewall rule)
     */
    public function __construct(
        private readonly RequestHandler $handler,
        int $port = 0,
    ) {
        $listener = @stream_socket_server('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage);

        if ($listener === false) {
            throw new BrowserProtocolException(sprintf('Could not bind the in-process server: %s (%d).', $errorMessage, $errorCode));
        }

        $this->listener = $listener;
        stream_set_blocking($this->listener, false);

        $name       = stream_socket_get_name($this->listener, false);
        $port       = $name === false ? null : parse_url('tcp://' . $name, PHP_URL_PORT);
        $this->port = is_int($port) ? $port : throw new BrowserProtocolException('The in-process server has no port.');
    }

    public function port(): int
    {
        return $this->port;
    }

    public function baseUrl(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    /**
     * @return list<resource>
     */
    #[Override]
    public function watchStreams(): array
    {
        $streams = [$this->listener];

        foreach ($this->connections as $connection) {
            $streams[] = $connection['stream'];
        }

        return $streams;
    }

    #[Override]
    public function pump(): void
    {
        // accept whatever is queued
        while (($client = @stream_socket_accept($this->listener, 0)) !== false) {
            stream_set_blocking($client, false);
            $this->connections[(int) $client] = ['stream' => $client, 'buffer' => ''];
        }

        if ($this->connections === []) {
            return;
        }

        // read what arrived, serve every completed request
        $read = [];

        foreach ($this->connections as $connection) {
            $read[] = $connection['stream'];
        }

        $write  = null;
        $except = null;

        if (@stream_select($read, $write, $except, 0) === false) {
            return;
        }

        foreach ($read as $stream) {
            $key   = (int) $stream;
            $chunk = fread($stream, 65_536);

            if ($chunk === false || $chunk === '') {
                // speculative connection closed by the browser
                unset($this->connections[$key]);
                fclose($stream);

                continue;
            }

            $buffer                  = $this->connections[$key]['buffer'] . $chunk;
            $this->connections[$key] = ['stream' => $stream, 'buffer' => $buffer];
            $request                 = $this->completedRequest($buffer);

            if ($request instanceof HttpRequest) {
                $response = $this->handler->handle($request);
                fwrite($stream, $response->render());
                unset($this->connections[$key]);
                fclose($stream);
            }
        }
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            fclose($connection['stream']);
        }

        $this->connections = [];
        fclose($this->listener);
    }

    /** Parses the buffer once headers (and any declared body) are whole. */
    private function completedRequest(string $buffer): ?HttpRequest
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");

        if ($headerEnd === false) {
            return null;
        }

        $head        = substr($buffer, 0, $headerEnd);
        $lines       = explode("\r\n", $head);
        $requestLine = explode(' ', $lines[0]);

        if (count($requestLine) < 2) {
            return null;
        }

        $headers = [];

        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value]                   = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        $contentLength = (int) ($headers['content-length'] ?? 0);
        $body          = substr($buffer, $headerEnd + 4);

        if (strlen($body) < $contentLength) {
            return null; // body still arriving
        }

        return new HttpRequest($requestLine[0], $requestLine[1], $headers, substr($body, 0, $contentLength));
    }
}
