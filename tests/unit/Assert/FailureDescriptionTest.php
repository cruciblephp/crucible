<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use FrameworkFixtures\RowsListed;
use LogicException;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Framework\TestCase;

/**
 * A constraint's additional failure description goes on a line of its own
 * (D-147), as PHPUnit 13.4.3 writes it: measured on the same constraint,
 * "Failed asserting that 1 is fine.\nEXTRA". 1.1.5 glued the two together.
 */
final class FailureDescriptionTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../_fixtures/framework/ForeignConstraint.php';
    }

    public function testTheExtraDescriptionStartsANewLine(): void
    {
        $this->assertSame("Failed asserting that a row [widgets] is in the table.\nRows: none", $this->failureOf(new RowsListed('Rows: none')));
    }

    public function testAnExtraDescriptionThatStartsWithANewlineKeepsTheBlankLine(): void
    {
        $this->assertSame("Failed asserting that a row [widgets] is in the table.\n\nRows: none", $this->failureOf(new RowsListed("\nRows: none")));
    }

    public function testAnEmptyExtraDescriptionAddsNoLine(): void
    {
        $this->assertSame('Failed asserting that a row [widgets] is in the table.', $this->failureOf(new RowsListed('')));
    }

    public function testACustomMessageComesFirstOnItsOwnLine(): void
    {
        try {
            Assert::assertThat('widgets', new RowsListed('Rows: none'), 'the import ran');
        } catch (AssertionFailedError $failure) {
            $this->assertSame("the import ran\nFailed asserting that a row [widgets] is in the table.\nRows: none", $failure->getMessage());

            return;
        }

        throw new LogicException('The constraint was expected to fail.');
    }

    public function testAShapeMismatchIsOneLineBelowTheSentence(): void
    {
        try {
            Assert::assertMatchesShape('array{id: positive-int}', ['id' => 0]);
        } catch (AssertionFailedError $failure) {
            $this->assertSame("Failed asserting that an array matches the type array{id: positive-int}.\n\$.id: expected positive-int, got 0", $failure->getMessage());

            return;
        }

        throw new LogicException('The shape was expected not to match.');
    }

    public function testADiffStillFollowsTheSentence(): void
    {
        try {
            Assert::assertSame('a', 'b');
        } catch (AssertionFailedError $failure) {
            $this->assertStringStartsWith("Failed asserting that two strings are identical.\n--- Expected\n", $failure->getMessage());

            return;
        }

        throw new LogicException('assertSame was expected to fail.');
    }

    private function failureOf(RowsListed $constraint): string
    {
        try {
            $constraint->evaluate('widgets');
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        throw new LogicException('The constraint was expected to fail.');
    }
}
