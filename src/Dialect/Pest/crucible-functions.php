<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The crucible dialect's own globals — check(), property(), table() —
 * the three names the real Pest does not define. Kept apart from
 * functions.php (the names Pest shares) for the analyser (D-128): these are
 * common enough words that a project's own global check() or table() is
 * plausible, and PHPStan resolving such a call to Crucible's signature
 * reported 373 false errors on one real suite. functions.php is scanned by
 * the extension every project gets; this file only by
 * phpstan/crucible-dialect.neon, for projects that write the dialect.
 * Loaded at run time together with functions.php, guarded the same way.
 */

use LucianoPereira\Crucible\Dialect\Pest\DescribeCall;
use LucianoPereira\Crucible\Dialect\Pest\PestRegistry;
use LucianoPereira\Crucible\Dialect\Pest\TestCall;
use LucianoPereira\Crucible\Property\Gen;

if (!\function_exists('check')) {
    /**
     * The crucible dialect (G2b v1): a nameless test — the deferred value
     * with the whole expectation surface chained on the handle. Named
     * after its own source line unless a description is given.
     *
     * @param ?non-empty-string $description
     */
    function check(Closure $test, ?string $description = null): TestCall
    {
        return PestRegistry::check($test, $description);
    }
}

if (!\function_exists('property')) {
    /**
     * The crucible dialect (G5): a property-based test — generators
     * between the description and the property closure, run on the
     * engine's Hypothesis-style runner (100 cases, shrunk
     * counterexamples, replayable seeds).
     *
     *     property('addition commutes', Gen::int(), Gen::int(),
     *         fn (int $a, int $b) => expect($a + $b)->toBe($b + $a));
     *
     * @param non-empty-string        $description
     * @param Gen<mixed>|Closure      ...$arguments
     */
    function property(string $description, Gen|Closure ...$arguments): TestCall
    {
        return PestRegistry::property($description, ...$arguments);
    }
}

if (!\function_exists('table')) {
    /**
     * The crucible dialect (G2b v1): one test per row against a callable —
     * `table(add(...), [[1, 2, 3]])` reads "add(1, 2) = 3". The last
     * row element is the expected value (toEqual semantics); a string
     * row key names the case.
     *
     * @param iterable<array-key, mixed> $rows
     */
    function table(callable $subject, iterable $rows): DescribeCall
    {
        return PestRegistry::table($subject, $rows);
    }
}
