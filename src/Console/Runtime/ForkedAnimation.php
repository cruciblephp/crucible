<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Runtime;

use Closure;
use LucianoPereira\Crucible\Console\Support\Sequence;
use LucianoPereira\Crucible\Console\Terminal\Terminal;
use RuntimeException;

use function defined;
use function function_exists;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function register_shutdown_function;
use function usleep;

use const SIGTERM;

/**
 * An animation that keeps drawing while the parent process blocks.
 *
 * PHP has no second thread to tick on, and the work worth animating over —
 * parsing a scope, discovering a suite, rendering a report — never yields.
 * So the frames are drawn from a forked child and the parent returns
 * immediately: the animation costs the run nothing but a fork.
 *
 * ⚠ Two hazards, both of them the kind that take the whole machine with
 * them, and both guarded below rather than documented away:
 *
 * - A failed fork returns -1, and `posix_kill(-1, …)` signals **every
 *   process the user owns**. Nothing here may reach {@see stop()} with a
 *   pid that did not come from a fork that worked.
 * - A child that escapes its loop would return into the parent's call
 *   stack and run the rest of the program a second time. The loop cannot
 *   exit, and the child re-checks its own pid before writing anyway.
 */
final class ForkedAnimation
{
    /** No child: nothing was forked, or the child has been reaped. */
    private const int NONE = -1;

    private int $pid = self::NONE;

    private int $parent = self::NONE;

    /**
     * @param Closure(int): string $frame the frame for tick N, ready to write
     * @param int                  $interval milliseconds between frames
     */
    /**
     * @param Closure(int): string $frame
     * @param bool                 $owns  the frame writes the whole surface itself, so
     *                                    nothing here prefixes a carriage return, erases
     *                                    a line, or touches the cursor — the owner hid it
     *                                    and the owner is the one that shows it again
     */
    public function __construct(
        private readonly Terminal $terminal,
        private readonly Closure $frame,
        private readonly int $interval,
        private readonly bool $owns = false,
    ) {}

    /** Whether this platform can fork and signal at all. */
    public static function isSupported(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill')
            && function_exists('posix_getpid');
    }

    /** Begin animating. False means the caller must draw something static instead. */
    public function start(): bool
    {
        if ($this->pid !== self::NONE || !self::isSupported()) {
            return false;
        }

        $this->parent = posix_getpid();

        if (!$this->owns) {
            $this->terminal->write(Sequence::HideCursor->render());
        }

        $pid = pcntl_fork();

        if ($pid === 0) {
            $this->animate();
        }

        if ($pid === self::NONE) {
            if (!$this->owns) {
                $this->terminal->write(Sequence::ShowCursor->render());
            }

            $this->parent = self::NONE;

            return false;
        }

        $this->pid = $pid;

        // ⚠ The child outlives a parent that forgets to stop it, and an
        // orphan that writes to the terminal forever is worse than no
        // animation. Every return path is covered by this, including the
        // ones that exit before the caller's own stop().
        register_shutdown_function($this->stop(...));

        return true;
    }

    /** Stop the child and clear the line it was drawing on. Safe to call twice. */
    public function stop(): void
    {
        if ($this->pid === self::NONE) {
            return;
        }

        posix_kill($this->pid, defined('SIGTERM') ? SIGTERM : 15);

        $status = 0;
        pcntl_waitpid($this->pid, $status);

        $this->pid = self::NONE;

        // ⚠ Nothing at all when the caller owns the surface. Showing the
        // cursor here left it blinking at the frame's top-left corner for
        // the whole run, which reads as something having jumped there.
        if (!$this->owns) {
            $this->terminal->write("\r" . Sequence::EraseLine->render() . Sequence::ShowCursor->render());
        }
    }

    /**
     * The child's whole life: draw, sleep, repeat, and never return.
     *
     * Declared `never` so a future edit that adds a `break` cannot compile
     * into a child that falls back into the parent's call stack.
     */
    private function animate(): never
    {
        $tick = 0;

        while (true) {
            // ⚠ The parent must never reach this. It does not today, but a
            // mutation of the `$pid === 0` above put a whole mutation run's
            // parent into a child's branch once, so the child confirms it
            // is the child before it writes anything.
            if (posix_getpid() === $this->parent) {
                throw new RuntimeException('The parent process reached the animation child.');
            }

            $frame = ($this->frame)($tick);

            $this->terminal->write($this->owns ? $frame : "\r" . Sequence::EraseLine->render() . $frame);

            $tick++;
            usleep($this->interval * 1000);
        }
    }
}
