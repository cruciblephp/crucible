<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\CLI\PhpstanMessage;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\DataAttributeRule;
use LucianoPereira\Crucible\PHPStan\DataRows;
use LucianoPereira\Crucible\PHPStan\DatasetRowRule;
use LucianoPereira\Crucible\PHPStan\TableRowRule;

use function array_map;
use function basename;
use function sort;
use function sprintf;

/**
 * The data-row rules (D-129) through the real phpstan binary, over
 * fixtures that seed one mistake of every kind beside rows that are
 * right. Each reported line is a row that errors when the suite runs
 * (measured on the same rows before they became fixtures); the rows not
 * reported pass. So the list below is both halves at once: nothing
 * wrong goes unreported, nothing right is reported.
 */
#[CoversClass(DataRows::class)]
#[CoversClass(DatasetRowRule::class)]
#[CoversClass(DataAttributeRule::class)]
#[CoversClass(TableRowRule::class)]
#[Group('phpstan-blackbox')]
final class DataRowRulesTest extends TestCase
{
    public function testEveryMistakeIsReportedAndNothingElse(): void
    {
        $report = Analysis::run([Analysis::root() . '/tests/_fixtures/phpstan/datarows'], Analysis::extension(), parameters: ['level' => '5']);
        $found  = array_map(static fn(PhpstanMessage $message): string => sprintf('%s:%d %s', basename($message->file), $message->line, $message->identifier ?? '?'), $report->messages);

        sort($found);

        self::assertSame([
            'DataAttributes.php:15 crucible.checkParameter',
            'DataAttributes.php:16 crucible.checkReturns',
            'DataAttributes.php:17 crucible.checkArity',
            'DataAttributes.php:28 crucible.testWithParameter',
            'DataAttributes.php:29 crucible.testWithArity',
            'DataAttributes.php:31 crucible.testWithParameter',
            'DatasetRows.pest.php:10 crucible.datasetArity',
            'DatasetRows.pest.php:11 crucible.datasetParameter',
            'DatasetRows.pest.php:12 crucible.datasetParameter',
            'DatasetRows.pest.php:17 crucible.datasetParameter',
            'DatasetRows.pest.php:9 crucible.datasetParameter',
            'TableRows.crucible.php:12 crucible.tableParameter',
            'TableRows.crucible.php:13 crucible.tableArity',
            'TableRows.crucible.php:16 crucible.tableParameter',
        ], $found);
    }
}
