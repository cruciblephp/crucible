<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Input;

/** A key press (or pasted chunk) delivered to a component. */
final readonly class KeyEvent implements Event
{
    public function __construct(
        public string $key,
    ) {}

    /**
     * Whether this key matches any of the given sequences.
     *
     * @param string|list<string> $expected
     */
    public function is(string|array $expected): bool
    {
        return Key::is($this->key, $expected);
    }

    public function isPrintable(): bool
    {
        return Key::isPrintable($this->key);
    }
}
