<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use function explode;
use function file;
use function is_array;
use function preg_match;
use function str_contains;
use function str_starts_with;

/**
 * PHPUnit's own event log, read for the tests JUnit cannot locate.
 *
 * ✓ Measured against pest 5.1.1: pest passes PHPUnit's event stream
 * through untouched, so a classic PHPUnit class inside a pest run is
 * reported there by its real class, its real method, and the dataset in
 * the exact `#name` form Crucible's ids already use —
 *
 *     Test Prepared (Tests\ClassicTest::testWithAProvider#one)
 *
 * — where the same test in pest's JUnit is a prettified description
 * with no file in it (D-115, D-117). This is not a fallback for that:
 * the two logs come from ONE run and own disjoint rows, decided by a
 * property of the row rather than by trying one and then the other.
 * Pest's own tests are self-labelling here — `P\Tests\ArithmeticTest::
 * __pest_evaluable_it_slugs` — and reversing that mangling would be
 * guesswork, while JUnit carries their file exactly. So each log is
 * read only where it is exact, and neither where it would need a
 * transformation undone.
 */
final readonly class PhpUnitEventLog
{
    /**
     * Pest's own tests, which JUnit keys exactly and this log mangles.
     */
    private const string PEST_METHOD_PREFIX = '__pest_evaluable_';

    /**
     * The class-based tests the run reported, by "Class::method#dataset".
     *
     * Outcomes are assigned by PRECEDENCE, not by arrival: PHPUnit emits
     * "Test Passed" and then "Test Considered Risky" for the same test,
     * and a reader that took the last line would call a failed-and-risky
     * test risky. Ordering is the log's business; the verdict is not.
     *
     * @param non-empty-string $file
     *
     * @return array<string, string>
     */
    public static function parse(string $file): array
    {
        $lines = file($file);

        if (!is_array($lines)) {
            return [];
        }

        $rank = ['error' => 4, 'fail' => 3, 'risky' => 2, 'skip' => 1, 'pass' => 0];
        $seen = [];

        foreach ($lines as $line) {
            if (preg_match('/Test (Passed|Failed|Errored|Skipped|Considered Risky) \((?<id>.+)\)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $id = $match['id'];

            if (!str_contains($id, '::') || str_starts_with(explode('::', $id, 2)[1], self::PEST_METHOD_PREFIX)) {
                continue;
            }

            $outcome = match ($match[1]) {
                'Failed'           => 'fail',
                'Errored'          => 'error',
                'Skipped'          => 'skip',
                'Considered Risky' => 'risky',
                default            => 'pass',
            };

            if (!isset($seen[$id]) || $rank[$outcome] > $rank[$seen[$id]]) {
                $seen[$id] = $outcome;
            }
        }

        return $seen;
    }

    /**
     * The same outcomes under Crucible's own ids, plus what could not
     * be placed.
     *
     * The class-to-file map is built by token scan of the candidate
     * files (ClassLocator), never by loading them: a file that declares
     * a class says so in its own grammar, so this half needs no
     * inference either. A class no candidate file declares is NOT
     * placed by resemblance — it is returned as unlocated and reported,
     * because a fabricated key can collide with a real one.
     *
     * @param array<string, string>                $events "Class::method#dataset" => outcome
     * @param array<string, non-empty-string>      $files  class => project-relative file
     *
     * @return array{outcomes: array<string, string>, unlocated: list<string>}
     */
    public static function keyedByFile(array $events, array $files): array
    {
        $outcomes  = [];
        $unlocated = [];

        foreach ($events as $id => $outcome) {
            [$class, $rest] = explode('::', $id, 2);
            $file           = $files[$class] ?? null;

            if ($file === null) {
                $unlocated[] = $id;

                continue;
            }

            $outcomes[$file . '::' . $rest] = $outcome;
        }

        return ['outcomes' => $outcomes, 'unlocated' => $unlocated];
    }
}
