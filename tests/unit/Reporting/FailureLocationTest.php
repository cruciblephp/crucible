<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Reporting;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Frame;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\FailureLocation;
use LucianoPereira\Crucible\Test\TestId;

#[CoversClass(FailureLocation::class)]
final class FailureLocationTest extends TestCase
{
    private const string ROOT = '/project';

    public function testAFailureNamesTheAssertionLineInTheTestFile(): void
    {
        $failure = new Failure('Failed asserting that 120 is identical to 121.', trace: [
            // The exception is built inside the assertion library, so the
            // first frame is not the line the author wrote.
            new Frame('/project/vendor/crucible/src/Assert/Constraint/Constraint.php', 90),
            new Frame('/project/tests/unit/CartTotalTest.php', 13),
        ]);

        $this->assertSame(
            'tests/unit/CartTotalTest.php:13',
            FailureLocation::of($failure, new TestId('tests/unit/CartTotalTest.php', 'testTotalIncludesTax'), Outcome::Failed, self::ROOT),
        );
    }

    public function testAWindowsDrivePathIsAbsoluteAndNamesTheTestFile(): void
    {
        $failure = new Failure('Failed asserting that 120 is identical to 121.', trace: [
            new Frame('D:\a\crucible\crucible\src\Assert\Constraint\Constraint.php', 90),
            new Frame('D:\a\crucible\crucible\tests\unit\CartTotalTest.php', 13),
        ]);

        $this->assertSame(
            'tests/unit/CartTotalTest.php:13',
            FailureLocation::of($failure, new TestId('tests/unit/CartTotalTest.php', 'testTotalIncludesTax'), Outcome::Failed, 'D:\a\crucible\crucible'),
        );
    }

    public function testAnErrorNamesTheLineItWasThrownAtEvenOutsideTheTest(): void
    {
        $failure = new Failure('cart is empty', trace: [
            new Frame('/project/src/Cart.php', 42, 'total'),
            new Frame('/project/tests/unit/CartTest.php', 10),
        ]);

        $this->assertSame(
            'src/Cart.php:42',
            FailureLocation::of($failure, new TestId('tests/unit/CartTest.php', 'testEmptyCartThrows'), Outcome::Errored, self::ROOT),
        );
    }

    public function testAFailureWithNoFrameInTheTestFallsBackToTheThrowSite(): void
    {
        $failure = new Failure('boom', trace: [
            new Frame('/project/vendor/package/src/Runner.php', 7),
        ]);

        $this->assertSame(
            'vendor/package/src/Runner.php:7',
            FailureLocation::of($failure, new TestId('tests/unit/CartTest.php', 'testBoom'), Outcome::Failed, self::ROOT),
        );
    }

    public function testNoTraceHasNoLocation(): void
    {
        $this->assertNull(
            FailureLocation::of(new Failure('no trace'), new TestId('tests/unit/CartTest.php', 'testBoom'), Outcome::Failed, self::ROOT),
        );
    }

    public function testAFileOutsideTheProjectKeepsItsAbsolutePath(): void
    {
        $failure = new Failure('boom', trace: [
            new Frame('/elsewhere/Cart.php', 3),
        ]);

        $this->assertSame(
            '/elsewhere/Cart.php:3',
            FailureLocation::of($failure, new TestId('tests/unit/CartTest.php', 'testBoom'), Outcome::Errored, self::ROOT),
        );
    }
}
