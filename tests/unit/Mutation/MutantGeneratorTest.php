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
use LucianoPereira\Crucible\Mutation\ArithmeticMutator;
use LucianoPereira\Crucible\Mutation\ComparisonMutator;
use LucianoPereira\Crucible\Mutation\LogicalMutator;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutantGenerator;
use LucianoPereira\Crucible\Mutation\OperatorMutator;
use LucianoPereira\Crucible\Mutation\TokenMutation;

use function array_map;

#[CoversClass(MutantGenerator::class)]
#[CoversClass(OperatorMutator::class)]
#[CoversClass(ArithmeticMutator::class)]
#[CoversClass(ComparisonMutator::class)]
#[CoversClass(LogicalMutator::class)]
#[CoversClass(TokenMutation::class)]
#[CoversClass(Mutant::class)]
final class MutantGeneratorTest extends TestCase
{
    public function testAMutantSwapsExactlyOneOperatorAndPreservesEverythingElse(): void
    {
        $source  = "<?php\n\nreturn 1 + 2;\n";
        $mutants = (new MutantGenerator())->generate('/project/src/Sum.php', 'Sum', $source);

        self::assertCount(1, $mutants);
        self::assertSame("<?php\n\nreturn 1 - 2;\n", $mutants[0]->mutatedSource, 'Only the operator changes.');
        self::assertSame('arithmetic', $mutants[0]->mutatorId);
        self::assertSame(3, $mutants[0]->line, 'The + is on line 3.');
        self::assertSame('Sum', $mutants[0]->class);
        self::assertSame('/project/src/Sum.php', $mutants[0]->file);
    }

    public function testEveryOperatorOnALineBecomesItsOwnMutant(): void
    {
        $mutants = (new MutantGenerator())->generate('/x/F.php', 'F', "<?php\nreturn 1 + 2 * 3;\n");

        // The + and the * are independent mutation points.
        self::assertSame(
            ["<?php\nreturn 1 - 2 * 3;\n", "<?php\nreturn 1 + 2 / 3;\n"],
            array_map(static fn(Mutant $m): string => $m->mutatedSource, $mutants),
        );
    }

    public function testComparisonAndLogicalRulesEachFire(): void
    {
        $mutants = (new MutantGenerator())->generate('/x/F.php', 'F', "<?php\n\$r = \$a < \$b && \$c;\n");

        $sources = array_map(static fn(Mutant $m): string => $m->mutatedSource, $mutants);

        self::assertContains("<?php\n\$r = \$a > \$b && \$c;\n", $sources, 'comparison < → >');
        self::assertContains("<?php\n\$r = \$a < \$b || \$c;\n", $sources, 'logical && → ||');
    }

    public function testSourceWithNoOperatorsYieldsNoMutants(): void
    {
        self::assertSame([], (new MutantGenerator())->generate('/x/F.php', 'F', "<?php\n\$x = 'a plus b';\n"));
    }

    public function testTheCatalogIsInjectable(): void
    {
        $onlyArithmetic = new MutantGenerator([new ArithmeticMutator()]);

        $mutants = $onlyArithmetic->generate('/x/F.php', 'F', "<?php\nreturn 1 + (2 < 3);\n");

        // The comparison is not in this catalog, so only the + mutates.
        self::assertSame(
            ['arithmetic'],
            array_map(static fn(Mutant $m): string => $m->mutatorId, $mutants),
        );
    }
}
