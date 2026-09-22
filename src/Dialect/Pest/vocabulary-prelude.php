<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Lets Crucible's pest dialect run in a project that still has
 * pestphp/pest installed. Loaded with `-d auto_prepend_file=`, never by
 * require: it has to execute BEFORE the entry script reaches
 * `vendor/autoload.php`, and nothing inside the process is early enough.
 *
 * Why this exists at all — the asymmetry with PHPUnit, measured:
 *
 *   PHPUnit's surface is CLASSES, so D-019 can alias them conditionally
 *   and stand down when the real package is present. Pest's surface is
 *   GLOBAL FUNCTIONS, delivered through Composer's `files` autoload
 *   (pestphp/pest/src/Functions.php and src/Pest.php). By the time any
 *   Crucible code runs they are already declared, and PHP has no
 *   function_alias(): redeclaring is an uncatchable fatal. So an
 *   in-process opt-in of the ->phpunitCompatibility() shape is not
 *   merely unimplemented, it is impossible.
 *
 * What is possible is not loading them in the first place. Composer's
 * autoload_real.php guards every `files` entry with
 * `if (empty($GLOBALS['__composer_autoload_files'][$hash]))`, so marking
 * a hash as already-included makes Composer skip that file. Marking only
 * Pest's two function files suppresses its globals and leaves every one
 * of its CLASSES autoloadable, which is what keeps the rest of the
 * project working.
 *
 * Measured: with this prepended, function_exists('test') and
 * function_exists('expect') are false while Pest\Expectation still
 * resolves. Nothing is patched, monkeyed or unregistered — Composer is
 * asked, through its own documented guard, not to include two files.
 *
 * The real package is never masked, which is the D-019 principle: its
 * functions are not replaced, they are never defined, and the process
 * that does this is a dedicated one Crucible spawned for the purpose.
 */

$vendor = \getenv('CRUCIBLE_PEST_VENDOR');

if (!\is_string($vendor) || $vendor === '') {
    $cwd    = \getcwd();
    $vendor = ($cwd === false ? '.' : $cwd) . '/vendor';
}

$manifest = $vendor . '/composer/autoload_files.php';

if (\is_file($manifest)) {
    /** @var mixed $entries */
    $entries = include $manifest;

    if (\is_array($entries)) {
        /** @var array<array-key, bool> $marked */
        $marked = \is_array($GLOBALS['__composer_autoload_files'] ?? null)
            ? $GLOBALS['__composer_autoload_files']
            : [];

        foreach ($entries as $hash => $path) {
            // Only the two function files. Plugin autoloads and every
            // other package's `files` entry are left alone: this is a
            // scalpel, not a blanket "skip vendor bootstrapping".
            if (\is_string($path) && \str_contains($path, '/pestphp/pest/src/')) {
                $marked[$hash] = true;
            }
        }

        $GLOBALS['__composer_autoload_files'] = $marked;
    }
}
