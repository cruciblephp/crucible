<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use function substr;

/**
 * One function as the {@see Observer} saw it over a run.
 */
final class ObservedFunction
{
    public int $calls = 0;

    /** @var array<string, array<string, int>> slot (`$name` or `return`) => type => calls */
    public array $types = [];

    /** @var array<string, array{written: string, mismatch: string, calls: int}> slot => the first value refused, and how many were */
    public array $contradictions = [];

    /** @var array<string, list<mixed>> slot => values put off until the suite is over */
    public array $deferred = [];

    /**
     * @param list<string> $parameters the names of the parameters, variadic left out
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly array $parameters,
        public readonly DocTypes $docs,
    ) {}

    /** @return ?array{string, \LucianoPereira\Crucible\Types\TypeExpression} */
    public function doc(string $slot): ?array
    {
        return $slot === 'return' ? $this->docs->return : ($this->docs->parameters[substr($slot, 1)] ?? null);
    }

    public function refused(string $slot, string $written, string $mismatch): void
    {
        $this->contradictions[$slot] ??= ['written' => $written, 'mismatch' => $mismatch, 'calls' => 0];
        $this->contradictions[$slot]['calls']++;
    }
}
