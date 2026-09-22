<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function array_is_list;
use function array_keys;
use function array_values;
use function count;
use function mb_strtolower;
use function str_contains;

/**
 * Normalises the two option shapes accepted by list prompts:
 *
 *  - a plain list of labels (`['Red', 'Green']`) whose selected *value* is the
 *    label itself; and
 *  - an associative map of value => label (`['r' => 'Red']`) whose selected
 *    value is the key.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class Options implements IteratorAggregate, Countable
{
    /**
     * @param list<int|string> $keys
     * @param list<string> $labels
     */
    private function __construct(
        private array $keys,
        private array $labels,
        private bool $isList,
    ) {}

    /**
     * @param array<int|string, string> $options
     */
    public static function from(array $options): self
    {
        return new self(
            array_keys($options),
            array_values($options),
            array_is_list($options),
        );
    }

    public function count(): int
    {
        return count($this->labels);
    }

    public function isEmpty(): bool
    {
        return $this->labels === [];
    }

    public function labelAt(int $index): string
    {
        return $this->labels[$index] ?? '';
    }

    /** The value produced when the option at $index is selected. */
    public function valueAt(int $index): int|string
    {
        return $this->isList ? ($this->labels[$index] ?? '') : ($this->keys[$index] ?? '');
    }

    /** Locate the index of a value (as returned by {@see valueAt()}), or null. */
    public function indexOfValue(int|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $haystack = $this->isList ? $this->labels : $this->keys;

        foreach ($haystack as $index => $candidate) {
            if ($candidate === $value) {
                return $index;
            }
        }

        return null;
    }

    /** The label for a given selected value, for display in submitted frames. */
    public function labelForValue(int|string $value): string
    {
        $index = $this->indexOfValue($value);

        return $index === null ? (string) $value : $this->labelAt($index);
    }

    /**
     * Return a new instance keeping only options whose label matches the query
     * (case-insensitive substring). Original keys/values are preserved.
     */
    public function filter(string $query): self
    {
        if ($query === '') {
            return $this;
        }

        $keys   = [];
        $labels = [];
        $needle = mb_strtolower($query);

        foreach ($this->labels as $index => $label) {
            if (str_contains(mb_strtolower($label), $needle)) {
                $keys[]   = $this->keys[$index];
                $labels[] = $label;
            }
        }

        return new self($keys, $labels, $this->isList);
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->labels);
    }
}
