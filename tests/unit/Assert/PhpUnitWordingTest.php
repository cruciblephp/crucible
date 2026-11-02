<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use ArrayObject;
use Closure;
use Countable;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\GeneratorNotSupportedException;
use LucianoPereira\Crucible\Assert\PhpUnitExporter;
use LucianoPereira\Crucible\Assert\UnknownClassOrInterfaceException;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Framework\TestCase;
use stdClass;

use function explode;
use function fclose;
use function fopen;
use function preg_replace;
use function str_repeat;

/**
 * A failing assertion reads as PHPUnit 13.4.3 writes it. Each expected
 * sentence is that version's, from the same call; the comparison diff
 * under it stays Crucible's.
 */
#[CoversClass(Assert::class)]
#[CoversClass(PhpUnitExporter::class)]
final class PhpUnitWordingTest extends TestCase
{
    /** @return iterable<string, array{Closure(): void, string}> */
    public static function failures(): iterable
    {
        $object    = new stdClass();
        $object->a = 1;

        yield 'equal scalars' => [static fn() => Assert::assertEquals(8, 7), 'Failed asserting that 7 matches expected 8.'];
        yield 'equal null' => [static fn() => Assert::assertEquals(null, 1), 'Failed asserting that 1 matches expected null.'];
        yield 'equal strings' => [static fn() => Assert::assertEquals('a', 'b'), 'Failed asserting that two strings are equal.'];
        yield 'not equal arrays' => [static fn() => Assert::assertNotEqualsCanonicalizing([1, 2], [2, 1]), "Failed asserting that Array &0 [\n    0 => 2,\n    1 => 1,\n] is not equal to Array &0 [\n    0 => 1,\n    1 => 2,\n]."];
        yield 'not equal text' => [static fn() => Assert::assertNotEquals("a\nb", "a\nb"), "Failed asserting that 'a\\n\nb' is not equal to <text>."];
        yield 'not equal with delta' => [static fn() => Assert::assertNotEqualsWithDelta(1.0, 1.05, 0.1), 'Failed asserting that 1.05 is not equal to 1.0 with delta <0.100000>.'];
        yield 'greater or equal' => [static fn() => Assert::assertGreaterThanOrEqual(5, 1), 'Failed asserting that 1 is equal to 5 or is greater than 5.'];
        yield 'less or equal' => [static fn() => Assert::assertLessThanOrEqual(1, 5), 'Failed asserting that 5 is equal to 1 or is less than 1.'];
        yield 'not count' => [static fn() => Assert::assertNotCount(1, [1]), 'Failed asserting that actual size 1 does not match expected size 1.'];
        yield 'contains only' => [static fn() => Assert::assertContainsOnlyInt(['1']), "Failed asserting that Array &0 [\n    0 => '1',\n] contains only values of type \"int\"."];
        yield 'contains not only' => [static fn() => Assert::assertContainsNotOnlyString(['a']), "Failed asserting that Array &0 [\n    0 => 'a',\n] does not contain only values of type \"string\"."];
        yield 'contains in array' => [static fn() => Assert::assertContains(3, [1, 2]), 'Failed asserting that an array contains 3.'];
        yield 'contains in traversable' => [static fn() => Assert::assertContains(3, new ArrayObject([1])), 'Failed asserting that a traversable contains 3.'];
        yield 'has key' => [static fn() => Assert::assertArrayHasKey('x', self::keyed()), "Failed asserting that an array has the key 'x'."];
        yield 'empty integer' => [static fn() => Assert::assertEmpty(1), 'Failed asserting that a integer is empty.'];
        yield 'empty object' => [static fn() => Assert::assertEmpty(new ArrayObject([1])), 'Failed asserting that an object is empty.'];
        yield 'not empty string' => [static fn() => Assert::assertNotEmpty(''), 'Failed asserting that a string is not empty.'];
        yield 'true of an array' => [static fn() => Assert::assertTrue([1]), 'Failed asserting that an array is true.'];
        yield 'null of an object' => [static fn() => Assert::assertNull($object), 'Failed asserting that an instance of class stdClass is null.'];
        yield 'type of an object' => [static fn() => Assert::assertIsString($object), "Failed asserting that stdClass Object #N (\n    'a' => 1,\n) is of type string."];
        yield 'list of a string' => [static fn() => Assert::assertIsList(['a' => 1]), 'Failed asserting that an array is a list.'];
        yield 'instance of a class' => [static fn() => Assert::assertInstanceOf(ArrayObject::class, new stdClass()), 'Failed asserting that an instance of class stdClass is an instance of class ArrayObject.'];
        yield 'instance of an interface' => [static fn() => Assert::assertInstanceOf(Countable::class, 1), 'Failed asserting that an integer is an instance of interface Countable.'];
        yield 'not same object' => [static fn() => Assert::assertNotSame($object, $object), 'Failed asserting that two variables do not reference the same object.'];
        yield 'not same strings' => [static fn() => Assert::assertNotSame('a', 'a'), 'Failed asserting that two strings are not identical.'];
        yield 'objects equal' => [static fn() => Assert::assertObjectNotEquals(new WordedMoney(1), new WordedMoney(1)), 'Failed asserting that two objects are not equal.'];
        yield 'property' => [static fn() => Assert::assertObjectHasProperty('b', $object), 'Failed asserting that object of class "stdClass" has property "b".'];
        yield 'file' => [static fn() => Assert::assertFileExists('/no/such/file'), 'Failed asserting that file "/no/such/file" exists.'];
        yield 'directory' => [static fn() => Assert::assertDirectoryExists('/no/such/directory'), 'Failed asserting that directory "/no/such/directory" exists.'];
        yield 'readable' => [static fn() => Assert::assertIsReadable('/no/such/file'), 'Failed asserting that "/no/such/file" is readable.'];
        yield 'contains string' => [static fn() => Assert::assertStringContainsString('x', 'ñandú'), "Failed asserting that 'ñandú' [UTF-8](length: 7) contains \"x\" [ASCII](length: 1)."];
        yield 'contains ignoring case' => [static fn() => Assert::assertStringNotContainsStringIgnoringCase('A', 'abc'), "Failed asserting that 'abc' [Encoding ignored](length: 3) does not contain \"a\" [Encoding ignored](length: 1)."];
        yield 'ignoring line endings' => [static fn() => Assert::assertStringContainsStringIgnoringLineEndings('x', "a\r\nb"), "Failed asserting that 'a\\r\\n\nb' [ASCII](length: 3) contains \"x\" [ASCII](length: 1)."];
        yield 'ignoring whitespace' => [static fn() => Assert::assertStringEqualsStringIgnoringWhitespace(" a \t b ", 'x'), 'Failed asserting that \'x\' is equal to "a b" ignoring whitespace.'];
        yield 'starts not with' => [static fn() => Assert::assertStringStartsNotWith('a', 'abc'), 'Failed asserting that \'abc\' does not start with "a".'];
        yield 'format' => [static fn() => Assert::assertStringMatchesFormat('n=%d', 'n=x'), 'Failed asserting that string matches format description.'];
        yield 'xml' => [static fn() => Assert::assertXmlStringEqualsXmlString('<r/>', '<s/>'), 'Failed asserting that two DOM documents are equal.'];
        yield 'xml negated' => [static fn() => Assert::assertXmlStringNotEqualsXmlString('<r/>', '<r/>'), 'Failed asserting that DOMDocument Object #N () is not equal to DOMDocument Object #N ().'];
        yield 'json syntax' => [static fn() => Assert::assertJson('{x'), 'Failed asserting that a string is valid JSON (Syntax error, malformed JSON).'];
        yield 'json state' => [static fn() => Assert::assertJson('[1}'), 'Failed asserting that a string is valid JSON (Underflow or the modes mismatch).'];
        yield 'json empty' => [static fn() => Assert::assertJson(''), 'Failed asserting that an empty string is valid JSON.'];
    }

    /** @param Closure(): void $assertion */
    #[DataProvider('failures')]
    public function testTheFailureIsPhpUnitsSentence(Closure $assertion, string $expected): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            self::assertSame($expected, preg_replace('/#\d+/', '#N', explode("\n--- Expected", $failure->getMessage())[0]));

            return;
        }

        self::fail('the assertion passed');
    }

    public function testATypeThatDoesNotExistIsAnErrorNotAFailure(): void
    {
        $this->expectException(UnknownClassOrInterfaceException::class);
        $this->expectExceptionMessage('Class or interface "Nope\X" does not exist');

        Assert::assertInstanceOf('Nope\X', new stdClass()); // @phpstan-ignore argument.type (a type that does not exist is the point)
    }

    public function testAGeneratorIsNotCounted(): void
    {
        $this->expectException(GeneratorNotSupportedException::class);
        $this->expectExceptionMessage('Passing an argument of type Generator for the $haystack parameter is not supported');

        Assert::assertCount(0, (static function () {
            yield 1;
        })());
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return ArrayObject<array-key, mixed>
     */
    private static function keyed(array $items = ['a' => 1]): ArrayObject
    {
        return new ArrayObject($items);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function values(): iterable
    {
        $closed = fopen('php://memory', 'r');
        self::assertIsResource($closed);
        fclose($closed);
        $self       = new WordedNode();
        $self->next = $self;

        yield 'float' => [1.0, '1.0'];
        yield 'nan' => [NAN, 'NAN'];
        yield 'line breaks' => ["a\r\nb", "'a\\r\\n\nb'"];
        yield 'lone carriage return' => ["a\rb", "'a\\r\nb'"];
        yield 'nul' => ["a\0b", 'Binary String: 0x610062'];
        yield 'nested arrays' => [['k' => 1, 'j' => [2]], "Array &0 [\n    'k' => 1,\n    'j' => Array &1 [\n        0 => 2,\n    ],\n]"];
        yield 'recursion' => [$self, "LucianoPereira\\Crucible\\Tests\\Assert\\WordedNode Object #N (\n    'next' => LucianoPereira\\Crucible\\Tests\\Assert\\WordedNode Object #N,\n)"];
        yield 'closed resource' => [$closed, 'resource (closed)'];
        yield 'long string' => [str_repeat('a', 50), "'" . str_repeat('a', 50) . "'"];
    }

    #[DataProvider('values')]
    public function testAValueIsExportedAsPhpUnitExportsIt(mixed $value, string $expected): void
    {
        self::assertSame($expected, preg_replace('/#\d+/', '#N', PhpUnitExporter::export($value)));
    }
}

final class WordedMoney
{
    public function __construct(public int $amount) {}

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }
}

final class WordedNode
{
    public ?self $next = null;
}
