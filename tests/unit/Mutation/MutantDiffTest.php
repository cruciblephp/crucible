<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\MutantDiff;

#[CoversClass(MutantDiff::class)]
final class MutantDiffTest extends TestCase
{
    public function testAnOperatorSwapIsTheOneLineBeforeAndAfter(): void
    {
        self::assertSame(
            ['- $precision === null && $scale === null => \'number\',', '+ $precision === null || $scale === null => \'number\','],
            MutantDiff::of(
                "<?php\nreturn match (true) {\n    \$precision === null && \$scale === null => 'number',\n};\n",
                "<?php\nreturn match (true) {\n    \$precision === null || \$scale === null => 'number',\n};\n",
            ),
        );
    }

    public function testADroppedElementIsItsLinesWithNothingAdded(): void
    {
        self::assertSame(
            ["- 'id'      => 'int',"],
            MutantDiff::of("<?php\nreturn [\n    'id'      => 'int',\n    'name' => 'string',\n];\n", "<?php\nreturn [\n    'name' => 'string',\n];\n"),
        );
    }

    public function testAnUnchangedSourceIsNoDiff(): void
    {
        self::assertSame([], MutantDiff::of("<?php\n", "<?php\n"));
    }
}
