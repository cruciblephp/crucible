<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\BrowserNotEnabledException;
use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\Pumpable;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function is_array;
use function is_string;

/**
 * One driver session: the config gate, the initialize handshake, and
 * the root of the remote object tree. Constructing a session is the
 * FIRST moment the browser tier touches Node — the default-off
 * contract lives here, not in scattered checks.
 */
final readonly class Session
{
    /** Launch budget, as observed from the incumbent's channel (ms). */
    private const int LAUNCH_TIMEOUT = 180_000;

    private function __construct(
        private Connection $connection,
        private string $playwrightGuid,
        private BrowserConfiguration $configuration,
    ) {}

    /**
     * @param Pumpable|null    $serveWhileWaiting the in-process HTTP server (or any work that
     *                                            must progress during driver waits)
     */
    public static function start(BrowserConfiguration $configuration, WorkingDirectory $workingDirectory, ?Pumpable $serveWhileWaiting = null): self
    {
        if ($configuration->enabled === null) {
            throw new BrowserNotEnabledException(
                "The browser tier is off by default. Enable it in crucible.php with ->browser() — Crucible never downloads browsers or touches Node without that explicit opt-in.",
            );
        }

        if ($configuration->enabled === false) {
            throw new BrowserNotEnabledException(
                'The browser tier is explicitly disabled (->browser(enabled: false)); browser tests report as skipped in this state.',
            );
        }

        $transport = new DriverTransport($configuration->playwrightRoot ?? $workingDirectory->path);

        if ($serveWhileWaiting instanceof Pumpable) {
            $transport->watch($serveWhileWaiting);
        }

        $connection = new Connection($transport);
        $result     = $connection->call('', 'initialize', ['sdkLanguage' => 'javascript']);
        $playwright = $result['playwright'] ?? null;

        if (!is_array($playwright) || !is_string($playwright['guid'] ?? null)) {
            $connection->close();

            throw new BrowserProtocolException('The driver initialize reply did not announce a Playwright root object.');
        }

        return new self($connection, $playwright['guid'], $configuration);
    }

    /**
     * Adds a Pumpable to the transport's select loop after the fact —
     * the in-process server may only come into existence at the first
     * relative visit, while an absolute visit already started the
     * session (D-065).
     */
    public function watch(Pumpable $pump): void
    {
        $this->connection->watch($pump);
    }

    public function launch(): Browser
    {
        $engineGuid = $this->connection
            ->object($this->playwrightGuid)
            ->referencedGuid($this->configuration->engine->playwrightType());

        $result = $this->connection->call($engineGuid, 'launch', [
            'timeout'  => self::LAUNCH_TIMEOUT,
            'headless' => !$this->configuration->headed,
        ]);
        $browser = $result['browser'] ?? null;

        if (!is_array($browser) || !is_string($browser['guid'] ?? null)) {
            throw new BrowserProtocolException('launch did not return a browser.');
        }

        return new Browser($this->connection, $browser['guid'], $this->configuration);
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
