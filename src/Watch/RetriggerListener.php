<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Watch;

use LucianoPereira\Crucible\Browser\Server\InProcessServer;
use Throwable;

use function bin2hex;
use function file_put_contents;
use function is_file;
use function random_bytes;
use function sprintf;
use function unlink;

/**
 * The socket half of the retrigger endpoint (D-083): it binds, it
 * publishes its address, and it hands the loop whatever was pushed.
 *
 * The address is published the way Vite publishes its dev server — a
 * **hot file** holding the whole URL, token included. Its presence is
 * therefore the liveness signal too: a producer that finds no file
 * knows Crucible is not listening and does nothing, so nothing has to be
 * configured in two places. It is removed on exit.
 *
 * Opening is **fail-soft**. A port already taken, a read-only
 * directory — none of that is worth killing a watch session over, so
 * `open()` returns null and the loop simply runs without the endpoint,
 * exactly as it did before this existed.
 */
final readonly class RetriggerListener
{
    private function __construct(
        private InProcessServer $server,
        private RetriggerEndpoint $endpoint,
        private string $hotFile,
        private string $url,
    ) {}

    /**
     * @param non-empty-string $hotFile absolute path
     * @param int              $port    0 = an ephemeral port, which never collides between projects
     */
    public static function open(string $hotFile, int $port = 0): ?self
    {
        try {
            $token    = bin2hex(random_bytes(16));
            $endpoint = new RetriggerEndpoint($token);
            $server   = new InProcessServer($endpoint, $port);
            $url      = sprintf('%s/retrigger?token=%s', $server->baseUrl(), $token);

            if (file_put_contents($hotFile, $url . "\n") === false) {
                $server->close();

                return null;
            }

            return new self($server, $endpoint, $hotFile, $url);
        } catch (Throwable) {
            return null;
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return list<resource> joined to the loop's select set, so a push
     *                        wakes the watcher at once instead of on the next poll
     */
    public function watchStreams(): array
    {
        return $this->server->watchStreams();
    }

    public function pump(): void
    {
        $this->server->pump();
    }

    /**
     * @return list<non-empty-string> the paths pushed since the last call
     */
    public function take(): array
    {
        return $this->endpoint->take();
    }

    public function close(): void
    {
        $this->server->close();

        if (is_file($this->hotFile)) {
            unlink($this->hotFile);
        }
    }
}
