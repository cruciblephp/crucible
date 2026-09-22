<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

/**
 * The marker every Mockery-surface double implements — the alias
 * target of \Mockery\MockInterface. A sibling of Mocked, not Mocked
 * itself: the two grammars declare different expects() signatures,
 * so they cannot share one interface. The one declared method is the
 * catch-all the generated classes really carry — it is also what
 * lets static analysis accept any method on a mock, exactly the
 * runtime contract.
 *
 * @method MockeryExpectation shouldReceive(array<string, mixed>|string ...$methods)
 * @method MockeryExpectation shouldNotReceive(array<string, mixed>|string ...$methods)
 * @method MockeryExpectation allows(array<string, mixed>|string ...$methods)
 * @method MockeryExpectation expects(array<string, mixed>|string ...$methods)
 */
interface MockeryMock
{
    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed;
}
