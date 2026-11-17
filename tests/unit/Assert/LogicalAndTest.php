<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\Crucible\Assert\Constraint\GreaterThan;
use LucianoPereira\Crucible\Assert\Constraint\LessThan;
use LucianoPereira\Crucible\Assert\Constraint\LogicalAnd;
use LucianoPereira\Crucible\Assert\Constraint\LogicalOr;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use LucianoPereira\Crucible\Framework\TestCase;
use Throwable;

use function class_exists;

/**
 * Symfony's BrowserKitAssertionsTrait and DomCrawlerAssertionsTrait
 * compose their constraints with `LogicalAnd::fromConstraints(...)`
 * under the incumbent's name; assertResponseRedirects() and
 * assertSelectorTextContains() could not run without it (F-03).
 */
#[CoversClass(LogicalAnd::class)]
final class LogicalAndTest extends TestCase
{
    public function testItPassesWhenEveryConstraintDoes(): void
    {
        self::assertThat(5, new LogicalAnd(new GreaterThan(1), new LessThan(10)));
        self::assertThat(5, LogicalAnd::fromConstraints(new GreaterThan(1), new LessThan(10)));
    }

    public function testOneFailingConstraintFailsTheWhole(): void
    {
        $message = $this->failureOf(5, new LogicalAnd(new GreaterThan(1), new LessThan(2), new GreaterThan(0)));

        self::assertStringContainsString('is less than 2', $message);
    }

    public function testZeroConstraintsIsVacuouslyTrueLikePhpUnits(): void
    {
        self::assertThat('anything', LogicalAnd::fromConstraints());
        self::assertTrue((new LogicalAnd())->matches('anything'));
    }

    public function testTheFailureWordingJoinsEachConstraintWithAnd(): void
    {
        $message = $this->failureOf(5, new LogicalAnd(new GreaterThan(1), new LessThan(3)));

        self::assertSame('Failed asserting that 5 is greater than 1 and is less than 3.', $message);
    }

    public function testTheIncumbentNamesResolveToTheConstraints(): void
    {
        PhpUnitCompatibility::load();

        self::assertTrue(class_exists('PHPUnit\Framework\Constraint\LogicalAnd'));
        self::assertTrue(class_exists('PHPUnit\Framework\Constraint\LogicalOr'));

        $and = \PHPUnit\Framework\Constraint\LogicalAnd::fromConstraints(new GreaterThan(1), new LessThan(10));

        self::assertInstanceOf(LogicalAnd::class, $and);
        self::assertThat(5, $and);
        self::assertInstanceOf(LogicalOr::class, new \PHPUnit\Framework\Constraint\LogicalOr(new GreaterThan(1)));
    }

    private function failureOf(mixed $value, Constraint $constraint): string
    {
        try {
            self::assertThat($value, $constraint);
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        } catch (Throwable $throwable) {
            self::fail('Unexpected failure type: ' . $throwable::class . ': ' . $throwable->getMessage());
        }

        self::fail('The constraint unexpectedly passed.');
    }
}
