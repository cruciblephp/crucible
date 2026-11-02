<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Types\Observed;

use ArrayObject;
use Countable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Dialect\BrewsCoffee;
use LucianoPereira\Crucible\Types\Observed\NameContext;
use LucianoPereira\Crucible\Types\Observed\ValueTypes;
use stdClass;

use function array_fill;

#[CoversClass(ValueTypes::class)]
#[CoversClass(NameContext::class)]
final class TypeReadingTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function values(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'positive int' => [3, 'positive-int'];
        yield 'zero' => [0, '0'];
        yield 'negative int' => [-3, 'negative-int'];
        yield 'float' => [1.5, 'float'];
        yield 'empty string' => ['', "''"];
        yield 'string' => ['tea', 'non-empty-string'];
        yield 'class name' => [Countable::class, 'class-string<\Countable>'];
        yield 'trait name' => [BrewsCoffee::class, 'class-string<\\' . BrewsCoffee::class . '>'];
        yield 'empty array' => [[], 'array{}'];
        yield 'list' => [[1, 'a'], 'non-empty-list<non-empty-string|positive-int>'];
        yield 'shape' => [['id' => 1, 'my key' => null], "array{id: positive-int, 'my key': null}"];
        yield 'map' => [[3 => 'a', 'x' => 'b'], 'non-empty-array<int|string, non-empty-string>'];
        yield 'deep' => [[[[[1]]]], 'non-empty-list<non-empty-list<non-empty-list<non-empty-list<mixed>>>>'];
        yield 'object' => [new stdClass(), '\stdClass'];
        yield 'anonymous class' => [new class extends ArrayObject {}, '\ArrayObject'];
        yield 'closure' => [static fn(): int => 1, '\Closure'];
        yield 'long list' => [[...array_fill(0, 16, 1), 'past the sixteenth'], 'non-empty-list<positive-int>'];
    }

    #[DataProvider('values')]
    public function testAValueIsTheNarrowestTypeThatNamesIt(mixed $value, string $type): void
    {
        self::assertSame($type, ValueTypes::of($value));
    }

    public function testNamesResolveWhereTheDocblockIsWritten(): void
    {
        $context = NameContext::ofSource(
            "<?php\nnamespace App\\Formatter;\nuse App\\Record;\nuse Vendor\\{Bag, Box as Crate};\nuse function strlen;\n"
            . "final class Line { use Shared; }\n",
        );

        self::assertSame('list<\App\Record>', $context->resolve('list<Record>'));
        self::assertSame('array{id: int<0, max>, crate: ?\Vendor\Box, bag: \Vendor\Bag\Item}', $context->resolve('array{id: int<0, max>, crate: ?Crate, bag: Bag\Item}'));
        self::assertSame('non-empty-string|class-string<\App\Formatter\Thing>', $context->resolve('non-empty-string|class-string<Thing>'));
        self::assertSame("'on'|42|self|\\Shared", $context->resolve("'on'|42|self|\\Shared"));
    }

    public function testATraitUseIsNoImport(): void
    {
        self::assertSame('\App\Shared', NameContext::ofSource("<?php\nnamespace App;\nfinal class Line { use Shared; }\n")->resolve('Shared'));
        self::assertSame('\Shared', NameContext::ofSource("<?php\nfinal class Line {}\n")->resolve('Shared'));
    }
}
