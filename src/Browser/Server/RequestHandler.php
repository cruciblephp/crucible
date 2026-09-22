<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Server;

/**
 * What the in-process server serves. THE point of the design: the
 * handler runs inside the test process, so test-side fakes, doubles,
 * and state mutations are visible to every request the browser makes
 * — the Laravel-fakes contract (spec/pest-api.md §6), framework-
 * agnostic. A framework bridge implements this with its kernel; tests
 * implement it with a closure.
 */
interface RequestHandler
{
    public function handle(HttpRequest $request): HttpResponse;
}
