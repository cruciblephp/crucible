<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Terminal;

/**
 * Abstraction over the physical terminal: raw-mode toggling, reading key
 * presses, writing output and querying the viewport dimensions.
 *
 * Implementations must be safe to use even when no real TTY is attached;
 * {@see Terminal::supportsInteractivity()} reports whether interactive prompts
 * are possible.
 *
 * Adapted from lucianopereira/console (same author, MIT) for Crucible's own
 * interactive CLI parts (--init, crucible extensions) — vendored rather than
 * required, so "PHP 8.3+ and ext-mbstring, nothing else at runtime" stays
 * true. Scoped to what Crucible actually uses: the inline presentation path
 * only (no boxed/centered dialogs), text/confirm/select prompts, a spinner,
 * a progress bar, and a table — not console's full component catalog.
 */
interface Terminal
{
    /** Put the terminal into raw (character-at-a-time, no-echo) mode. */
    public function enableRawMode(): void;

    /** Restore the terminal to the mode captured before {@see enableRawMode()}. */
    public function restoreMode(): void;

    /**
     * Read the next chunk of input. Blocks until at least one byte is
     * available. Returns an empty string only when the stream is exhausted.
     */
    public function read(): string;

    public function write(string $text): void;

    public function writeError(string $text): void;

    /** The number of visible columns in the viewport. */
    public function columns(): int;

    /** The number of visible rows in the viewport. */
    public function lines(): int;

    public function supportsInteractivity(): bool;
}
