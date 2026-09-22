<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Style;

use function intdiv;
use function max;
use function min;

use const PHP_INT_MAX;

/**
 * An immutable terminal colour, expressible as a basic 16-colour index, an
 * xterm-256 index, or 24-bit RGB. A colour is direction-agnostic; whether it
 * renders as a foreground or background is decided when {@see foregroundParams()}
 * or {@see backgroundParams()} is called.
 */
final readonly class Color
{
    private const int MODE_BASIC = 0;
    private const int MODE_XTERM = 1;
    private const int MODE_RGB   = 2;

    /**
     * Approximate RGB of the classic 16 ANSI colours (xterm defaults), indexed
     * 0-15, used to snap arbitrary colours to the nearest classic colour.
     *
     * @var array<int, array{int, int, int}>
     */
    private const array BASE_RGB = [
        [0, 0, 0], [128, 0, 0], [0, 128, 0], [128, 128, 0],
        [0, 0, 128], [128, 0, 128], [0, 128, 128], [192, 192, 192],
        [128, 128, 128], [255, 0, 0], [0, 255, 0], [255, 255, 0],
        [0, 0, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255],
    ];

    /**
     * @param list<int> $components basic:[index 0-15], xterm:[index 0-255], rgb:[r,g,b]
     */
    private function __construct(
        private int $mode,
        private array $components,
    ) {}

    /** A basic 16-colour value (0-15). */
    public static function basic(int $index): self
    {
        return new self(self::MODE_BASIC, [max(0, min(15, $index))]);
    }

    /** An xterm-256 palette value (0-255). */
    public static function xterm(int $index): self
    {
        return new self(self::MODE_XTERM, [max(0, min(255, $index))]);
    }

    public static function rgb(int $red, int $green, int $blue): self
    {
        return new self(self::MODE_RGB, [
            max(0, min(255, $red)),
            max(0, min(255, $green)),
            max(0, min(255, $blue)),
        ]);
    }

    public static function black(): self
    {
        return self::basic(0);
    }

    public static function red(): self
    {
        return self::basic(1);
    }

    public static function green(): self
    {
        return self::basic(2);
    }

    public static function yellow(): self
    {
        return self::basic(3);
    }

    public static function blue(): self
    {
        return self::basic(4);
    }

    public static function magenta(): self
    {
        return self::basic(5);
    }

    public static function cyan(): self
    {
        return self::basic(6);
    }

    public static function white(): self
    {
        return self::basic(7);
    }

    public static function gray(): self
    {
        return self::basic(8);
    }

    /**
     * SGR parameters selecting this colour as a foreground.
     *
     * @return list<int>
     */
    public function foregroundParams(): array
    {
        return $this->params(38, 30, 90);
    }

    /**
     * SGR parameters selecting this colour as a background.
     *
     * @return list<int>
     */
    public function backgroundParams(): array
    {
        return $this->params(48, 40, 100);
    }

    public function equals(self $other): bool
    {
        return $this->mode === $other->mode && $this->components === $other->components;
    }

    /** Return this colour rendered for the given palette (Full is a no-op). */
    public function forPalette(Palette $palette): self
    {
        return $palette === Palette::Classic16 ? $this->toBasic16() : $this;
    }

    /** Snap this colour to the nearest of the classic 16 ANSI colours. */
    public function toBasic16(): self
    {
        if ($this->mode === self::MODE_BASIC) {
            return $this;
        }

        [$r, $g, $b]  = $this->toRgb();
        $best         = 0;
        $bestDistance = PHP_INT_MAX;

        foreach (self::BASE_RGB as $index => [$br, $bg, $bb]) {
            $distance = ($r - $br) ** 2 + ($g - $bg) ** 2 + ($b - $bb) ** 2;

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best         = $index;
            }
        }

        return self::basic($best);
    }

    /**
     * The approximate 24-bit RGB value of this colour.
     *
     * @return array{int, int, int}
     */
    private function toRgb(): array
    {
        if ($this->mode === self::MODE_RGB) {
            return [$this->components[0], $this->components[1], $this->components[2]];
        }

        if ($this->mode === self::MODE_BASIC) {
            return self::BASE_RGB[$this->components[0]];
        }

        return $this->xtermToRgb($this->components[0]);
    }

    /**
     * @return array{int, int, int}
     */
    private function xtermToRgb(int $index): array
    {
        if ($index < 16) {
            return self::BASE_RGB[$index];
        }

        if ($index < 232) {
            $index -= 16;
            $levels = [0, 95, 135, 175, 215, 255];

            return [
                $levels[intdiv($index, 36) % 6],
                $levels[intdiv($index, 6) % 6],
                $levels[$index % 6],
            ];
        }

        $gray = 8 + ($index - 232) * 10;

        return [$gray, $gray, $gray];
    }

    /**
     * @return list<int>
     */
    private function params(int $extendedSelector, int $basicOffset, int $brightOffset): array
    {
        return match ($this->mode) {
            self::MODE_XTERM => [$extendedSelector, 5, $this->components[0]],
            self::MODE_RGB   => [$extendedSelector, 2, $this->components[0], $this->components[1], $this->components[2]],
            default          => [$this->components[0] < 8
                ? $basicOffset + $this->components[0]
                : $brightOffset + ($this->components[0] - 8)],
        };
    }
}
