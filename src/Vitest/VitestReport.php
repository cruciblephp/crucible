<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Vitest;

use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Test\TestId;

use function array_filter;
use function array_map;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Translates a decoded Vitest JSON report (D-079) into Crucible
 * `test:finish` events — orchestration, never reimplementation. Vitest's
 * `--reporter=json` output is Jest-compatible: `testResults[]` per file,
 * each carrying `assertionResults[]` per test. Every assertion result
 * becomes one first-class {@see TestFinished} on the NDJSON stream, so a
 * JS test folds into the same tree, tally, report, and exit code as a
 * PHP one. Pure and defensive: malformed entries are skipped, never a
 * crash — the parser is the one place a foreign contract is trusted.
 */
final class VitestReport
{
    /**
     * @param array<mixed>     $decoded     the parsed JSON report
     * @param non-empty-string $projectRoot absolute JS project root; file paths are made relative to it
     *
     * @return list<TestFinished>
     */
    public static function translate(array $decoded, string $projectRoot): array
    {
        $files  = is_array($decoded['testResults'] ?? null) ? $decoded['testResults'] : [];
        $prefix = rtrim($projectRoot, '/') . '/';
        $events = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $path     = is_string($file['name'] ?? null) ? $file['name'] : '';
            $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
            $tests    = is_array($file['assertionResults'] ?? null) ? $file['assertionResults'] : [];

            foreach ($tests as $test) {
                if (!is_array($test)) {
                    continue;
                }

                $event = self::event($relative, $test);

                if ($event instanceof TestFinished) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param array<mixed> $test one assertionResult
     */
    private static function event(string $file, array $test): ?TestFinished
    {
        $name = self::string($test['fullName'] ?? null) ?? self::string($test['title'] ?? null);

        if ($file === '' || $name === null) {
            return null;
        }

        $outcome = match (self::string($test['status'] ?? null)) {
            'passed' => Outcome::Passed,
            'failed' => Outcome::Failed,
            'todo'   => Outcome::Incomplete,
            default  => Outcome::Skipped, // skipped, pending, disabled, or unknown
        };

        $milliseconds = $test['duration'] ?? null;
        $duration     = is_int($milliseconds) || is_float($milliseconds) ? (float) $milliseconds / 1000.0 : 0.0;

        return new TestFinished(new TestId($file, $name), $outcome, $duration, self::failure($outcome, $test));
    }

    /**
     * @param array<mixed> $test
     */
    private static function failure(Outcome $outcome, array $test): ?Failure
    {
        if ($outcome !== Outcome::Failed) {
            return null;
        }

        $messages = is_array($test['failureMessages'] ?? null) ? $test['failureMessages'] : [];
        $text     = implode("\n\n", array_filter(
            array_map(static fn(mixed $message): string => is_string($message) ? $message : '', $messages),
            static fn(string $message): bool => $message !== '',
        ));

        return new Failure($text !== '' ? $text : 'The Vitest test failed.');
    }

    /**
     * @return ?non-empty-string
     */
    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
