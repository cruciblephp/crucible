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
 * A flat sequence of blocks — a report's content, independent of
 * whatever format it ends up rendered as. Each reporter builds its
 * own `Document` from `RunModel` (D-091) and its own judgment about
 * what belongs in it; there is no single shared "build the document"
 * function, matching how content producers build their own tree in
 * the lrv pattern this is modeled on (D-091's addendum).
 */
final readonly class Document
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {}
}
