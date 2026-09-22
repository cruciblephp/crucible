<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * A thin proxy layer for spatie/pest-plugin-snapshots' function
 * surface (growth G2 follow-up). That package hard-requires
 * pestphp/pest itself, which D-019's own PestBuilder::ensureUsable()
 * guard already refuses to coexist with — so it can never actually be
 * installed alongside Crucible's pest dialect, same reasoning as the
 * Pest\Laravel/Pest\Livewire bridges (src/Bridge/PestLaravel,
 * src/Bridge/PestLivewire). Every exported function is, in the real
 * package, a one-line proxy to a method on
 * Spatie\Snapshots\MatchesSnapshots — a trait, not a native TestCase
 * method, so PestBuilder auto-mixes it into every uses() class when
 * spatie/phpunit-snapshot-assertions (the standalone package that
 * actually provides the trait — no pestphp/pest dependency at all)
 * is installed, mirroring real Pest's own
 * Plugin::uses(MatchesSnapshots::class) (see AutoMixedTrait, in this
 * same directory, and its call site in PestBuilder::build()).
 *
 * Most of these methods are public on the real trait, so a plain
 * ->method() call would work here too — routed through
 * CurrentTest::call() anyway, for uniformity with the Laravel bridge
 * and because that's how real Pest's own HigherOrderMessage::call()
 * reaches every proxied method regardless of visibility.
 *
 * Scoped to the functions real-world test suites actually exercise
 * (assertMatchesSnapshot, assertMatchesJsonSnapshot — verified
 * against spatie/laravel-data) plus the rest of the trivial,
 * same-shape family. The expect()->extend() custom matchers
 * (toMatchSnapshot etc.) are included too — Crucible's own
 * Expectation::extend() already exists (built for user-authored
 * matchers), so these are wiring, not new capability; unlike the
 * functions above, laravel-data's own suite never calls them, so
 * they're unverified against a real benchmark, only unit-tested.
 * One exception: toMatchSnapshot() itself is deliberately NOT
 * registered — it collides with Crucible's own, unrelated, native
 * toMatchSnapshot() (D-076) — see the comment at the registrations
 * below.
 *
 * Not verified: first-time snapshot creation. The real trait marks
 * that case incomplete via a #[PostCondition]-attributed method
 * (PHPUnit\Framework\Attributes\PostCondition) — whether Crucible's
 * engine honors that real PHPUnit attribute (as opposed to its own,
 * src/Attributes/PostCondition.php) on a trait mixed in this way is
 * untested; the real spatie/laravel-data benchmark this was verified
 * against ships its snapshots pre-created, so only the
 * compare-against-existing-snapshot path was exercised.
 */

namespace Spatie\Snapshots;

use LucianoPereira\Crucible\Dialect\Pest\CurrentTest;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function class_uses;
use function expect;
use function func_get_args;
use function function_exists;
use function get_parent_class;
use function in_array;
use function sprintf;

/**
 * AutoMixedTrait::forClass() (this bridge's own build-time half —
 * see that file) skips mixing MatchesSnapshots in for a final uses()
 * class, or one that already declares a method with the same name as
 * one of the trait's own (Crucible's own TestCase/Assert hierarchy
 * declares assertMatchesSnapshot() itself, for its own unrelated
 * D-076 snapshot feature — found via uses(PestTestCase::class), the
 * default uses() class for pest-dialect tests, with
 * spatie/phpunit-snapshot-assertions installed). Without this check,
 * CurrentTest::call() would silently fall through to reflecting
 * whatever unrelated method of that name the class actually has —
 * calling Crucible's own, differently-behaved implementation instead
 * of Spatie's, with no error at all. This turns that into a clear,
 * actionable one instead.
 *
 * Walks the class's own ancestry, not just class_uses($instance::class)
 * alone — the running instance is usually one more subclass down from
 * where MatchesSnapshots was actually mixed in
 * (SnapshotIdentityWrapper's own generated wrapper, which class_uses()
 * alone can't see past since it only reports a class's own directly
 * `use`d traits, not an ancestor's).
 *
 * @param list<mixed> $arguments
 */
function dispatch(string $method, array $arguments): mixed
{
    $instance = CurrentTest::get();

    if (!usesMatchesSnapshots($instance::class)) {
        throw new ConfigurationException(sprintf(
            "Spatie\\Snapshots\\%s() is not available on %s: either spatie/phpunit-snapshot-assertions "
                . 'is not installed, or %s already declares a method with the same name as one of '
                . "MatchesSnapshots's own — use a uses() class without that conflict (a real "
                . 'PHPUnit-descended TestCase, as spatie/laravel-data itself does), not one from '
                . "Crucible's own TestCase/Assert hierarchy (it declares assertMatchesSnapshot() itself, "
                . 'for its own unrelated D-076 snapshot feature).',
            $method,
            $instance::class,
            $instance::class,
        ));
    }

    return CurrentTest::call($method, $arguments);
}

/** @param class-string $class */
function usesMatchesSnapshots(string $class): bool
{
    do {
        $uses = class_uses($class);

        if (in_array(MatchesSnapshots::class, $uses === false ? [] : $uses, true)) {
            return true;
        }

        $class = get_parent_class($class);
    } while ($class !== false);

    return false;
}

if (!function_exists('Spatie\Snapshots\assertMatchesSnapshot')) {
    function assertMatchesSnapshot(mixed $actual, ?Driver $driver = null): void
    {
        dispatch('assertMatchesSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesFileHashSnapshot')) {
    function assertMatchesFileHashSnapshot(string $filePath): void
    {
        dispatch('assertMatchesFileHashSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesFileSnapshot')) {
    function assertMatchesFileSnapshot(string $file): void
    {
        dispatch('assertMatchesFileSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesHtmlSnapshot')) {
    function assertMatchesHtmlSnapshot(string $actual): void
    {
        dispatch('assertMatchesHtmlSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesJsonSnapshot')) {
    function assertMatchesJsonSnapshot(string $actual): void
    {
        dispatch('assertMatchesJsonSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesObjectSnapshot')) {
    function assertMatchesObjectSnapshot(object $actual): void
    {
        dispatch('assertMatchesObjectSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesTextSnapshot')) {
    function assertMatchesTextSnapshot(string $actual): void
    {
        dispatch('assertMatchesTextSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesXmlSnapshot')) {
    function assertMatchesXmlSnapshot(string $actual): void
    {
        dispatch('assertMatchesXmlSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesYamlSnapshot')) {
    function assertMatchesYamlSnapshot(string $actual): void
    {
        dispatch('assertMatchesYamlSnapshot', func_get_args());
    }
}

if (!function_exists('Spatie\Snapshots\assertMatchesImageSnapshot')) {
    function assertMatchesImageSnapshot(mixed $actual, float $threshold = 0.1, bool $includeAa = true): void
    {
        dispatch('assertMatchesImageSnapshot', func_get_args());
    }
}

// expect()->extend() registrations mirror the real plugin's own
// Functions.php exactly: top-level calls, run once (guarded by this
// whole file's function_exists guard in PestBuilder::ensureUsable()).
// expect() requires an argument here (unlike real Pest's own
// zero-arg form), but extend() never reads $this->value at
// registration time — only when the matcher is later invoked on a
// real expectation — so the value passed in is inert.
//
// No toMatchSnapshot() registration here, deliberately: Crucible
// already has its own, unrelated, native toMatchSnapshot()
// (Expectation.php, D-076's file-based snapshot feature). A real
// PHP method always resolves before __call()'s extension lookup, so
// registering one under this exact name would be permanently
// unreachable dead code — and worse, silently misleading, since a
// migrated Pest test calling expect()->toMatchSnapshot() would
// silently get Crucible's own different storage format instead of
// Spatie's, rather than either working correctly or failing loudly.
// The plain function form, \Spatie\Snapshots\assertMatchesSnapshot(),
// has no such collision and works as expected — that's the spelling
// to use for Spatie's specific file format.
expect(null)->extend('toMatchFileHashSnapshot', function (): void {
    dispatch('assertMatchesFileHashSnapshot', [$this->value]);
});

expect(null)->extend('toMatchFileSnapshot', function (): void {
    dispatch('assertMatchesFileSnapshot', [$this->value]);
});

expect(null)->extend('toMatchHtmlSnapshot', function (): void {
    dispatch('assertMatchesHtmlSnapshot', [$this->value]);
});

expect(null)->extend('toMatchJsonSnapshot', function (): void {
    dispatch('assertMatchesJsonSnapshot', [$this->value]);
});

expect(null)->extend('toMatchObjectSnapshot', function (): void {
    dispatch('assertMatchesObjectSnapshot', [$this->value]);
});

expect(null)->extend('toMatchTextSnapshot', function (): void {
    dispatch('assertMatchesTextSnapshot', [$this->value]);
});

expect(null)->extend('toMatchXmlSnapshot', function (): void {
    dispatch('assertMatchesXmlSnapshot', [$this->value]);
});

expect(null)->extend('toMatchYamlSnapshot', function (): void {
    dispatch('assertMatchesYamlSnapshot', [$this->value]);
});

expect(null)->extend('toMatchImageSnapshot', function (float $threshold = 0.1, bool $includeAa = true): void {
    dispatch('assertMatchesImageSnapshot', [$this->value, $threshold, $includeAa]);
});
