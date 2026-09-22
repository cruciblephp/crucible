<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Components;

use Closure;
use LucianoPereira\Crucible\Console\Runtime\InlinePresenter;
use LucianoPereira\Crucible\Console\Runtime\Presenter;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Screen\Buffer;
use LucianoPereira\Crucible\Console\Screen\Line;
use LucianoPereira\Crucible\Console\Style\Color;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\Terminal;

use function count;
use function is_countable;
use function max;
use function min;
use function round;
use function str_repeat;

use const PHP_EOL;

/**
 * A determinate progress bar that redraws itself in place as work advances.
 *
 * Only at a terminal: {@see start()} is a no-op without one, so every method
 * below stays callable and draws nothing. A caller that also has findings to
 * report checks {@see isActive()} and falls back to plain lines, which is what
 * keeps `crucible mutate` legible in a CI log.
 */
final class Progress
{
    private const string TOP    = '┌';
    private const string BAR    = '│';
    private const string BOTTOM = '└';

    /** The bar's preferred width; it shrinks when the viewport cannot hold it. */
    private const int WIDTH = 40;

    /** Columns the gutter and the percentage take beside the bar. */
    private const int CHROME = 8;

    public int $current = 0;

    public string $hint = '';

    private readonly Terminal $terminal;

    private readonly Presenter $presenter;

    private bool $active = false;

    public function __construct(
        public readonly string $label,
        public readonly int $total,
    ) {
        $this->terminal  = Runtime::terminal();
        $this->presenter = new InlinePresenter(Capabilities::color($this->terminal));
    }

    /**
     * Iterate a set of items, advancing once per item and returning the
     * callback results.
     *
     * @template TItem
     * @template TReturn
     *
     * @param iterable<TItem> $items
     * @param Closure(TItem): TReturn $callback
     *
     * @return list<TReturn>
     */
    public static function each(string $label, iterable $items, Closure $callback, ?int $total = null): array
    {
        $total ??= is_countable($items) ? count($items) : 0;

        $progress = new self($label, $total);
        $progress->start();

        $results = [];

        foreach ($items as $item) {
            $results[] = $callback($item);
            $progress->advance();
        }

        $progress->finish();

        return $results;
    }

    public function start(): void
    {
        if (! Capabilities::animation($this->terminal)) {
            return;
        }

        $this->active = true;
        $this->presenter->begin($this->terminal);
        $this->draw();
    }

    public function advance(int $step = 1): void
    {
        $this->current = max(0, min($this->total, $this->current + $step));

        if ($this->active) {
            $this->draw();
        }
    }

    public function hint(string $hint): self
    {
        $this->hint = $hint;

        if ($this->active) {
            $this->draw();
        }

        return $this;
    }

    public function finish(): void
    {
        $this->current = $this->total;

        if ($this->active) {
            $this->draw();
            $this->presenter->finish($this->terminal);
            $this->active = false;
        }
    }

    /**
     * Write a line into the scrollback, keeping the bar below it.
     *
     * Without a bar on screen this is just a line, which is what makes it
     * safe to call from a run that may or may not be interactive.
     */
    public function interrupt(string $line): void
    {
        if (! $this->active) {
            $this->terminal->write($line . PHP_EOL);

            return;
        }

        $this->presenter->interrupt($this->terminal, $line);
        $this->draw();
    }

    /** Whether a bar is actually on screen. */
    public function isActive(): bool
    {
        return $this->active;
    }

    public function ratio(): float
    {
        return $this->total <= 0 ? 1.0 : min(1.0, $this->current / $this->total);
    }

    public function percent(): int
    {
        return (int) round($this->ratio() * 100);
    }

    private function draw(): void
    {
        $width  = $this->presenter->viewportWidth($this->terminal);
        $buffer = new Buffer($width, 3);

        $accent = Style::none()->withForeground(Theme::accent());

        // The hint is what a long run actually reads — counts and an ETA —
        // so the bar yields columns to it rather than pushing it off the
        // edge. A fixed 40 overflowed any viewport under ~90 columns.
        $hint     = $this->hint === '' ? 0 : Str::width($this->hint) + 2;
        $barWidth = max(4, min(self::WIDTH, $width - self::CHROME - $hint));
        $filled   = (int) round($this->ratio() * $barWidth);

        $title = (new Line())->add(self::TOP . ' ', $accent)->add($this->label, Style::none()->bold());

        $bar = (new Line())
            ->add(self::BAR . ' ', $accent)
            ->add(str_repeat('█', $filled), Style::none()->withForeground(Color::green()))
            ->add(str_repeat('░', $barWidth - $filled), Style::none()->dim())
            ->add('  ')
            ->add($this->percent() . '%', Style::none()->bold());

        if ($this->hint !== '') {
            $bar->add('  ')->add($this->hint, Style::none()->dim());
        }

        $closing = (new Line())->add(self::BOTTOM, $accent);

        $title->drawInto($buffer, 0, 0);
        $bar->drawInto($buffer, 0, 1);
        $closing->drawInto($buffer, 0, 2);

        $this->presenter->present($this->terminal, $buffer);
    }
}
