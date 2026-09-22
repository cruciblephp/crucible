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
use LucianoPereira\Crucible\Browser\BrowserProtocolException;

use function is_array;
use function is_string;

/**
 * One isolated browsing context (a fresh cookie/storage/emulation
 * world inside a shared browser process).
 */
final readonly class BrowserContext
{
    public function __construct(
        private Connection $connection,
        public string $guid,
        private BrowserConfiguration $configuration,
    ) {}

    public function newPage(): Page
    {
        $result = $this->connection->call($this->guid, 'newPage');
        $page   = $result['page'] ?? null;

        if (!is_array($page) || !is_string($page['guid'] ?? null)) {
            throw new BrowserProtocolException('newPage did not return a page.');
        }

        return new Page($this->connection, $page['guid'], $this->configuration, $this->guid);
    }

    /**
     * Runs a script in every page of this context **before** the page's
     * own code — the seam a framework-protocol recorder needs, since
     * events fired while the app boots are gone by the time an
     * assertion could subscribe to them.
     */
    public function addInitScript(string $source): self
    {
        $this->connection->call($this->guid, 'addInitScript', ['source' => $source]);

        return $this;
    }

    public function close(): void
    {
        $this->connection->call($this->guid, 'close');
    }
}
