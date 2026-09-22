<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Bridge\PestSnapshots;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Bridge\PestSnapshots\AutoMixedTrait;
use LucianoPereira\Crucible\Framework\TestCase;
use ReflectionClass;
use stdClass;

/** PHP forbids `final` on an anonymous class, so this needs a name. */
final class FinalFixture {}

/**
 * spatie/phpunit-snapshot-assertions is not, and should never
 * become, a dev-dependency of the engine itself (same reasoning as
 * src/Bridge/Laravel, phpstan.neon) — so in this suite's own
 * environment, Spatie\Snapshots\MatchesSnapshots never exists, and
 * forClass() must return [] regardless of the target class's own
 * shape. The "the trait is actually mixed in when the package is
 * installed" behavior is verified against the real
 * spatie/laravel-data benchmark instead, not here.
 */
#[CoversClass(AutoMixedTrait::class)]
final class AutoMixedTraitTest extends TestCase
{
    public function testReturnsNothingWhenTheSnapshotsPackageIsNotInstalled(): void
    {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass(stdClass::class);

        self::assertSame([], AutoMixedTrait::forClass($reflection));
    }

    public function testReturnsNothingForAFinalClassEither(): void
    {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass(FinalFixture::class);

        self::assertTrue($reflection->isFinal());
        self::assertSame([], AutoMixedTrait::forClass($reflection));
    }
}
