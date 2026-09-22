<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Runtime;

use LucianoPereira\Crucible\Console\Components\Component;
use LucianoPereira\Crucible\Console\Components\Prompt;
use LucianoPereira\Crucible\Console\Exceptions\CancelledException;
use LucianoPereira\Crucible\Console\Exceptions\NonInteractiveException;
use LucianoPereira\Crucible\Console\Input\Key;
use LucianoPereira\Crucible\Console\Input\KeyEvent;
use LucianoPereira\Crucible\Console\Input\ResizeEvent;
use LucianoPereira\Crucible\Console\Screen\Buffer;
use LucianoPereira\Crucible\Console\Terminal\Terminal;

use function max;

/**
 * Runs a single component to completion: owns raw mode, the read/render loop,
 * cancellation and resize dispatch, then restores the terminal. This is the
 * whole "modal episode" — no persistent app state, no widget tree.
 */
final readonly class Program
{
    public function __construct(
        private Terminal $terminal,
        private Presenter $presenter,
    ) {}

    /** Run the component, returning its result — or throwing on cancellation, or on a prompt no script can answer. */
    public function run(Component $component): mixed
    {
        if (! $this->terminal->supportsInteractivity()) {
            $unanswerable = $component instanceof Prompt ? $component->unanswerableWithoutTerminal() : null;

            if ($unanswerable !== null) {
                throw new NonInteractiveException($unanswerable);
            }

            return $component->result();
        }

        $this->terminal->enableRawMode();
        $this->presenter->begin($this->terminal);
        $width = $this->presenter->viewportWidth($this->terminal);

        try {
            $this->draw($component);

            while (! $component->isFinished()) {
                $raw = $this->terminal->read();

                if ($raw === '') {
                    break;
                }

                if (Key::is($raw, Key::CTRL_C)) {
                    throw new CancelledException();
                }

                $width = $this->dispatchResize($component, $width);
                $component->handle(new KeyEvent($raw));
                $this->draw($component);
            }

            return $component->result();
        } finally {
            $this->presenter->finish($this->terminal);
            $this->terminal->restoreMode();
        }
    }

    private function dispatchResize(Component $component, int $width): int
    {
        $current = $this->presenter->viewportWidth($this->terminal);

        if ($current !== $width) {
            $component->handle(new ResizeEvent($current, $this->terminal->lines()));
        }

        return $current;
    }

    private function draw(Component $component): void
    {
        $width  = $this->presenter->viewportWidth($this->terminal);
        $height = max(1, $component->measure($width));

        $buffer = new Buffer($width, $height);
        $component->render($buffer);

        $this->presenter->present($this->terminal, $buffer);
    }
}
