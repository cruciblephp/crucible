<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Bridge\PestSnapshots;

use ReflectionClass;

use function trait_exists;

/**
 * Real Pest's own spatie/pest-plugin-snapshots calls
 * Plugin::uses(MatchesSnapshots::class) unconditionally at its
 * file-load time, mixing the trait into every uses() class without
 * the test file listing it explicitly. That plugin package can never
 * actually be installed alongside Crucible's pest dialect (D-019 —
 * it hard-requires pestphp/pest, like the Laravel and Livewire
 * plugins), but the trait's own package,
 * spatie/phpunit-snapshot-assertions, has no such dependency and is
 * genuinely standalone — a project that installs it directly to get
 * Spatie\Snapshots\* (functions.php in this same directory) needs
 * the same auto-mixing real Pest gives it. See
 * PestBuilder::build(), the only caller.
 */
final class AutoMixedTrait
{
    /**
     * Skipped for a final class: forcing composition there would
     * throw for every uses()'d final class
     * (TraitComposer::compose()'s own guard), not just the ones that
     * actually call a snapshot function.
     *
     * trait_exists(), not class_exists() — MatchesSnapshots is
     * declared `trait`, and class_exists() silently returns false
     * for a trait even when it's genuinely installed and
     * autoloadable. Found against the real spatie/laravel-data
     * benchmark: every Spatie\Snapshots\* call failed with "Method
     * ...::assertMatchesSnapshot() does not exist" despite
     * spatie/phpunit-snapshot-assertions being installed, because
     * this method silently never mixed the trait in at all.
     *
     * Also skipped when the target class already has a method
     * matching any of the trait's own names — Crucible's own
     * TestCase/Assert hierarchy declares a *static*
     * assertMatchesSnapshot() (its own, unrelated D-076 snapshot
     * feature), and mixing a trait whose method of the same name is
     * an *instance* method fatals: "Cannot make static method ...
     * non static". Found against uses(PestTestCase::class) — the
     * default uses() class for pest-dialect tests — with
     * spatie/phpunit-snapshot-assertions installed, before this
     * check existed. The same reasoning applies even without a
     * fatal: a same-name, same-staticness collision would just let
     * the class's own method silently win, leaving Spatie's version
     * permanently unreachable — no less broken for not crashing.
     *
     * @param ReflectionClass<object> $class
     *
     * @return list<class-string>
     */
    public static function forClass(ReflectionClass $class): array
    {
        if ($class->isFinal() || !trait_exists(\Spatie\Snapshots\MatchesSnapshots::class)) {
            return [];
        }

        $trait = new ReflectionClass(\Spatie\Snapshots\MatchesSnapshots::class);

        foreach ($trait->getMethods() as $method) {
            if ($class->hasMethod($method->getName())) {
                return [];
            }
        }

        return [\Spatie\Snapshots\MatchesSnapshots::class];
    }
}
