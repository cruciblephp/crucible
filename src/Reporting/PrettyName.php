<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Attributes\Check;
use LucianoPereira\Crucible\Test\TestId;

use function basename;
use function ctype_digit;
use function implode;
use function lcfirst;
use function preg_split;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function ucfirst;

/**
 * TestDox-style prettifying, shared by the human-facing reporters:
 * `testSumsTwoNumbers` reads as "Sums two numbers", a file declares
 * the section it prints under, and dataset rows carry the spec's
 * "with data set" spelling.
 */
final readonly class PrettyName
{
    /**
     * The section title a file's tests print under:
     * tests/unit/Runner/SchedulerTest.php → "Scheduler".
     *
     * @param non-empty-string $file
     *
     * @return non-empty-string
     */
    #[Check(['tests/unit/Runner/SchedulerTest.php'], returns: 'Scheduler')]
    #[Check(['Test.php'], returns: 'Test', name: 'a file just called Test keeps its name')]
    public static function ofFile(string $file): string
    {
        $name = basename($file);

        if (str_ends_with($name, '.php')) {
            $name = substr($name, 0, -4);
        }

        if (str_ends_with($name, 'Test') && $name !== 'Test') {
            $name = substr($name, 0, -4);
        }

        return $name !== '' ? $name : $file;
    }

    /**
     * @return non-empty-string
     */
    public static function ofTest(TestId $id): string
    {
        $name = $id->name;

        if (str_starts_with($name, 'test') && $name !== 'test') {
            $name = lcfirst(substr($name, 4));
        }

        $words = preg_split('/(?=[A-Z])|_/', $name, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            $words = [$name];
        }

        $sentence = [];

        foreach ($words as $word) {
            $sentence[] = strtolower($word);
        }

        $pretty = ucfirst(implode(' ', $sentence));

        if ($id->dataset !== null) {
            $pretty .= self::dataset($id->dataset);
        }

        return $pretty;
    }

    /**
     * The spec's dataset spelling: quoted for named rows, #N for
     * positional ones.
     *
     * @param non-empty-string $dataset
     *
     * @return non-empty-string
     */
    public static function dataset(string $dataset): string
    {
        return ctype_digit($dataset)
            ? sprintf(' with data set #%s', $dataset)
            : sprintf(' with data set "%s"', $dataset);
    }
}
