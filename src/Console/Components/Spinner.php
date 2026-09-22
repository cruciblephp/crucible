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
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\Terminal;

use function count;

use const PHP_EOL;

/**
 * An indeterminate spinner shown while a long-running callback executes.
 *
 * When the `pcntl` extension is available the animation runs in a forked child
 * process so it keeps ticking during blocking work; otherwise it degrades
 * gracefully to a single static line. The fork is the point: the work it
 * covers is synchronous and never yields, so nothing in-process could tick.
 */
final class Spinner
{
    /**
     * Animation frames: the project's own mark, filling and emptying.
     *
     * From the drafts in assets/ — a diamond alternating solid and hollow
     * through a four-pointed star. Public, so a caller that wants the
     * braille orbit or anything else just assigns it.
     *
     * @var list<string>
     */
    public array $frames = ['◆', '✦', '◇', '✦'];

    /** The frame shown when animation is unavailable (no TTY / no pcntl). */
    public string $staticFrame = '◆';

    public int $frameIndex = 0;

    /** Milliseconds between animation frames. */
    public int $interval = 75;

    private readonly Terminal $terminal;

    public function __construct(
        public readonly string $message = '',
    ) {
        $this->terminal = Runtime::terminal();
    }

    /**
     * Run the callback while animating, returning its result.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     *
     * @return TReturn
     */
    public function spin(Closure $callback): mixed
    {
        if (! Capabilities::animation($this->terminal)) {
            return $this->spinStatically($callback);
        }

        $animation = new ForkedAnimation($this->terminal, $this->frameAt(...), $this->interval);

        if (! $animation->start()) {
            return $this->spinStatically($callback);
        }

        try {
            return $callback();
        } finally {
            $animation->stop();
        }
    }

    /** The frame for tick N, which is what the forked animation asks for. */
    private function frameAt(int $tick): string
    {
        $this->frameIndex = $tick;

        return $this->frame();
    }

    private function frame(): string
    {
        return $this->line($this->frames[$this->frameIndex % count($this->frames)]);
    }

    /** Render a spinner symbol (accent-coloured) followed by the message. */
    private function line(string $symbol): string
    {
        if (Capabilities::color($this->terminal)) {
            $symbol = Style::none()->withForeground(Theme::accent())->toAnsi() . $symbol . "\e[0m";
        }

        return $this->message === '' ? $symbol : $symbol . ' ' . $this->message;
    }

    /**
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     *
     * @return TReturn
     */
    private function spinStatically(Closure $callback): mixed
    {
        // ⚠ Only where someone is waiting. A pipe, a CI log and a
        // captured fixture all get nothing at all: a spinner that cannot
        // spin has no business adding a line to output that is being
        // compared, redirected or read back later.
        if ($this->message !== '' && $this->terminal->supportsInteractivity()) {
            $this->terminal->write($this->line($this->staticFrame) . PHP_EOL);
        }

        return $callback();
    }

}
