<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

/**
 * A marker for one piece of report content — a heading, a list of
 * problems, a badge. Renderers dispatch on the concrete class
 * (`match (true) { $block instanceof Heading => ..., }`); this
 * interface exists so a `Document`'s block list has one shared type
 * to hold them, not to prescribe a rendering contract of its own.
 */
interface Block
{
    /**
     * A representative instance of this block — the single source
     * {@see SampleDocument} draws from to build a live, code-driven
     * template report. Each block owns its own example next to its
     * own code, so a new block type is automatically included the
     * moment it implements this, with no separate fixture to keep in
     * sync.
     */
    public static function sample(): self;
}
