<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

/**
 * How protocol messages reach a Playwright server. The default is the
 * driver process over stdio pipes; the seam exists so a future
 * transport (a shared websocket server for remote browsers, or a
 * WebDriver BiDi client if the standard catches up) swaps in without
 * touching the protocol layer above.
 */
interface Transport
{
    /**
     * @param array<string, mixed> $message
     */
    public function send(array $message): void;

    /**
     * Blocking read of the next protocol message.
     *
     * @return array<string, mixed>
     */
    public function receive(): array;

    public function close(): void;
}
