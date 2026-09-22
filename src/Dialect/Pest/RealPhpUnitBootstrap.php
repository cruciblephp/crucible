<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use PHPUnit\Framework\Assert as RealAssert;
use PHPUnit\TextUI\CliArguments\Builder as CliArgumentsBuilder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use Throwable;

use function class_exists;
use function method_exists;

/**
 * A uses() class can be a real PHPUnit\Framework\TestCase subclass —
 * Orchestra Testbench's, for instance, confirmed against
 * spatie/laravel-data's real Pest suite. Its setUp() (and PHPUnit's
 * own TestCase internals that setUp() can reach, e.g. backupGlobals
 * handling) reads PHPUnit\TextUI\Configuration\Registry, a
 * process-wide singleton real PHPUnit's own CLI entry point
 * initializes before running anything. Crucible never runs that entry
 * point, so without this the singleton stays unset and every such
 * test errors before its body even starts. Filled with PHPUnit's own
 * defaults (empty CLI arguments, no XML file) — Crucible has no
 * phpunit.xml opinions of its own to feed it, and this exists only so
 * PHPUnit's internals stop asserting on a missing value, not to
 * change PHPUnit's behavior.
 *
 * Reaches into `@internal`, no-BC-promise PHPUnit classes — there is
 * no supported alternative, since this is normally the TextUI
 * entry point's own job. Best-effort and defensive: a future PHPUnit
 * release reshaping this is a test that errors with its own real
 * message, same as before this existed, not a broken process.
 */
final class RealPhpUnitBootstrap
{
    private static bool $attempted = false;

    public static function ensureConfigured(): void
    {
        if (self::$attempted) {
            return;
        }

        self::$attempted = true;

        if (!class_exists(Registry::class)
            || !class_exists(CliArgumentsBuilder::class)
            || !class_exists(DefaultConfiguration::class)
        ) {
            return;
        }

        try {
            Registry::init((new CliArgumentsBuilder())->fromParameters([]), DefaultConfiguration::create());
        } catch (Throwable) {
            // Best-effort, see class docblock.
        }
    }

    /**
     * Real PHPUnit's own assertTrue()/assertSame()/etc. are static
     * methods (final on Assert, inherited by TestCase) — they count
     * through a process-wide static counter on PHPUnit\Framework\Assert,
     * not through the instance's own numberOfAssertionsPerformed().
     * That static counter only ever reaches the instance because
     * PHPUnit's own TestRunner::run() bridges it explicitly
     * (`$test->addToAssertionCount(Assert::getCount())`, called once
     * after the whole test — setUp, body, and tearDown — finishes).
     * Crucible never runs through TestRunner, so without mirroring that
     * bridge here, every `$this->assertSame(...)`-style call inside a
     * real-PHPUnit-descended uses() class vanishes: invisible to
     * Crucible's own counter and wrongly flagged risky. Reset before
     * setUp() the same way TestRunner resets before runBare(), since
     * the static counter is never otherwise cleared between tests and
     * would leak counts across every test in the process.
     *
     * Gated on PhpUnitCompatibility::phpUnitIsInstalled(), not merely
     * class_exists(RealAssert::class) — when the real package is
     * absent, Crucible's own drop-in compatibility layer
     * (PhpUnitCompatibility::load()) class_alias()es this very name to
     * Crucible's own Assert, which has no resetCount()/getCount(). A
     * bare class_exists() check would see the alias and mistake it for
     * the real thing.
     */
    public static function resetAssertionCount(): void
    {
        if (PhpUnitCompatibility::phpUnitIsInstalled() && class_exists(RealAssert::class)) {
            RealAssert::resetCount();
        }
    }

    /**
     * The other half of the bridge described on resetAssertionCount():
     * call once the test (setUp, body, tearDown) has fully finished,
     * mirroring TestRunner::run()'s own
     * `$test->addToAssertionCount(Assert::getCount())` call site.
     */
    public static function bridgeAssertionCount(object $instance): void
    {
        if (!PhpUnitCompatibility::phpUnitIsInstalled()
            || !class_exists(RealAssert::class)
            || !method_exists($instance, 'addToAssertionCount')
        ) {
            return;
        }

        $instance->addToAssertionCount(RealAssert::getCount());
    }
}
