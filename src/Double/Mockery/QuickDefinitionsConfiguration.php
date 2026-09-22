<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

/**
 * The quick-definitions toggle (spec §15, probed both ways): stubs by
 * default; at-least-once mocks when asked.
 */
final class QuickDefinitionsConfiguration
{
    private bool $atLeastOnce = false;

    public function shouldBeCalledAtLeastOnce(bool $enabled): void
    {
        $this->atLeastOnce = $enabled;
    }

    public function definesAtLeastOnce(): bool
    {
        return $this->atLeastOnce;
    }
}
