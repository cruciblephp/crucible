<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Components;

use LucianoPereira\Crucible\Console\Concerns\EmitsEvents;
use LucianoPereira\Crucible\Console\Concerns\Validation;
use LucianoPereira\Crucible\Console\Exceptions\NonInteractiveException;
use LucianoPereira\Crucible\Console\Input\Event;
use LucianoPereira\Crucible\Console\Input\KeyEvent;
use LucianoPereira\Crucible\Console\PromptState;
use LucianoPereira\Crucible\Console\Screen\Buffer;
use LucianoPereira\Crucible\Console\Screen\Line;
use LucianoPereira\Crucible\Console\Style\Color;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Support\Str;

use function count;
use function max;

/**
 * The base for every interactive prompt component.
 *
 * Concrete prompts implement {@see value()} and {@see frame()} (the styled rows
 * to draw); this base owns state, validation, key dispatch, and the shared
 * gutter chrome. It draws into a {@see Buffer} and never touches ANSI directly.
 */
abstract class Prompt implements Component
{
    use EmitsEvents;
    use Validation;

    protected const TOP    = '┌';
    protected const BAR    = '│';
    protected const BOTTOM = '└';

    public PromptState $state = PromptState::Active;

    public string $error = '';

    /** When true, omit the left gutter so a presenter can draw its own frame. */
    public bool $bare = false;

    /** The concrete answer produced by the prompt. */
    abstract public function value(): mixed;

    /**
     * The styled rows to render for the current state.
     *
     * @return list<Line>
     */
    abstract protected function frame(): array;

    /**
     * The rendered rows for this prompt — exposed so composite components
     * can embed a prompt's frame in their own.
     *
     * @return list<Line>
     */
    public function frameLines(): array
    {
        return $this->frame();
    }

    /**
     * Why this prompt cannot be answered off a terminal, or null when it can.
     *
     * Most prompts carry a default and are therefore answerable by a
     * script: {@see \LucianoPereira\Crucible\CLI\Commands\InitCommand}
     * writes the same crucible.php with or without a TTY. One that was
     * given no default has nothing to fall back on, and returning its
     * first option would be an answer nobody gave — the message returned
     * here becomes the {@see NonInteractiveException} instead.
     */
    public function unanswerableWithoutTerminal(): ?string
    {
        return null;
    }

    /** Return the prompt to its active state (e.g. when re-focused in a form). */
    public function reactivate(): void
    {
        if ($this->state === PromptState::Submit || $this->state === PromptState::Error) {
            $this->state = PromptState::Active;
        }
    }

    public function measure(int $width): int
    {
        return max(1, count($this->frame()));
    }

    public function render(Buffer $buffer): void
    {
        foreach ($this->frame() as $y => $line) {
            $line->drawInto($buffer, 0, $y);
        }
    }

    public function handle(Event $event): void
    {
        if (! $event instanceof KeyEvent) {
            return;
        }

        if ($this->state === PromptState::Error) {
            $this->state = PromptState::Active;
        }

        $this->emit('key', $event->key);
    }

    public function isFinished(): bool
    {
        return $this->state === PromptState::Submit;
    }

    public function result(): mixed
    {
        return $this->value();
    }

    /** Validate the current value and, if valid, mark the prompt submitted. */
    protected function submit(): void
    {
        $this->validateValue($this->value());

        if ($this->state !== PromptState::Error) {
            $this->state = PromptState::Submit;
        }
    }

    protected function setError(string $message): void
    {
        $this->state = PromptState::Error;
        $this->error = $message;
    }

    // ---- Drawing helpers -------------------------------------------------

    protected function newLine(): Line
    {
        return new Line();
    }

    /** The opening row carrying the label. */
    protected function title(string $label): Line
    {
        $line = $this->newLine();

        if (! $this->bare) {
            $line->add(self::TOP . ' ', $this->stateStyle());
        }

        return $line->add($label, $this->bold());
    }

    /** A body row prefixed with the gutter bar. */
    protected function body(string $text = '', ?Style $style = null): Line
    {
        return $this->bar()->add($text, $style);
    }

    /** A body row whose content the caller fills with styled segments. */
    protected function bar(): Line
    {
        $line = $this->newLine();

        return $this->bare ? $line : $line->add(self::BAR . ' ', $this->stateStyle());
    }

    /** The closing row, showing an error, a cancellation note, or a hint. */
    protected function closing(string $hint = ''): Line
    {
        $line = $this->newLine();

        if (! $this->bare) {
            $line->add(self::BOTTOM, $this->stateStyle());
        }

        return match ($this->state) {
            PromptState::Cancel => $line->add($this->bare ? '' : ' ')->add('Cancelled.', $this->fg(Color::red())),
            PromptState::Error  => $line->add($this->bare ? '' : ' ')->add($this->error, $this->fg(Color::yellow())),
            default             => $hint === '' ? $line : $line->add($this->bare ? '' : ' ')->add($hint, $this->dim()),
        };
    }

    protected function stateStyle(): Style
    {
        return $this->fg(match ($this->state) {
            PromptState::Cancel => Color::red(),
            PromptState::Error  => Color::yellow(),
            PromptState::Submit => Color::green(),
            default             => Theme::accent(),
        });
    }

    /** The accent style used for pointers and active highlights. */
    protected function accent(): Style
    {
        return $this->fg(Theme::accent());
    }

    protected function fg(Color $color): Style
    {
        return Style::none()->withForeground($color);
    }

    protected function bold(): Style
    {
        return Style::none()->bold();
    }

    protected function dim(): Style
    {
        return Style::none()->dim();
    }

    /**
     * Append a value with a simulated caret at the given character position to
     * a line, since the real cursor is hidden while a prompt is active.
     */
    protected function appendCursor(Line $line, string $value, int $position, string $placeholder = ''): Line
    {
        $active = $this->state === PromptState::Active || $this->state === PromptState::Error;

        if (! $active) {
            return $line->add($value === '' ? $placeholder : $value, $value === '' ? $this->dim() : null);
        }

        if ($value === '' && $placeholder !== '') {
            return $line
                ->add(Str::substr($placeholder, 0, 1), $this->inverse())
                ->add(Str::substr($placeholder, 1), $this->dim());
        }

        $at = Str::substr($value, $position, 1);

        return $line
            ->add(Str::substr($value, 0, $position))
            ->add($at === '' ? ' ' : $at, $this->inverse())
            ->add(Str::substr($value, $position + 1));
    }

    protected function inverse(): Style
    {
        return Style::none()->inverse();
    }
}
