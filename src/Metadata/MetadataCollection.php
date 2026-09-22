<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Metadata;

use ArrayIterator;
use Countable;
use IteratorAggregate;

use function array_first;
use function array_merge;
use function array_values;
use function count;

/**
 * An ordered, immutable set of metadata values (attribute instances).
 * Attribute classes are the metadata model — there is no parallel
 * class hierarchy to keep in sync.
 *
 * @implements IteratorAggregate<int, CrucibleAttribute>
 */
final readonly class MetadataCollection implements Countable, IteratorAggregate
{
    /**
     * @param list<CrucibleAttribute> $attributes
     */
    private function __construct(
        public array $attributes,
    ) {}

    public static function from(CrucibleAttribute ...$attributes): self
    {
        return new self(array_values($attributes));
    }

    /**
     * @template T of CrucibleAttribute
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function ofType(string $type): array
    {
        $matches = [];

        foreach ($this->attributes as $attribute) {
            if ($attribute instanceof $type) {
                $matches[] = $attribute;
            }
        }

        return $matches;
    }

    /**
     * @param class-string<CrucibleAttribute> $type
     */
    public function has(string $type): bool
    {
        return $this->ofType($type) !== [];
    }

    /**
     * @template T of CrucibleAttribute
     *
     * @param class-string<T> $type
     *
     * @return ?T
     */
    public function first(string $type): ?CrucibleAttribute
    {
        return array_first($this->ofType($type));
    }

    public function mergedWith(self $other): self
    {
        return new self(array_merge($this->attributes, $other->attributes));
    }

    public function count(): int
    {
        return count($this->attributes);
    }

    /**
     * @return ArrayIterator<int, CrucibleAttribute>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->attributes);
    }
}
