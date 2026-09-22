<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

/**
 * The pest()->printer() chain (spec/pest-api.md §5, D-066): the one
 * documented selection is compact(). Crucible's default console IS the
 * compact dot printer, so the declaration's observable effect is
 * overriding a configured ->testdox() back to dots; the CLI --testdox
 * flag still wins — flags beat suite files, the D-023 precedence.
 */
final readonly class PrinterSelection
{
    public function compact(): self
    {
        PestScopes::selectPrinter('compact');

        return $this;
    }
}
