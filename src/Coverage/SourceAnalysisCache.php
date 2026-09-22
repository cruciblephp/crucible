<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function count;

/**
 * The in-process memo behind {@see SourceAnalysis::of()}. Its own class
 * because SourceAnalysis is a readonly value object and a cache is the
 * opposite of one — and because a cache with a name is a cache that can
 * be reasoned about, warmed, and emptied.
 *
 * Entries are keyed by path *and* mtime, so an edited file is a
 * different entry rather than a stale answer.
 */
final class SourceAnalysisCache
{
    /** @var array<string, SourceAnalysis> */
    private static array $entries = [];

    public static function get(string $key): ?SourceAnalysis
    {
        return self::$entries[$key] ?? null;
    }

    public static function put(string $key, SourceAnalysis $analysis): void
    {
        self::$entries[$key] = $analysis;
    }

    public static function count(): int
    {
        return count(self::$entries);
    }

    public static function clear(): void
    {
        self::$entries = [];
    }
}
