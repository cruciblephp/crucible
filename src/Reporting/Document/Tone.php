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
 * A semantic emphasis a `Badge` or `ProblemList` entry carries,
 * decoupled from any literal color — each renderer maps a tone to its
 * own visual (PDF: an RGB fill, Console: an ANSI code, Markdown: bold
 * text, no color at all). Named for what the content means, not how
 * any one format shows it — lrv's `Document`/`Block` model has no
 * such concept at all (D-091's addendum); this is new for Crucible.
 */
enum Tone
{
    case Success;
    case Caution;
    case Danger;
    case Muted;
}
