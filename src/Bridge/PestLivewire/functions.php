<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * A thin proxy for pestphp/pest-plugin-livewire's livewire() (growth
 * G2 follow-up). Unlike the Pest\Laravel surface, real Pest's own
 * InteractsWithLivewire::livewire() doesn't read $this at all — it's
 * `return Livewire\Livewire::test($name, $params);`, a direct static
 * facade call (verified against the real package source) — so this
 * needs no CurrentTest lookup, only livewire/livewire itself
 * installed in the consuming project.
 */

namespace Pest\Livewire;

use Livewire\Livewire;

use function function_exists;

if (!function_exists('Pest\Livewire\livewire')) {
    /** @param array<array-key, mixed> $params */
    function livewire(string $name, array $params = []): mixed
    {
        return Livewire::test($name, $params);
    }
}
