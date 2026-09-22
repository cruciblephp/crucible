<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use Closure;

use function is_iterable;

/**
 * What describe() returns: chainables that apply to every test the
 * block (including nested blocks) declared. The body has already run
 * when this exists, so applying is a plain loop over the collected
 * calls.
 */
final readonly class DescribeCall
{
    /**
     * @param list<TestCall> $calls
     */
    public function __construct(
        private array $calls,
    ) {}

    /**
     * @param non-empty-string ...$groups
     */
    public function group(string ...$groups): self
    {
        foreach ($this->calls as $call) {
            $call->group(...$groups);
        }

        return $this;
    }

    public function skip(bool|string|Closure $condition = true, string $reason = ''): self
    {
        foreach ($this->calls as $call) {
            if ($call->skipped === false) {
                $call->skip($condition, $reason);
            }
        }

        return $this;
    }

    /**
     * A dataset for every contained test — one more Cartesian factor
     * for tests that already carry their own.
     *
     * @param iterable<array-key, mixed>|non-empty-string|Closure $dataset
     */
    public function with(iterable|string|Closure $dataset): self
    {
        // Materialize plain iterables once — a generator would be
        // drained by the first test and empty for the rest. Names and
        // closures resolve per use, so they pass through.
        if (is_iterable($dataset)) {
            $rows = [];

            foreach ($dataset as $key => $row) {
                $rows[$key] = $row;
            }

            $dataset = $rows;
        }

        foreach ($this->calls as $call) {
            $call->with($dataset);
        }

        return $this;
    }
}
