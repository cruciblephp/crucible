<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Bridge\Laravel;

use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;

use function putenv;

/**
 * The Laravel bridge (growth G1): discovered via composer package
 * discovery, it registers the Crucible-backed `artisan test` command and
 * completes the parallel-testing contract inside Crucible workers.
 *
 * ParaTest's wrapper calls ParallelTesting::callSetUpProcessCallbacks
 * once per worker; in a Crucible worker there is no wrapper, so the
 * provider fires it on the first application boot of the process
 * (TEST_TOKEN is only present inside parallel workers — D-028). The
 * per-test-case callbacks need nothing from us: Laravel's own
 * TestCase invokes them whenever a token exists.
 */
final class CrucibleServiceProvider extends ServiceProvider
{
    private static bool $processCallbacksFired = false;

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
        }

        if (self::$processCallbacksFired || ($_SERVER['TEST_TOKEN'] ?? '') === '') {
            return;
        }

        // Laravel gates every ParallelTesting hook on this variable in
        // addition to the token; its paratest wrapper exports it, so
        // inside a Crucible worker the bridge does. Setting it also arms
        // Laravel's own per-token test-database machinery.
        foreach (['LARAVEL_PARALLEL_TESTING' => '1'] as $name => $value) {
            if (($_SERVER[$name] ?? '') === '') {
                $_SERVER[$name] = $value;
                $_ENV[$name]    = $value;
                putenv($name . '=' . $value);
            }
        }

        self::$processCallbacksFired = true;

        // After the whole application has booted: package providers
        // boot before the app's own, and setUpProcess callbacks are
        // typically registered in an app provider's boot().
        $this->app->booted(static function (): void {
            ParallelTesting::callSetUpProcessCallbacks();
        });
    }
}
