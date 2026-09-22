/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

import { retrigger } from "./crucible-retrigger.mjs";

/**
 * A Vite plugin that pushes each change to a running `crucible --watch`.
 *
 * Vite already resolved the module graph when `handleHotUpdate` fires —
 * it knows the changed file and everything the change reaches. That is
 * precisely the knowledge Crucible refuses to reconstruct from build
 * artifacts, so the plugin hands it over rather than leaving Crucible to
 * guess from a manifest.
 *
 *     import crucible from './integration/vite-plugin-crucible.mjs'
 *
 *     export default defineConfig({
 *         plugins: [vue(), crucible()],
 *     })
 *
 * Nothing here is Vue-specific, or even Vite-specific beyond the two
 * hook names: the wire format is documented in `integration/README.md`
 * and any producer can speak it.
 *
 * @param {{ hotFile?: string, modules?: boolean, build?: boolean }} [options]
 *   hotFile — where Crucible published its endpoint (default `.crucible.hot`)
 *   modules — also push the modules the change reaches, not just the file
 *   build   — also push on a completed production build
 */
export default function crucible(options = {}) {
    const { hotFile = ".crucible.hot", modules = false, build = true } = options;

    return {
        name: "crucible-retrigger",

        // Dev: an edit, with the affected module set Vite just computed.
        async handleHotUpdate(context) {
            const changed = [context.file];

            if (modules) {
                for (const module of context.modules ?? []) {
                    if (typeof module.file === "string" && module.file !== context.file) {
                        changed.push(module.file);
                    }
                }
            }

            await retrigger(changed, { hotFile });
        },

        // Build: the assets are new, so whatever renders them is in doubt.
        async writeBundle(_outputOptions, bundle) {
            if (!build) {
                return;
            }

            const changed = [];

            for (const chunk of Object.values(bundle ?? {})) {
                // `facadeModuleId` is the source this chunk came from —
                // a source path, not a hashed output name, which is the
                // only direction worth reporting.
                if (typeof chunk.facadeModuleId === "string") {
                    changed.push(chunk.facadeModuleId);
                }
            }

            await retrigger(changed, { hotFile });
        },
    };
}
