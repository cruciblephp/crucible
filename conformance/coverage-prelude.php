<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Makes a child process contribute what it executed of Crucible's own
 * source, so a line exercised outside the self-hosted suite stops
 * reading as a line nothing tests.
 *
 * The gap this closes is a real one: the conformance suite runs the
 * whole assertion surface against the real phpunit binary, in a child
 * process, and none of it reached the coverage map — Assert.php read
 * 45.2% while the strongest gate in the project was exercising it. A
 * number that cannot tell "nobody tests this" from "tested by the
 * strongest gate you have" cannot be used to decide what to test next.
 *
 * Loaded with -d auto_prepend_file=, so it runs before the entry
 * script of whatever child it is attached to and needs no cooperation
 * from that child. PHP applies that ini to a script FILE and ignores
 * it for `php -r`, which is why the conformance harness attaches it to
 * the crucible entry script rather than to a one-liner. Silent by construction: it prints nothing, changes
 * no exit code, and returns immediately when the environment does not
 * ask for it or no driver is loaded — a conformance run compares
 * observable behavior, and a probe that changed any of it would be
 * measuring itself.
 *
 * The artifact carries LINES ONLY, never a test id. The per-test map
 * feeds the impact graph and the mutation query (D-041/D-047), and
 * those want tests that exist; a whole child process is not one. So
 * the report learns the line ran, and nothing learns a test covered
 * it.
 */

namespace LucianoPereira\Crucible\Conformance;

use LucianoPereira\Crucible\Coverage\CoverageCollector;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\CoverageDriver;
use LucianoPereira\Crucible\Coverage\DriverFactory;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function getenv;
use function getmypid;
use function is_dir;
use function is_file;
use function is_string;
use function random_bytes;
use function register_shutdown_function;

(static function (): void {
    $directory = getenv('CRUCIBLE_COVERAGE_ARTIFACTS');

    if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
        return;
    }

    $root = dirname(__DIR__);

    if (!is_file($root . '/vendor/autoload.php')) {
        return;
    }

    require_once $root . '/vendor/autoload.php';

    $driver = DriverFactory::detect();

    // A child started without a driver is the ordinary case — the
    // caller decides whether coverage is worth its cost, and being
    // asked for an artifact is not the same as being able to make one.
    if (!$driver instanceof CoverageDriver) {
        return;
    }

    $collector = new CoverageCollector($driver, [$root . '/src/']);
    $collector->begin();

    register_shutdown_function(static function () use ($collector, $directory): void {
        $window = $collector->end();

        if ($window->lines === []) {
            return;
        }

        // Named for where it came from and unique per process: several
        // children run at once, and a reader of the directory should be
        // able to see which gate produced what.
        file_put_contents(
            $directory . '/external-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.json',
            (new CoverageData($window->lines, [], $window->branches, $window->paths))->toJson(),
        );
    });
})();
