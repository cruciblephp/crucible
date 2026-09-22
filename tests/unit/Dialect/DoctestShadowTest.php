<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Inline\DoctestShadow;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_values;
use function explode;

#[CoversClass(DoctestShadow::class)]
final class DoctestShadowTest extends TestCase
{
    public function testShadowsCarryNamespaceExpressionsAndTheLineMap(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App\Math;

            final class Calc
            {
                /**
                 * @crucible expect((new Calc())->add(1, 2))->toBe(3)
                 * @crucible expect((new Calc())->add(0, 0))->toBe(0)
                 */
                public function add(int $a, int $b): int
                {
                    return $a + $b;
                }
            }
            PHP;

        $shadow = DoctestShadow::fromSource($source);

        self::assertNotNull($shadow);
        self::assertSame(2, $shadow->count);
        self::assertStringContainsString('namespace App\Math;', $shadow->content);
        self::assertStringContainsString('static fn () => expect((new Calc())->add(1, 2))->toBe(3),', $shadow->content);

        // The map points every expression at its origin docblock line.
        self::assertSame([8, 9], array_values($shadow->lines));

        foreach ($shadow->lines as $shadowLine => $originLine) {
            $line = explode("\n", $shadow->content)[$shadowLine - 1];

            self::assertStringContainsString('static fn () =>', $line);
        }
    }

    public function testAOneLineDocblockLosesItsCloser(): void
    {
        // The closer riding the tag's line — the InlineBuilder rule.
        // (A pure one-liner /** @crucible ... */ is NOT a doctest: the
        // engine's grammar wants the tag on a starred line.)
        $shadow = DoctestShadow::fromSource(
            "<?php\n\n/**\n * @crucible expect(1)->toBe(1) */\nfunction one(): int { return 1; }\n",
        );

        self::assertNotNull($shadow);
        self::assertStringContainsString('static fn () => expect(1)->toBe(1),', $shadow->content);
        $this->assertStringNotContainsString('*/', explode("return [\n", $shadow->content)[1]);
    }

    public function testAGlobalNamespaceFileGetsNoNamespaceStatement(): void
    {
        $shadow = DoctestShadow::fromSource("<?php\n\n/**\n * @crucible expect(true)->toBeTrue()\n */\nfunction f(): bool { return true; }\n");

        self::assertNotNull($shadow);
        $this->assertStringNotContainsString('namespace ', $shadow->content);
    }

    public function testCrucibleMentionsOutsideDocblocksAreNotDoctests(): void
    {
        self::assertNull(DoctestShadow::fromSource(
            "<?php\n\n\$x = '@crucible expect(1)->toBe(2)';\n// @crucible expect(1)->toBe(2)\n",
        ));
    }
}
