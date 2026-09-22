<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Metadata;

use LucianoPereira\Crucible\Attributes\After;
use LucianoPereira\Crucible\Attributes\Before;
use LucianoPereira\Crucible\Attributes\PostCondition;
use LucianoPereira\Crucible\Attributes\PreCondition;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use ReflectionMethod;

/**
 * Real PHPUnit's own #[Before]/#[PreCondition]/#[PostCondition]/
 * #[After] (PHPUnit\Framework\Attributes\*) are a genuinely different
 * class from Crucible's own equivalents — not aliased when
 * phpunit/phpunit is actually installed (PhpUnitCompatibility::load()
 * only aliases when the real package is absent), so a class using the
 * real attribute directly (as Spatie\Snapshots\MatchesSnapshots does)
 * was never recognized by MetadataParser, which only matches
 * CrucibleAttribute-implementing classes. Verified against a real
 * phpunit/phpunit run first: a trait-provided,
 * #[PHPUnit\Framework\Attributes\PostCondition]-attributed method
 * fires normally there, then converted the same scenario to Crucible
 * and got a different result — a genuine gap, not fidelity to some
 * PHPUnit limitation.
 *
 * Isolated in its own file, excluded from phpstan (phpstan.neon), for
 * the same reason as src/Dialect/Pest/RealPhpUnitBootstrap.php:
 * compiles against PHPUnit\Framework\Attributes\* classes
 * deliberately not a dev-dependency of the engine.
 */
final class RealPhpUnitHookAttributes
{
    /**
     * Guarded on isLoaded(), not phpUnitIsInstalled() — when aliasing
     * is actually active (either auto/drop-in mode with the real
     * package absent, or explicit opt-in with it present),
     * PhpUnitCompatibility::load() already class_alias()es these very
     * names onto Crucible's own attribute classes, so
     * MetadataParser's existing CrucibleAttribute-instanceof scan
     * already catches them through the alias. Running this too in
     * that case would double-count every hook (matched once via the
     * alias, once here by exact name) — firing each hook method
     * twice; worse, with the alias active, reflecting on the
     * "real" name actually returns Crucible's own attribute
     * instance (that's what the alias points to), which lacks the
     * real one's priority() method — a guard keyed on
     * phpUnitIsInstalled() alone let that combination through and
     * crashed on the call.
     *
     * @return list<Before|PreCondition|PostCondition|After>
     */
    public static function forMethod(ReflectionMethod $method): array
    {
        if (PhpUnitCompatibility::isLoaded()) {
            return [];
        }

        $translated = [];

        foreach ($method->getAttributes(\PHPUnit\Framework\Attributes\Before::class) as $attribute) {
            $translated[] = new Before($attribute->newInstance()->priority());
        }

        foreach ($method->getAttributes(\PHPUnit\Framework\Attributes\PreCondition::class) as $attribute) {
            $translated[] = new PreCondition($attribute->newInstance()->priority());
        }

        foreach ($method->getAttributes(\PHPUnit\Framework\Attributes\PostCondition::class) as $attribute) {
            $translated[] = new PostCondition($attribute->newInstance()->priority());
        }

        foreach ($method->getAttributes(\PHPUnit\Framework\Attributes\After::class) as $attribute) {
            $translated[] = new After($attribute->newInstance()->priority());
        }

        return $translated;
    }
}
