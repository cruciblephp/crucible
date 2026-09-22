<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

use function addcslashes;
use function sprintf;

/**
 * Reading a Livewire component's state out of the page (D-086).
 *
 * Livewire keeps the authoritative state in the DOM: every component
 * carries a `wire:snapshot` attribute, and the server rewrites it on
 * every round trip — verified against a real Livewire 4 app, where a
 * click moved the snapshot from `count:0` to `count:1` with a fresh
 * checksum. So unlike Inertia (D-085), which never updates its
 * `data-page` after a client-side visit and therefore needs a recorder
 * installed before boot, Livewire needs nothing installed at all.
 *
 * The asymmetry is worth stating plainly, because assuming the two
 * frameworks behave alike would produce a recorder nobody needs for one
 * of them and a silently stale read for the other.
 */
final readonly class LivewireSnapshot
{
    /**
     * The decoded snapshot of one component, or null when the page
     * carries none — which is a different answer from "the property did
     * not match", and the assertions keep them apart.
     *
     * @param ?string $component the component's name in its memo; null = the first on the page
     */
    public static function readExpression(?string $component = null): string
    {
        $selector = '[wire\\\\:snapshot]';

        return sprintf(
            <<<'JS_WRAP'
            (() => {
                const nodes = Array.from(document.querySelectorAll('%s'));
                const wanted = %s;
            
                for (const node of nodes) {
                    let snapshot;
            
                    try {
                        snapshot = JSON.parse(node.getAttribute('wire:snapshot'));
                    } catch (error) {
                        continue;
                    }
            
                    if (wanted === null || (snapshot.memo && snapshot.memo.name === wanted)) {
                        return snapshot;
                    }
                }
            
                return null;
            })()
            JS_WRAP,
            $selector,
            $component === null ? 'null' : sprintf("'%s'", addcslashes($component, "'\\")),
        );
    }
}
