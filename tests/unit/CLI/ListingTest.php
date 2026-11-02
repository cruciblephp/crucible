<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function implode;
use function realpath;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_BINARY;

/**
 * The listings and the testdox file, run as a user runs them over one
 * fixture. ✓ Every expected text is what PHPUnit 13.4.3 writes for the
 * same class; only the suite's name comes from crucible.php.
 */
final class ListingTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../_fixtures/listing';

    /** @return list<string> the lines after the banner and the configuration line */
    private function crucible(string $arguments): array
    {
        exec(sprintf(
            'cd %s && %s %s %s 2>&1',
            escapeshellarg(self::FIXTURE),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 3) . '/crucible'),
            $arguments,
        ), $output);

        $lines = [];

        foreach ($output as $line) {
            if ($line !== '' && !str_starts_with($line, 'crucible ') && !str_starts_with($line, 'Configuration: ')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function testEveryDataSetIsListedByItsName(): void
    {
        self::assertSame(
            [
                'Available tests:',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testA',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testFails',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testIsRisky',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testRows"first"',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testRows#0',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testRows#1',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testWith#0',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testWith"named"',
                ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testWith#1',
            ],
            $this->crucible('--list-tests'),
        );
        self::assertSame(['Available test:', ' - LucianoPereira\Crucible\Tests\Fixtures\Listing\ListedTest::testA'], $this->crucible('--list-tests --filter testA'));
    }

    public function testGroupsAndSuitesCarryTheirCountsAndAnEmptySuiteIsLeftOut(): void
    {
        self::assertSame(
            ['Available test groups:', ' - db (2 tests)', ' - default (7 tests)', ' - slow (1 test)'],
            $this->crucible('--list-groups'),
        );
        self::assertSame(['Available test suite:', ' - unit (9 tests)'], $this->crucible('--list-suites'));
        self::assertSame(
            ['Available test files:', ' - ' . realpath(self::FIXTURE) . '/tests/ListedTest.php'],
            $this->crucible('--list-test-files'),
        );
    }

    public function testTheTestdoxFileIsPhpUnitsForTheSameClass(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'crucible-testdox-');

        try {
            $this->crucible('--testdox-text ' . escapeshellarg($file));

            self::assertSame(
                implode("\n", [
                    'Listed (LucianoPereira\Crucible\Tests\Fixtures\Listing\Listed)',
                    ' [x] A',
                    ' [ ] Fails',
                    ' [x] Is risky',
                    ' [x] Rows with data set "first"',
                    ' [ ] Rows with data set #0',
                    ' [ ] Rows with data set #1',
                    ' [x] With with data set #0',
                    ' [x] With with data set "named"',
                    ' [x] With with data set #1',
                    '',
                    '',
                ]),
                (string) file_get_contents($file),
            );
        } finally {
            unlink($file);
        }
    }
}
