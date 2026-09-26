<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Types;

use ArrayIterator;
use ArrayObject;
use Countable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Types\TypeExpression;
use LucianoPereira\Crucible\Types\TypeParser;
use RuntimeException;
use SplObjectStorage;

use function get_debug_type;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * What a misfit says (D-131): the first place the value does not fit, as
 * a path from the root — the part of the failure that tells someone what
 * to change. Which values fit is TypeExpressionAgreementTest's, against
 * PHPStan.
 */
#[CoversClass(TypeExpression::class)]
#[CoversClass(TypeParser::class)]
final class TypeExpressionTest extends TestCase
{
    private const string USER = 'array{id: positive-int, email: non-empty-string, tags: list<string>, address?: array{city: string}}';

    public function testAFittingValueHasNoMismatch(): void
    {
        self::assertNull(TypeExpression::parse(self::USER)->mismatch(['id' => 1, 'email' => 'a@b', 'tags' => []]));
    }

    public function testEachBoundaryFallsOnTheSideItsTypeSays(): void
    {
        // The edges the differential test against PHPStan reads too, here
        // in process: that test runs PHPStan, and is too slow to be the
        // one that notices a boundary moving.
        $cases = [
            ['non-negative-int', 0, true],
            ['non-negative-int', -1, false],
            ['int<1, 10>', 1, true],
            ['int<1, 10>', 10, true],
            ['int<1, 10>', 0, false],
            ['int<1, 10>', 11, false],
            ['int<min, 5>', PHP_INT_MIN, true],
            ['int<5, max>', PHP_INT_MAX, true],
            ['class-string', Countable::class, true],
            ['class-string', \LucianoPereira\Crucible\Event\Outcome::class, true],
            ['class-string', 'No\\Such\\ClassName', false],
            ['class-string<\Throwable>', RuntimeException::class, true],
            ['class-string<\Throwable>', ArrayObject::class, false],
            ['class-string<\Throwable>', 7, false],
            ['\Countable&\ArrayAccess', new ArrayObject(), true],
            ['\Countable&\ArrayAccess', new SplObjectStorage(), true],
            ['\Countable&\ArrayAccess', 'x', false],
            ['\Countable&\Stringable', new ArrayObject(), false],
            ['iterable<int>', new ArrayIterator([1, 2]), true],
            ['array<int>', new ArrayIterator([1, 2]), false],
            ['iterable<int>', 'x', false],
        ];

        $wrong = [];

        foreach ($cases as [$type, $value, $fits]) {
            if ((TypeExpression::parse($type)->mismatch($value) === null) !== $fits) {
                $wrong[] = $type . ' ← ' . get_debug_type($value) . ' should ' . ($fits ? 'fit' : 'not fit');
            }
        }

        self::assertSame([], $wrong);
    }

    public function testTheFirstMisfitIsNamedByItsPath(): void
    {
        $type = TypeExpression::parse(self::USER);

        self::assertSame('$.id: expected positive-int, got 0', $type->mismatch(['id' => 0, 'email' => 'a', 'tags' => []]));
        self::assertSame('$.email: missing', $type->mismatch(['id' => 1, 'tags' => []]));
        self::assertSame("\$.tags[1]: expected string, got 7", $type->mismatch(['id' => 1, 'email' => 'a', 'tags' => ['a', 7]]));
        self::assertSame("\$.address.city: expected string, got null", $type->mismatch(['id' => 1, 'email' => 'a', 'tags' => [], 'address' => ['city' => null]]));
    }

    public function testKeysAreCheckedWhereTheTypeNamesThem(): void
    {
        self::assertSame('$[0] (key): expected string, got 0', TypeExpression::parse('array<string, int>')->mismatch([1]));
    }

    public function testATypeThatDoesNotReadIsRefusedWithItsPosition(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Cannot read the type "array{id: int" at its end: expected }.');

        TypeExpression::parse('array{id: int');
    }

    public function testTheRefusalPointsAtTheTokenThatDidNotRead(): void
    {
        // The bound is read before it is judged: the position is the
        // token's own, one back from where the reading stopped.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Cannot read the type "int<x, 5>" at offset 4: expected an integer or min.');

        TypeExpression::parse('int<x, 5>');
    }

    public function testShapeKeysAreReadInEveryQuotingAndAsIntegers(): void
    {
        $shape = TypeExpression::parse('array{"quoted key": int, \'single\': int, 3: int}');

        self::assertNull($shape->mismatch(['quoted key' => 1, 'single' => 2, 3 => 3]));
        self::assertSame('$.quoted key: expected int, got \'x\'', $shape->mismatch(['quoted key' => 'x', 'single' => 2, 3 => 3]));
        self::assertSame('$[3]: missing', $shape->mismatch(['quoted key' => 1, 'single' => 2]));
    }

    public function testAnUnknownGenericIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Collection<…> is not a type this reads');

        TypeExpression::parse('Collection<int>');
    }
}
