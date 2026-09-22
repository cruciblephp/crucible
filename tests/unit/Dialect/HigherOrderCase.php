<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect;

use AllowDynamicProperties;
use LucianoPereira\Crucible\Framework\TestCase;

/**
 * The uses() target for PestDialectLongTail.pest.php: a test case
 * with domain-flavored methods, so higher-order chains have something
 * real to replay against. Not final — tests/unit/Pest.php composes a
 * trait onto it via pest()->use().
 */
#[AllowDynamicProperties]
class HigherOrderCase extends TestCase
{
    /**
     * Suite state the hooks assign — declared, because a typed
     * uses() class is exactly the alternative to dynamic properties
     * the analyzer can see through (D-050).
     */
    public string $brew = '';

    public string $fromSuiteConfig = '';

    public string $hookOrder = '';

    /** @var list<string> */
    private array $visited = [];

    public function visit(string $page): self
    {
        $this->visited[] = $page;

        return $this;
    }

    public function assertVisited(string $page): self
    {
        self::assertContains($page, $this->visited);

        return $this;
    }

    public function double(int $number): int
    {
        return $number * 2;
    }
}
