<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan;

use LucianoPereira\Crucible\Assert\Assert;

use stdClass;

/*
 * Analyzed by AssertAnnotationParityTest with NO extension included,
 * never executed: every narrowing below comes from the portable
 * docblocks on Assert alone (D-173) — the answers an analyser that has
 * never heard of Crucible reads straight from the tags, the way Mago
 * and Psalm read them, and the incumbent's own Assert has answered
 * since it grew the same 48 tags.
 *
 * One root parameter per case, no copies: PHPStan follows a narrowed
 * copy back onto the variable it came from, so a shared seed would
 * hand later cases the narrowing of an earlier one instead of their
 * own.
 */

/** @phpcpd-keep Analysed by AssertAnnotationParityTest through phpstan, never referenced in code. */
final class PortableAnnotationFixture
{
    /**
     * @param int|string|null $union the union every narrowing starts from
     */
    public function narrowedFromTheTags(
        int|string|null $union,
        int|string|null $also,
        int|string|null $again,
        int|string|null $onceMore,
        int|string|null $still,
        mixed $anything,
        mixed $whatever,
        mixed $something,
        mixed $more,
    ): void
    {
        Assert::assertIsString($union);
        \PHPStan\dumpType($union);

        Assert::assertNotNull($also);
        \PHPStan\dumpType($also);

        Assert::assertIsNotString($again);
        \PHPStan\dumpType($again);

        Assert::assertIsNumeric($onceMore);
        \PHPStan\dumpType($onceMore);

        $flag = $union === 'x';
        Assert::assertNotTrue($flag);
        \PHPStan\dumpType($flag);

        Assert::assertInstanceOf(stdClass::class, $anything);
        \PHPStan\dumpType($anything);

        Assert::assertIsList($whatever);
        \PHPStan\dumpType($whatever);

        Assert::assertIsClosedResource($something);
        \PHPStan\dumpType($something);

        $rows = [$also, $also];
        Assert::assertContainsOnlyInt($rows);
        \PHPStan\dumpType($rows);

        $items = [$more, $more];
        Assert::assertContainsOnlyInstancesOf(stdClass::class, $items);
        \PHPStan\dumpType($items);

        $expected = 'const';
        $actual   = $still;
        Assert::assertSame($expected, $actual);
        \PHPStan\dumpType($actual);
    }
}
