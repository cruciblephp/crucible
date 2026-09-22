<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Bridge\PestSnapshots;

use LucianoPereira\Crucible\Dialect\Pest\CurrentTest;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use ReflectionClass;

use function dirname;
use function expect;
use function function_exists;

/**
 * Duck-types the real trait's own method names by hand, but does NOT
 * `use \Spatie\Snapshots\MatchesSnapshots` itself — that trait isn't,
 * and shouldn't become, a dev-dependency of the engine (same
 * reasoning as the Laravel bridge's own fixture, phpstan.neon). That
 * makes it exactly the shape dispatch()'s guard exists to catch: a
 * class with all the right method names available, but not actually
 * carrying the real trait — found for real via
 * uses(PestTestCase::class) with spatie/phpunit-snapshot-assertions
 * installed, where Crucible's own inherited, unrelated
 * assertMatchesSnapshot() (D-076) collided with the trait's and
 * silently won. Without the guard, this fixture's own calls array
 * below would never even get a chance to record anything — dispatch()
 * would instead reflect into whatever method of that name the
 * instance already has.
 *
 * "Forwards correctly when the trait genuinely IS mixed in" is
 * verified against the real spatie/laravel-data benchmark instead,
 * not here — same as the Laravel bridge's mock()/actingAs(), this
 * suite's own environment has no real Spatie\Snapshots\MatchesSnapshots
 * to construct a genuine positive case against.
 */
final class FakeSnapshotTestCase
{
    /** @var list<array{non-empty-string, list<mixed>}> */
    public array $calls = [];

    public function assertMatchesSnapshot(mixed $actual, mixed $driver = null): void
    {
        $this->calls[] = ['assertMatchesSnapshot', [$actual, $driver]];
    }

    public function assertMatchesTextSnapshot(string $actual): void
    {
        $this->calls[] = ['assertMatchesTextSnapshot', [$actual]];
    }
}

final class FunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        // functions.php calls expect() at its own top level (the
        // extend() registrations) — in production this is guaranteed
        // loaded first by PestBuilder::ensureUsable()'s own require
        // order, but this test requires the bridge file directly, so
        // it must ensure the same precondition itself rather than
        // rely on some other *.pest.php file in the suite happening
        // to have run first.
        if (!function_exists('expect')) {
            require dirname(__DIR__, 4) . '/src/Dialect/Pest/functions.php';
        }

        if (!function_exists('Spatie\Snapshots\assertMatchesSnapshot')) {
            require dirname(__DIR__, 4) . '/src/Bridge/PestSnapshots/functions.php';
        }
    }

    protected function tearDown(): void
    {
        CurrentTest::set(null);
    }

    public function testCallingAProxyOutsideARunningTestThrows(): void
    {
        $this->expectException(ConfigurationException::class);

        \Spatie\Snapshots\assertMatchesTextSnapshot('anything');
    }

    public function testProxyRefusesAnInstanceThatDoesNotActuallyUseTheRealTrait(): void
    {
        CurrentTest::set(new FakeSnapshotTestCase());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(FakeSnapshotTestCase::class);

        \Spatie\Snapshots\assertMatchesSnapshot('text value');
    }

    /**
     * expect()->extend() is process-global (Expectation::$extensions
     * is a static property) — the registrations at the bottom of
     * functions.php ran once, the first time any test in this suite
     * required the file (this setUp()'s own guard), so this matcher
     * is already available regardless of test order. No
     * toMatchSnapshot() case here — it's deliberately not registered
     * (see functions.php); that name resolves to Crucible's own
     * native, unrelated matcher instead, covered by ExpectationTest's
     * own suite, not this one.
     */
    public function testExpectMatcherRefusesAnInstanceThatDoesNotActuallyUseTheRealTrait(): void
    {
        CurrentTest::set(new FakeSnapshotTestCase());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(FakeSnapshotTestCase::class);

        expect('text value')->toMatchTextSnapshot();
    }

    /**
     * Documents the collision itself: expect()->toMatchSnapshot()
     * resolves to Crucible's own native matcher (D-076), not
     * Spatie's — this is Expectation's real, declared method
     * winning over __call()'s extension lookup, by ordinary PHP
     * method resolution, not a Crucible-specific special case.
     */
    public function testToMatchSnapshotResolvesToCruciblesOwnNativeMatcherNotSpaties(): void
    {
        self::assertTrue((new ReflectionClass(Expectation::class))->hasMethod('toMatchSnapshot'));
    }
}
