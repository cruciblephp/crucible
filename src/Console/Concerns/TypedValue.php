<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Concerns;

use LucianoPereira\Crucible\Console\Input\Key;
use LucianoPereira\Crucible\Console\Support\Str;

use function max;
use function min;

/**
 * Provides an editable text buffer with a cursor, shared by every text-based
 * prompt.
 *
 * The host prompt calls {@see trackTypedValue()} once during construction to
 * wire the buffer up to key events.
 */
trait TypedValue
{
    protected string $typedValue = '';

    /** The cursor offset within the typed value, in characters. */
    protected int $cursorPosition = 0;

    private bool $ignoreInput = false;

    private bool $allowNewLine = false;

    /**
     * Begin tracking typed input against the buffer.
     *
     * @param bool $allowNewLine whether Enter inserts a newline instead of submitting
     * @param bool $submitOnEnter whether Enter should submit when newlines are disallowed
     */
    protected function trackTypedValue(
        string $default = '',
        bool $allowNewLine = false,
        bool $submitOnEnter = true,
    ): void {
        $this->typedValue     = $default;
        $this->cursorPosition = Str::length($default);
        $this->allowNewLine   = $allowNewLine;

        $this->on('key', function (string $key) use ($submitOnEnter): void {
            if ($this->ignoreInput) {
                return;
            }

            if (Key::is($key, [Key::LEFT, Key::LEFT_ARROW, Key::CTRL_B])) {
                $this->cursorPosition = max(0, $this->cursorPosition - 1);

                return;
            }

            if (Key::is($key, [Key::RIGHT, Key::RIGHT_ARROW, Key::CTRL_F])) {
                $this->cursorPosition = min(Str::length($this->typedValue), $this->cursorPosition + 1);

                return;
            }

            if (Key::is($key, Key::CTRL_A) || Key::is($key, Key::HOME)) {
                $this->cursorPosition = 0;

                return;
            }

            if (Key::is($key, Key::CTRL_E) || Key::is($key, Key::END)) {
                $this->cursorPosition = Str::length($this->typedValue);

                return;
            }

            if (Key::is($key, [Key::BACKSPACE, Key::CTRL_H])) {
                $this->deleteBeforeCursor();

                return;
            }

            if (Key::is($key, Key::DELETE)) {
                $this->deleteAtCursor();

                return;
            }

            if ($this->allowNewLine && Key::is($key, Key::enter())) {
                $this->insert("\n");

                return;
            }

            if ($submitOnEnter && Key::is($key, Key::enter())) {
                $this->submit();

                return;
            }

            if (Key::isPrintable($key)) {
                $this->insert($key);
            }
        });
    }

    abstract protected function submit(): void;

    /** Temporarily suspend buffer editing (used while a sub-prompt is active). */
    protected function ignoreInput(bool $ignore = true): void
    {
        $this->ignoreInput = $ignore;
    }

    /** Replace the buffer contents and move the cursor to the end. */
    protected function setTypedValue(string $value): void
    {
        $this->typedValue     = $value;
        $this->cursorPosition = Str::length($value);
    }

    public function value(): string
    {
        return $this->typedValue;
    }

    /**
     * The raw contents of the text buffer, independent of any {@see value()}
     * override.
     */
    public function typedText(): string
    {
        return $this->typedValue;
    }

    public function cursorPosition(): int
    {
        return $this->cursorPosition;
    }

    private function insert(string $text): void
    {
        $before = Str::substr($this->typedValue, 0, $this->cursorPosition);
        $after  = Str::substr($this->typedValue, $this->cursorPosition);

        $this->typedValue = $before . $text . $after;
        $this->cursorPosition += Str::length($text);
    }

    private function deleteBeforeCursor(): void
    {
        if ($this->cursorPosition === 0) {
            return;
        }

        $before = Str::substr($this->typedValue, 0, $this->cursorPosition - 1);
        $after  = Str::substr($this->typedValue, $this->cursorPosition);

        $this->typedValue = $before . $after;
        --$this->cursorPosition;
    }

    private function deleteAtCursor(): void
    {
        if ($this->cursorPosition >= Str::length($this->typedValue)) {
            return;
        }

        $before = Str::substr($this->typedValue, 0, $this->cursorPosition);
        $after  = Str::substr($this->typedValue, $this->cursorPosition + 1);

        $this->typedValue = $before . $after;
    }
}
