<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

/**
 * The client-side half of Inertia awareness (D-085): a recorder
 * installed **before the application boots**, and the expression that
 * reads what it saw.
 *
 * Inertia keeps the current page in its client adapter, not in the DOM:
 * `data-page` carries only the payload the server rendered, and a
 * client-side visit never updates it. What the library does guarantee
 * is a documented DOM event on every visit — `inertia:navigate` and
 * `inertia:success` both carry `detail.page` — so the current page is
 * observable without the application cooperating, and without Crucible
 * reaching into the adapter's internals.
 *
 * It has to be installed early for the ordinary reason: a visit that
 * completes before an assertion subscribes has already fired its event,
 * and a listener added afterwards would see nothing. Reading the
 * initial page from the DOM is therefore the fallback, not the
 * mechanism — it is what is true before any visit has happened.
 */
final readonly class InertiaRecorder
{
    /**
     * Runs in every page of the context before the page's own scripts.
     * Deliberately inert when Inertia is absent: it adds two listeners
     * that never fire and one small object, so installing it always is
     * cheaper than deciding whether to.
     */
    public static function script(): string
    {
        return <<<'JS'
            (() => {
                const store = { page: null };
                window.__crucibleInertia = store;

                const capture = (event) => {
                    const page = event && event.detail ? event.detail.page : null;
                    if (page) {
                        store.page = page;
                    }
                };

                document.addEventListener('inertia:navigate', capture);
                document.addEventListener('inertia:success', capture);
            })();
            JS;
    }

    /**
     * The current page object, or null when this is not an Inertia page.
     *
     * The recorder wins when it has seen a visit; otherwise the DOM's
     * `data-page` is read, which is exactly the state the server
     * rendered — the same source Inertia's own `getInitialPageFromDOM`
     * uses to boot.
     */
    public static function readExpression(): string
    {
        return <<<'JS_WRAP'
        (() => {
            const store = window.__crucibleInertia;
            if (store && store.page) {
                return store.page;
            }
            const element = document.querySelector('#app[data-page], [data-page]');
            if (!element) {
                return null;
            }
            try {
                return JSON.parse(element.getAttribute('data-page'));
            } catch (error) {
                return null;
            }
        })()
        JS_WRAP;
    }
}
