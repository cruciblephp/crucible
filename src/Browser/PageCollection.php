<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

use LucianoPereira\Crucible\Browser\Playwright\Page;

use function count;

/**
 * The plural visit(['/a', '/b']) surface (D-064, probe-pinned): every
 * interaction and assertion fans out to every page — a failing page
 * fails the fan-out with its own message — and the chain continues on
 * the collection. The pages stay reachable for anything per-page.
 *
 * @method self assertSee(string $text)
 * @method self assertTitle(string $title)
 * @method self assertTitleContains(string $needle)
 * @method self assertNoSmoke()
 * @method self assertNoConsoleLogs()
 * @method self assertNoJavaScriptErrors()
 */
final readonly class PageCollection
{
    /**
     * @param non-empty-list<Page> $pages
     */
    public function __construct(
        public array $pages,
    ) {}

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): self
    {
        foreach ($this->pages as $page) {
            $page->{$method}(...$arguments);
        }

        return $this;
    }

    public function count(): int
    {
        return count($this->pages);
    }
}
