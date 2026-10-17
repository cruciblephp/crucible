<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

/**
 * What `test()` returns when called with no description: the test that is
 * running, as under Pest. Plugin helpers are one-line proxies onto it
 * (`return test()->get(...)`), and the methods they reach are often
 * protected, so each call goes through CurrentTest::call().
 */
final readonly class RunningTest
{
    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return CurrentTest::call($method, $arguments);
    }
}
