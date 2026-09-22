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
use LucianoPereira\Crucible\Console\Runtime\ForkedAnimation;
use LucianoPereira\Crucible\Console\Style\Brand;
use LucianoPereira\Crucible\Console\Style\Color;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Support\Sequence;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\Terminal;

use function abs;
use function count;
use function max;
use function usleep;

use const PHP_EOL;

/**
 * The wordmark, lit one character at a time.
 *
 * A bright head sweeps the text and bounces off each end, with one step
 * of falloff behind it and the rest unlit — the whole animation is three
 * colours and a position. Adapted from the project's own draft; the
 * colours come from {@see Brand}, which reads them off the logo.
 *
 * **It animates only where animation is watched.** A run piped to a file
 * or a CI log gets the wordmark once, in one colour, with no carriage
 * returns and no cursor control — ✓ the thing that makes an animated
 * banner unbearable is a log full of `\r` frames, and
 * {@see Capabilities::animation()} is the same gate {@see Spinner} uses.
 *
 * Two ways to run it, because a banner has two jobs:
 *
 * - {@see begin()} and {@see settle()} sweep in a forked child while the
 *   parent gets on with loading the configuration and discovering the
 *   suite. ⚠ This is the one that belongs in front of real work: the
 *   animation costs the run nothing, because the run never waits for it.
 * - {@see render()} sweeps synchronously for a fixed number of frames,
 *   for `crucible --version`, where the banner IS the command and there
 *   is nothing to overlap it with.
 *
 * The frame is built from graphemes, not bytes, so a wordmark with a
 * wide or combining character stays aligned: {@see Str::graphemes()}
 * splits it and each cell is coloured whole.
 */
final class Splash
{
    /** Milliseconds between frames. */
    public int $interval = 110;

    private ?ForkedAnimation $animation = null;

    /** Round trips before the sweep settles. */
    public int $sweeps = 2;

    /** Pre-styled text drawn after the wordmark, on the same line, unswept. */
    public string $suffix = '';

    public function __construct(
        private readonly Terminal $terminal,
        private readonly string $wordmark = 'cruciblephp',
    ) {}

    /**
     * Sweep the wordmark, then leave it lit on its own line.
     *
     * Returns the number of frames written, which is what a caller — or
     * a test — can check without measuring time.
     */
    public function render(): int
    {
        $cells = Str::graphemes($this->wordmark);

        if ($cells === []) {
            return 0;
        }

        if (!Capabilities::animation($this->terminal)) {
            $this->terminal->write($this->paint($cells, -1) . PHP_EOL);

            return 0;
        }

        $this->terminal->write(Sequence::HideCursor->render());

        $frames = 0;
        $last   = count($cells) - 1;
        $head   = 0;
        $step   = $last === 0 ? 0 : 1;
        $turns  = 0;

        // Bounded by frames, not only by turns.
        //
        // ⚠ Found by `crucible mutate` 2026-09-20: flipping the `||` in
        // the bounce guard below to `&&` makes it never true, so $step
        // never reverses and $turns never advances — the loop does not
        // end. The test's terminal accumulates every frame, so that ran
        // at ~130 MiB/s until the OOM killer took the whole run. The
        // mutant is what broke it; an exit that depends on two coupled
        // variables agreeing is what let it.
        //
        // A sweep cannot legitimately exceed cells * turns frames, so
        // this ceiling is unreachable when the guard is correct and
        // immediate when it is not.
        $ceiling = count($cells) * max(1, $this->sweeps) * 2 + 1;

        while ($turns < max(1, $this->sweeps) * 2 && $frames < $ceiling) {
            $this->terminal->write("\r" . $this->paint($cells, $head));
            $frames++;

            if ($step === 0) {
                break;
            }

            $head += $step;

            if ($head >= $last || $head <= 0) {
                $step *= -1;
                $turns++;
            }

            usleep($this->interval * 1000);
        }

        $this->terminal->write(
            "\r" . $this->paint($cells, -1) . Sequence::ShowCursor->render() . PHP_EOL,
        );

        return $frames;
    }

    /**
     * Start sweeping in a forked child and return immediately.
     *
     * False means nothing is animating and the settled line has already
     * been written — a caller still pairs this with {@see settle()},
     * which then does nothing.
     */
    public function begin(): bool
    {
        $cells = Str::graphemes($this->wordmark);

        if ($cells === []) {
            return false;
        }

        if (!Capabilities::animation($this->terminal)) {
            $this->terminal->write($this->paint($cells, -1) . PHP_EOL);

            return false;
        }

        $animation = new ForkedAnimation($this->terminal, $this->frameAt(...), $this->interval);

        if (!$animation->start()) {
            $this->terminal->write($this->paint($cells, -1) . PHP_EOL);

            return false;
        }

        $this->animation = $animation;

        return true;
    }

    /**
     * Sweep the wordmark while the callback runs, then clear the line.
     *
     * ⚠ This is the one that actually animates. {@see begin()} covers the
     * gap before the first line of output, and on a warm checkout that gap
     * is tens of milliseconds — nothing to sweep over. Discovering a suite
     * is seconds, so the mark is put THERE, over work that exists, and the
     * run still waits exactly as long as the work takes.
     *
     * Leaves nothing behind: the banner has already been printed above, so
     * a second settled wordmark would just be the same line twice.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $work
     *
     * @return TReturn
     */
    public function sweepWhile(Closure $work): mixed
    {
        $cells = Str::graphemes($this->wordmark);

        if ($cells === [] || !Capabilities::animation($this->terminal)) {
            return $work();
        }

        $animation = new ForkedAnimation($this->terminal, $this->frameAt(...), $this->interval);

        if (!$animation->start()) {
            return $work();
        }

        try {
            return $work();
        } finally {
            $animation->stop();
        }
    }

    /**
     * The frame for tick N: what the forked animation asks for each time.
     *
     * Public because it is the whole sweep as a pure function of one
     * integer — a test reads the bounce off it without forking a process
     * or measuring a clock.
     */
    public function frameAt(int $tick): string
    {
        $cells = Str::graphemes($this->wordmark);

        return $this->paint($cells, $this->headAt(count($cells), $tick));
    }

    /** Stop the sweep and leave the wordmark lit on its own line. Idempotent. */
    public function settle(): void
    {
        if (!$this->animation instanceof ForkedAnimation) {
            return;
        }

        $this->animation->stop();
        $this->animation = null;

        $this->terminal->write("\r" . $this->paint(Str::graphemes($this->wordmark), -1) . PHP_EOL);
    }

    /**
     * The lit cell at tick N: a triangle wave over the cells.
     *
     * The bounce as arithmetic rather than a mutable step and turn count.
     * ⚠ The stateful form is what a mutant turned into a loop with no
     * exit; there is no state here to get the two variables disagreeing.
     */
    private function headAt(int $cells, int $tick): int
    {
        if ($cells <= 1) {
            return 0;
        }

        $period = 2 * ($cells - 1);
        $phase  = $tick % $period;

        return $phase < $cells ? $phase : $period - $phase;
    }

    /**
     * One frame: $head is the lit cell, or -1 to light every cell.
     *
     * -1 is the resting state rather than a separate renderer, so the
     * settled line and the animated ones cannot drift apart in spacing
     * or colour.
     *
     * @param list<string> $cells
     */
    private function paint(array $cells, int $head): string
    {
        $frame = '';

        foreach ($cells as $index => $cell) {
            $distance = $head < 0 ? 0 : abs($index - $head);

            $frame .= $this->sgr(match (true) {
                $distance === 0 => Brand::ember(),
                $distance === 1 => Brand::flame(),
                default         => Brand::slate(),
            }) . $cell;
        }

        return $frame . $this->sgr(null) . $this->suffix;
    }

    /**
     * A foreground colour as an SGR sequence, or the reset when null.
     *
     * ⚠ Through Style, which is the same idiom {@see Spinner::line()} uses.
     * This method first emitted its own `\e[...m` from
     * `Color::forPalette()->foregroundParams()`, and took a Palette
     * constructor parameter to feed it — reimplementing Style::toAnsi()
     * and re-deciding what Theme already decides globally. Written while
     * removing 75 copies of one annotation, which is the joke.
     */
    private function sgr(?Color $color): string
    {
        if (!$color instanceof Color) {
            return "\e[0m";
        }

        return Style::none()->withForeground($color)->toAnsi();
    }
}
