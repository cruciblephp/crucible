<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Style;

use function implode;

/**
 * An immutable text style: an optional foreground and background colour plus a
 * set of {@see Attribute}s. Every mutator returns a new instance, and
 * {@see toAnsi()} renders the whole style to a single SGR sequence.
 *
 * Style is pure data — it performs no I/O and holds no global state.
 *
 * Distinct from Reporting\Style (Crucible's own narrow 4-color reporter
 * palette, unchanged) — this is the general-purpose CLI/interactive styling
 * toolkit used by src/Console/*, not a replacement for it.
 */
final class Style
{
    private static ?self $none = null;

    /** @param int $attributes bitmask of {@see Attribute::bit()} values */
    public function __construct(
        public readonly ?Color $foreground = null,
        public readonly ?Color $background = null,
        private readonly int $attributes = 0,
    ) {}

    /** The empty style (no colours, no attributes). */
    public static function none(): self
    {
        return self::$none ??= new self();
    }

    public function withForeground(?Color $color): self
    {
        return new self($color, $this->background, $this->attributes);
    }

    public function withBackground(?Color $color): self
    {
        return new self($this->foreground, $color, $this->attributes);
    }

    public function with(Attribute ...$attributes): self
    {
        $mask = $this->attributes;

        foreach ($attributes as $attribute) {
            $mask |= $attribute->bit();
        }

        return new self($this->foreground, $this->background, $mask);
    }

    public function has(Attribute $attribute): bool
    {
        return ($this->attributes & $attribute->bit()) !== 0;
    }

    public function bold(): self
    {
        return $this->with(Attribute::Bold);
    }

    public function dim(): self
    {
        return $this->with(Attribute::Dim);
    }

    public function italic(): self
    {
        return $this->with(Attribute::Italic);
    }

    public function underline(): self
    {
        return $this->with(Attribute::Underline);
    }

    public function inverse(): self
    {
        return $this->with(Attribute::Inverse);
    }

    public function isEmpty(): bool
    {
        return !$this->foreground instanceof \LucianoPereira\Crucible\Console\Style\Color && !$this->background instanceof \LucianoPereira\Crucible\Console\Style\Color && $this->attributes === 0;
    }

    public function equals(self $other): bool
    {
        return $this->attributes === $other->attributes
            && $this->colorEquals($this->foreground, $other->foreground)
            && $this->colorEquals($this->background, $other->background);
    }

    /** Render this style to an SGR sequence, or an empty string when empty. */
    public function toAnsi(): string
    {
        $params = [];

        foreach (Attribute::cases() as $attribute) {
            if ($this->has($attribute)) {
                $params[] = $attribute->value;
            }
        }

        $palette = Theme::palette();

        if ($this->foreground instanceof \LucianoPereira\Crucible\Console\Style\Color) {
            $params = [...$params, ...$this->foreground->forPalette($palette)->foregroundParams()];
        }

        if ($this->background instanceof \LucianoPereira\Crucible\Console\Style\Color) {
            $params = [...$params, ...$this->background->forPalette($palette)->backgroundParams()];
        }

        return $params === [] ? '' : "\e[" . implode(';', $params) . 'm';
    }

    private function colorEquals(?Color $a, ?Color $b): bool
    {
        if (!$a instanceof \LucianoPereira\Crucible\Console\Style\Color || !$b instanceof \LucianoPereira\Crucible\Console\Style\Color) {
            return $a === $b;
        }

        return $a->equals($b);
    }
}
