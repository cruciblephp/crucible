<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\PHPStan;

use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * What a Pest file's `beforeEach` hooks put on `$this`, per file (D-144).
 *
 * The runtime runs those hooks on the test's own instance, so a property
 * one of them sets is there in every test of the file. PHPStan analyses a
 * file top to bottom, so the hooks are recorded before the tests below them
 * read the properties.
 */
final class PestHookProperties
{
    /** @var array<string, true> */
    private array $dialectFiles = [];

    /** @var array<string, list<array{int, int}>> */
    private array $hooks = [];

    /** @var array<string, array<string, Type>> */
    private array $properties = [];

    public function markDialectFile(string $file): void
    {
        $this->dialectFiles[$file] = true;
    }

    public function isDialectFile(string $file): bool
    {
        return isset($this->dialectFiles[$file]);
    }

    public function markHook(string $file, int $startLine, int $endLine): void
    {
        $this->hooks[$file][] = [$startLine, $endLine];
    }

    public function inHook(string $file, int $line): bool
    {
        foreach ($this->hooks[$file] ?? [] as [$start, $end]) {
            if ($line >= $start && $line <= $end) {
                return true;
            }
        }

        return false;
    }

    public function record(string $file, string $property, Type $type): void
    {
        $known = $this->properties[$file][$property] ?? null;

        $this->properties[$file][$property] = $known === null ? $type : TypeCombinator::union($known, $type);
    }

    public function typeOf(string $file, string $property): ?Type
    {
        return $this->properties[$file][$property] ?? null;
    }
}
