<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Watch\RetriggerListener;
use LucianoPereira\Crucible\Watch\WatchLoop;
use LucianoPereira\Crucible\Watch\WatchSession;

use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function printf;

use const PHP_EOL;

/**
 * Watch mode (G3 slice 2): the parent only loads the configuration
 * to learn what to watch — every run happens in a fresh child
 * process (PHP cannot re-require changed files; a new process is
 * the only honest reload), so no bootstrap or php settings are
 * applied here.
 */
final class WatchCommand
{
    /**
     * @param list<string>     $argv
     */
    public function execute(CliOptions $options, array $argv, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $configuration = $loaded->configuration;
        $directories   = [];
        $files         = [$loaded->path];

        foreach ($configuration->testSuites as $suite) {
            foreach ($suite->directories as $directory) {
                $directories[] = $workingDirectory->absolute($directory);
            }

            foreach ($suite->files as $file) {
                $files[] = $workingDirectory->absolute($file);
            }
        }

        foreach ($configuration->source->includeDirectories as $directory) {
            $directories[] = $workingDirectory->absolute($directory);
        }

        foreach ($configuration->source->includeFiles as $file) {
            $files[] = $workingDirectory->absolute($file);
        }

        // The JavaScript tier (D-081): watch each configured Vitest
        // suite too, and tell the session its roots — a change under one
        // is in the JS graph, re-run through `--related` (where D-080
        // narrows it to `vitest related`) rather than widening to a full
        // run the way an off-graph file does.
        $jsDirectories = [];

        foreach ($configuration->vitest as $suite) {
            $jsDirectory     = $workingDirectory->absolute($suite->directory);
            $directories[]   = $jsDirectory;
            $jsDirectories[] = $jsDirectory;
        }

        /** @var list<non-empty-string> $childArgv */
        $childArgv = array_values(array_filter($argv, static fn(string $argument): bool => $argument !== '--watch' && $argument !== ''));

        printf('Watching %d directories. Every change runs the affected tests in a fresh process.' . PHP_EOL, count(array_unique($directories)));

        // The retrigger endpoint (D-083), when the project asked for it.
        // Failing to bind is not worth losing a watch session over, so a
        // null listener simply means the loop watches the way it always did.
        $retrigger = $configuration->retriggerHotFile === null
            ? null
            : RetriggerListener::open(
                $workingDirectory->absolute($configuration->retriggerHotFile),
                $configuration->retriggerPort,
            );

        if ($retrigger instanceof RetriggerListener) {
            printf('Retrigger endpoint: %s' . PHP_EOL, $retrigger->url());
        } elseif ($configuration->retriggerHotFile !== null) {
            print 'Retrigger endpoint: could not bind — watching without it.' . PHP_EOL;
        }

        try {
            return (new WatchLoop(
                $childArgv,
                array_values(array_unique($directories)),
                array_values(array_unique($files)),
                session: new WatchSession(
                    array_values(array_unique($jsDirectories)),
                    $configuration->impactRules,
                    $workingDirectory->path,
                ),
                retrigger: $retrigger,
            ))->run();
        } finally {
            $retrigger?->close();
        }
    }
}
