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
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\Terminal;
use LucianoPereira\Crucible\Console\Terminal\TerminalFactory;

/**
 * The process-wide entry point for running components.
 *
 * Holds the active {@see Terminal} (swappable for tests) and the default
 * {@see Presenter}, and runs a component to completion via a {@see Program}.
 */
final class Runtime
{
    private static ?Terminal $terminal = null;

    private static ?Presenter $presenter = null;

    private function __construct() {}

    public static function terminal(): Terminal
    {
        return self::$terminal ??= TerminalFactory::make();
    }

    public static function setTerminal(?Terminal $terminal): void
    {
        self::$terminal = $terminal;
    }

    /** Override the presenter. */
    public static function setPresenter(?Presenter $presenter): void
    {
        self::$presenter = $presenter;
    }

    public static function presenter(): Presenter
    {
        return self::$presenter ?? new InlinePresenter(Capabilities::color(self::terminal()));
    }

    /** Run a component to completion and return its result. */
    public static function run(Component $component): mixed
    {
        $presenter = self::presenter();

        if ($presenter->wrapsContent() && $component instanceof Prompt) {
            $component->bare = true;
        }

        return (new Program(self::terminal(), $presenter))->run($component);
    }

    /** Reset all global state. Primarily useful between tests. */
    public static function reset(): void
    {
        self::$terminal  = null;
        self::$presenter = null;
    }
}
