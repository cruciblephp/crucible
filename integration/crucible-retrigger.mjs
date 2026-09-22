/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/**
 * Tell a running `crucible --watch` which files changed (D-083).
 *
 * Crucible cannot map a built asset back to a source file without owning a
 * bundler's knowledge, and guessing would silently run too few tests. So
 * whoever already knows — a bundler plugin, a build step, a git hook —
 * says it instead. Crucible's declared impact rules turn those paths into
 * the test groups they put in doubt.
 *
 * Dependency-free on purpose: no package, no install, nothing to keep in
 * sync with a version of Crucible. Copy this file if that is easier.
 */

/**
 * Read the endpoint Crucible published, or null when it is not listening.
 *
 * The hot file's presence IS the liveness signal — the same contract a
 * Vite dev server uses — so a producer never needs to be told twice
 * whether to bother.
 *
 * @param {string} [hotFile] path to the hot file, defaulting to `.crucible.hot`
 * @returns {string | null} the full URL including its token
 */
export function endpoint(hotFile = ".crucible.hot") {
    try {
        const url = readFileSync(resolve(hotFile), "utf8").trim();

        return url === "" ? null : url;
    } catch {
        // No file, no permission, no listener. All the same answer.
        return null;
    }
}

/**
 * Push a change set. Never throws and never blocks a build: a watch
 * session that is not running, or has just exited, is the normal case,
 * not an error worth failing a bundle over.
 *
 * @param {string[]} changed  paths, absolute or relative to the project root
 * @param {{ hotFile?: string, timeoutMs?: number }} [options]
 * @returns {Promise<boolean>} whether Crucible accepted the push
 */
export async function retrigger(changed, options = {}) {
    const { hotFile = ".crucible.hot", timeoutMs = 1000 } = options;

    if (!Array.isArray(changed) || changed.length === 0) {
        return false;
    }

    const url = endpoint(hotFile);

    if (url === null) {
        return false;
    }

    const abort = AbortSignal.timeout(timeoutMs);

    try {
        const response = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ changed }),
            signal: abort,
        });

        return response.ok;
    } catch {
        return false;
    }
}
