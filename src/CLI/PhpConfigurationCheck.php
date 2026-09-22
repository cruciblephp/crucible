<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use function extension_loaded;
use function in_array;
use function ini_get;
use function sprintf;

use const E_ALL;

/**
 * Whether PHP is configured the way a development machine should be:
 * errors visible, assertions live, no memory ceiling in the way of a
 * test suite. Advisory only — every entry is a recommendation about
 * the environment, never a verdict about the code, so nothing here can
 * fail a run on its own.
 *
 * The settings and their expected values are the spec's, so a project
 * that already satisfies one tool satisfies the other.
 */
final readonly class PhpConfigurationCheck
{
    /**
     * name => [expected values, the value to write in php.ini, the extension it belongs to]
     *
     * @var array<non-empty-string, array{list<string>, non-empty-string, ?non-empty-string}>
     */
    private const array SETTINGS = [
        'display_errors'              => [['1'], 'On', null],
        'display_startup_errors'      => [['1'], 'On', null],
        'error_reporting'             => [['-1'], '-1', null],
        'xdebug.show_exception_trace' => [['0'], '0', 'xdebug'],
        'zend.assertions'             => [['1'], '1', null],
        'assert.exception'            => [['1'], '1', null],
        'memory_limit'                => [['-1'], '-1', null],
    ];

    /**
     * Every applicable setting with its verdict, in declaration order.
     * A setting belonging to an absent extension is not applicable and
     * is left out rather than reported as a problem nobody has.
     *
     * @return list<array{name: non-empty-string, expected: non-empty-string, actual: string, ok: bool}>
     */
    public static function results(): array
    {
        $results = [];

        foreach (self::SETTINGS as $name => [$expected, $recommended, $extension]) {
            if ($extension !== null && !extension_loaded($extension)) {
                continue;
            }

            $actual = ini_get($name);
            $actual = $actual === false ? '' : $actual;

            // error_reporting is written as a bitmask, so the fully-on
            // value has two equally correct spellings.
            if ($name === 'error_reporting') {
                $expected[] = (string) E_ALL;
            }

            $results[] = [
                'name'     => $name,
                'expected' => $recommended,
                'actual'   => $actual,
                'ok'       => in_array($actual, $expected, true),
            ];
        }

        return $results;
    }

    /**
     * One advisory line per setting that is not as recommended.
     *
     * @return list<non-empty-string>
     */
    public static function warnings(): array
    {
        $warnings = [];

        foreach (self::results() as $result) {
            if (!$result['ok']) {
                $warnings[] = sprintf(
                    'PHP is not configured for development: %s should be %s, but is %s',
                    $result['name'],
                    $result['expected'],
                    $result['actual'] === '' ? '(empty)' : $result['actual'],
                );
            }
        }

        return $warnings;
    }
}
