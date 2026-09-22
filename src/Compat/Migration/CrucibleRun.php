<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function dirname;
use function file;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function proc_close;
use function proc_open;
use function unlink;

use const PHP_BINARY;

/**
 * Runs Crucible itself as a child process — the same subprocess
 * pattern `FlakesCommand::childOutcomes()` already uses for its own
 * rounds, reused here for `compat-check`'s Crucible-side outcomes.
 */
final readonly class CrucibleRun
{
    /**
     * The child command, with the pest vocabulary prelude prepended.
     *
     * compat-check compares two engines, so it needs both live -- and on
     * a pest project they could not be. Pest publishes test(), it() and
     * expect() through Composer's `files` autoload, so they exist before
     * any Crucible code runs, and PHP has no function_alias(): a second
     * declaration is an uncatchable fatal. The dialect therefore stood
     * down, compat-check discovered nothing on the Crucible side, and
     * the comparison was empty -- for precisely the users this tool is
     * aimed at.
     *
     * The prelude asks Composer, through its own documented
     * `__composer_autoload_files` guard, not to include pest's two
     * function files. Its globals are never defined; every one of its
     * classes stays autoloadable (D-111).
     *
     * Passed unconditionally, because it is a no-op wherever pest is not
     * installed: it looks for `/pestphp/pest/src/` in the target's own
     * autoload map and marks nothing when there is nothing to mark.
     * Making it conditional would mean detecting the child's vendor tree
     * from the parent, which is one more thing to get wrong for no gain.
     *
     * @param list<non-empty-string> $extraArgs
     *
     * @return list<string>
     */
    public static function command(string $binary, array $extraArgs, string $eventsFile): array
    {
        return [
            PHP_BINARY,
            '-d',
            'auto_prepend_file=' . dirname(__DIR__, 2) . '/Dialect/Pest/vocabulary-prelude.php',
            $binary,
            ...$extraArgs,
            '--log-events-json',
            $eventsFile,
        ];
    }

    /**
     * @param non-empty-string       $binary           path to the crucible binary
     * @param list<non-empty-string> $extraArgs
     * @param non-empty-string       $eventsFile
     *
     * @return array<string, string> "FQCN::method#dataset" => outcome
     */
    public static function outcomes(string $binary, array $extraArgs, WorkingDirectory $workingDirectory, string $eventsFile): array
    {
        $process = proc_open(
            self::command($binary, $extraArgs, $eventsFile),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $workingDirectory->path,
        );

        if (is_resource($process)) {
            proc_close($process);
        }

        $outcomes = self::parseEvents($eventsFile);

        if (is_file($eventsFile)) {
            unlink($eventsFile);
        }

        return $outcomes;
    }

    /**
     * The pure parsing half, public so it can be unit-tested against a
     * fixture NDJSON file without a real Crucible subprocess.
     *
     * @param non-empty-string $eventsFile
     *
     * @return array<string, string>
     */
    public static function parseEvents(string $eventsFile): array
    {
        $outcomes = [];
        $lines    = is_file($eventsFile) ? file($eventsFile) : false;

        foreach ($lines === false ? [] : $lines as $line) {
            $event = json_decode($line, true);

            if (is_array($event)
                && ($event['event'] ?? null) === 'test:finish'
                && is_string($event['id'] ?? null)
                && is_string($event['outcome'] ?? null)
            ) {
                $outcomes[$event['id']] = $event['outcome'];
            }
        }

        return $outcomes;
    }
}
