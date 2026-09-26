<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\EquivalentMarkers;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutantExecutor;
use LucianoPereira\Crucible\Mutation\MutantGenerator;
use LucianoPereira\Crucible\Mutation\MutationOutcome;
use LucianoPereira\Crucible\Mutation\MutationReport;
use LucianoPereira\Crucible\Mutation\MutationRunner;
use LucianoPereira\Crucible\Mutation\MutationVerdict;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function dirname;
use function file_get_contents;
use function ksort;
use function sort;

use const SORT_NUMERIC;

/**
 * Declared equivalent mutants (D-134), in phpcpd-next's notation: the
 * three forms mark the lines they cover, a marker without a reason
 * declares nothing, a declared mutant is never run, and it leaves the
 * score's denominator without leaving the report.
 */
#[CoversClass(EquivalentMarkers::class)]
#[CoversClass(MutantGenerator::class)]
#[CoversClass(MutationRunner::class)]
#[CoversClass(MutationReport::class)]
final class EquivalentMarkersTest extends TestCase
{
    private const string SOURCE = <<<'PHP'
        <?php
        final class Loop
        {
            /** @crucible-equivalent the loop breaks before the bound matters */
            public function first(array $items): mixed
            {
                for ($i = 0; $i < 10; $i++) {
                    return $items[$i] ?? null;
                }

                return null;
            }

            public function region(int $a): int
            {
                // crucible-equivalent-start the log line's wording is not behaviour
                $label = $a + 1;
                // crucible-equivalent-end
                return $a - 1;
            }

            public function line(int $a): int
            {
                return $a * 2; // crucible-equivalent-line doubling is the same as adding it twice here
            }

            public function unreasoned(int $a): int
            {
                return $a + 3; // crucible-equivalent-line
            }
        }
        PHP;

    public function testEachFormCoversItsLinesWithItsReason(): void
    {
        $markers = EquivalentMarkers::scan(self::SOURCE);

        self::assertSame('the loop breaks before the bound matters', $markers->reasonFor(7));
        self::assertSame("the log line's wording is not behaviour", $markers->reasonFor(17));
        self::assertNull($markers->reasonFor(19), 'the line after the region is not covered');
        self::assertSame('doubling is the same as adding it twice here', $markers->reasonFor(24));
        self::assertNull($markers->reasonFor(29), 'a marker without a reason declares nothing');
    }

    public function testEachRangeStartsAndEndsWhereItsDeclarationDoes(): void
    {
        // Every edge at once, as exact ranges: a declaration ending in `;`,
        // a docblock several lines long, braces nested in the body, an
        // -end with no -start, a block comment, and the colon form.
        $source = <<<'PHP'
            <?php
            final class Shapes
            {
                /** @crucible-equivalent: the key order is not observable */
                private array $keys = ['a', 'b'];

                /**
                 * Nested braces stay inside the method.
                 *
                 * @crucible-equivalent the first truthy item ends the loop either way
                 */
                public function nested(array $items): int
                {
                    foreach ($items as $item) {
                        if ($item) {
                            return 1;
                        }
                    }

                    return 0;
                }

                // crucible-equivalent-end with no start is nothing
                public function after(): int
                {
                    return 2; /* crucible-equivalent-line: a block comment counts too */
                }

                // crucible-equivalent-start
                public function unreasoned(): int
                {
                    return 3;
                }
                // crucible-equivalent-end
            }
            PHP;

        self::assertSame([
            ['from' => 5, 'to' => 5, 'line' => 4, 'reason' => 'the key order is not observable'],
            ['from' => 12, 'to' => 21, 'line' => 7, 'reason' => 'the first truthy item ends the loop either way'],
            ['from' => 26, 'to' => 26, 'line' => 26, 'reason' => 'a block comment counts too'],
            ['from' => 29, 'to' => 34, 'line' => 29, 'reason' => null],
        ], EquivalentMarkers::scan($source)->ranges);

        $markers = EquivalentMarkers::scan($source);

        self::assertNull($markers->reasonFor(11), 'the docblock itself is not the declaration');
        self::assertSame('the first truthy item ends the loop either way', $markers->reasonFor(12));
        self::assertSame('the first truthy item ends the loop either way', $markers->reasonFor(21));
        self::assertNull($markers->reasonFor(22), 'the line after the closing brace is not covered');
        self::assertNull($markers->reasonFor(31), 'a region without a reason declares nothing');
    }

    public function testGeneratedMutantsCarryTheReasonAndAreNeverRun(): void
    {
        $mutants  = (new MutantGenerator())->generate('/p/Loop.php', 'Loop', self::SOURCE);
        $declared = array_values(array_unique(array_map(static fn(Mutant $m): int => $m->line, array_values(array_filter($mutants, static fn(Mutant $m): bool => $m->equivalent !== null))), SORT_NUMERIC));

        sort($declared);

        self::assertSame([7, 17, 24], $declared);

        $executor = new class implements MutantExecutor {
            public int $ran = 0;

            public function canRun(Mutant $mutant): bool
            {
                return true;
            }

            public function execute(Mutant $mutant, array $coveringTestIds): MutationVerdict
            {
                $this->ran++;

                return MutationVerdict::escaped($mutant, 0.0);
            }
        };

        $report = (new MutationRunner($executor, null, static fn(Mutant $m): array => ['tests/LoopTest.php::testFirst']))->run($mutants);

        $equivalent = $report->count(MutationOutcome::Equivalent);

        self::assertGreaterThan(0, $equivalent);
        self::assertSame($report->total() - $equivalent, $executor->ran, 'a declared mutant is never run');
        self::assertSame($report->total() - $equivalent, $report->covered(), 'it leaves the denominator');
    }

    public function testTheManualsExampleDeclaresTheMutantsItSays(): void
    {
        // examples/06-equivalent-mutants is where MANUAL.md's sample
        // comes from: first()'s loop and restock()'s log line are
        // declared, each with its own reason, and nothing else is.
        $file    = dirname(__DIR__, 3) . '/examples/06-equivalent-mutants/InventoryTest.php';
        $mutants = (new MutantGenerator())->generate($file, 'Inventory', (string) file_get_contents($file));
        $reasons = [];

        foreach ($mutants as $mutant) {
            if ($mutant->equivalent !== null) {
                $reasons[$mutant->line] = $mutant->equivalent;
            }
        }

        ksort($reasons);

        self::assertSame([
            27 => 'the loop returns before the bound matters',
            36 => 'the log wording is not behaviour',
        ], $reasons);
    }
}
