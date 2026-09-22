<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use function sprintf;

/**
 * How often a mocked method is expected to be called: a min/max
 * window. The spec's once()/never()/exactly()/atLeastOnce()/atMost()/
 * any() are all instances of this one shape.
 *
 * Also the spec's matcher-as-live-counter idiom: the same instance
 * passed to expects() is queryable mid-run via numberOfInvocations()
 * (real-world usage: a willReturnCallback() closure that varies its
 * result by how many times it has already been called). $invocations
 * is the one mutable field — MethodConfigurator::registerInvocation()
 * keeps it in sync with its own count, since expects() binds exactly
 * one InvocationCount to one MethodConfigurator.
 */
final class InvocationCount
{
    private int $invocations = 0;

    private function __construct(
        public readonly int $min,
        public readonly ?int $max,
        public readonly string $description,
    ) {}

    public static function any(): self
    {
        return new self(0, null, 'any number of times');
    }

    public static function never(): self
    {
        return new self(0, 0, 'never');
    }

    public static function once(): self
    {
        return new self(1, 1, 'once');
    }

    public static function exactly(int $count): self
    {
        return new self($count, $count, sprintf('exactly %d time(s)', $count));
    }

    public static function atLeastOnce(): self
    {
        return new self(1, null, 'at least once');
    }

    public static function atLeast(int $count): self
    {
        return new self($count, null, sprintf('at least %d time(s)', $count));
    }

    public static function atMost(int $count): self
    {
        return new self(0, $count, sprintf('at most %d time(s)', $count));
    }

    /** The Mockery grammar's between($min, $max) window. */
    public static function between(int $min, int $max): self
    {
        return new self($min, $max, sprintf('between %d and %d times', $min, $max));
    }

    public function exceededBy(int $calls): bool
    {
        return $this->max !== null && $calls > $this->max;
    }

    public function satisfiedBy(int $calls): bool
    {
        return $calls >= $this->min && ($this->max === null || $calls <= $this->max);
    }

    public function recordInvocation(): void
    {
        $this->invocations++;
    }

    public function numberOfInvocations(): int
    {
        return $this->invocations;
    }
}
