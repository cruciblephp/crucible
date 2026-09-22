<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use LucianoPereira\Crucible\Dialect\Pest\RealPhpUnitBootstrap;
use LucianoPereira\Crucible\Framework\TestCase;
use ReflectionProperty;

use function class_exists;

/**
 * The bridge to a real PHPUnit that is not installed here.
 *
 * Every method is gated on the real package being present, so in this
 * repository each one is a no-op — and that is exactly what is worth
 * pinning, because the gate is subtle enough to have been written the
 * obvious wrong way.
 */
#[CoversClass(RealPhpUnitBootstrap::class)]
final class RealPhpUnitBootstrapTest extends TestCase
{
    /**
     * ✓ Measured: with the drop-in aliases loaded — which is this
     * suite's own configuration, since phpunit/phpunit is absent and
     * the auto policy aliases — `class_exists(PHPUnit\Framework\Assert)`
     * is TRUE while the real package is nowhere. A guard written as
     * `class_exists(RealAssert::class)` alone would then call
     * `resetCount()` on Crucible's own Assert and get
     * `Error: Call to undefined method ...\Assert::resetCount()`.
     * The gate is `phpUnitIsInstalled()` for that reason.
     */
    public function testTheDropInAliasIsNotMistakenForTheRealPackage(): void
    {
        self::assertFalse(PhpUnitCompatibility::phpUnitIsInstalled(), 'this repository ships no phpunit/phpunit');

        if (class_exists(\PHPUnit\Framework\Assert::class, false)) {
            // The trap is live in this process: the name resolves, the
            // package does not exist, and the method behind it does not
            // either. Reaching the next line is the assertion.
            RealPhpUnitBootstrap::resetAssertionCount();
        }

        RealPhpUnitBootstrap::resetAssertionCount();

        self::assertTrue(true, 'resetting must be a no-op, never a call into an aliased class');
    }

    public function testBridgingLeavesTheInstanceAloneWhenTheRealPackageIsAbsent(): void
    {
        // Carries the method PHPUnit's own TestRunner would call, so a
        // gate that only checked method_exists() would fire here.
        $instance = new class {
            /** @var list<int> */
            public array $added = [];

            public function addToAssertionCount(int $count): void
            {
                $this->added[] = $count;
            }
        };

        RealPhpUnitBootstrap::bridgeAssertionCount($instance);

        self::assertSame([], $instance->added);
    }

    public function testBridgingIgnoresAnInstanceThatCannotCount(): void
    {
        RealPhpUnitBootstrap::bridgeAssertionCount(new class {});

        self::assertTrue(true, 'an instance without addToAssertionCount is skipped, not fataled on');
    }

    /**
     * The latch is tripped during DISCOVERY, long before any test runs,
     * so a plain call here only ever reaches the early return. Reset it
     * to reach the branch that actually decides something: PHPUnit's
     * TextUI classes are absent, so the registry is left alone rather
     * than half-filled.
     */
    public function testConfiguringLooksForPhpUnitsCliClassesAndLeavesThemAloneWhenAbsent(): void
    {
        $latch = new ReflectionProperty(RealPhpUnitBootstrap::class, 'attempted');
        $latch->setValue(null, false);

        RealPhpUnitBootstrap::ensureConfigured();

        self::assertTrue($latch->getValue(), 'the attempt is recorded even when there is nothing to configure');
    }

    public function testConfiguringIsAttemptedOnlyOnce(): void
    {
        // Best-effort by design: a second call must not re-enter the
        // TextUI internals it reaches into, which carry no BC promise.
        RealPhpUnitBootstrap::ensureConfigured();
        RealPhpUnitBootstrap::ensureConfigured();

        self::assertTrue(true, 'the registry is filled best-effort or not at all, never fatally');
    }
}
