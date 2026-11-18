<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\AssertConditions;
use ReflectionClass;
use ReflectionMethod;

use function array_diff;
use function array_keys;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function sort;

/**
 * The portable assert annotations (D-173): every narrowing Crucible's
 * PHPStan extension provides is also declared on Assert as a tag
 * `@phpstan-assert`/`@psalm-assert`, so an analyser that cannot load a
 * PHPStan extension — Mago, Psalm — reads the narrowing straight from
 * the docblocks. This gate pins the three-way accounting: what the
 * extension narrows, what the tags promise, and the methods that stay
 * extension-only because no portable tag can say what they prove
 * without lying or losing precision.
 */
#[CoversClass(AssertConditions::class)]
#[Group('phpstan-blackbox')]
final class AssertAnnotationParityTest extends TestCase
{
    /**
     * The annotation each method carries, as the exact docblock lines.
     * The set is the incumbent's own 48 (PHPUnit 12.5.40's Assert) —
     * the tags the reporter's Mago run proved — on Crucible's
     * signatures. PHPStan reads the `@phpstan-assert` form; Psalm and
     * the analysers that speak its vocabulary read `@psalm-assert`.
     *
     * @var array<non-empty-string, list<non-empty-string>>
     */
    private const array ANNOTATIONS = [
        'assertSame'                       => ['@phpstan-assert =ExpectedType $actual', '@psalm-assert ExpectedType $actual'],
        'assertTrue'                       => ['@phpstan-assert true $condition', '@psalm-assert true $condition'],
        'assertNotTrue'                    => ['@phpstan-assert !true $condition', '@psalm-assert !true $condition'],
        'assertFalse'                      => ['@phpstan-assert false $condition', '@psalm-assert false $condition'],
        'assertNotFalse'                   => ['@phpstan-assert !false $condition', '@psalm-assert !false $condition'],
        'assertNull'                       => ['@phpstan-assert null $actual', '@psalm-assert null $actual'],
        'assertNotNull'                    => ['@phpstan-assert !null $actual', '@psalm-assert !null $actual'],
        'assertIsList'                     => ['@phpstan-assert list<mixed> $array', '@psalm-assert list<mixed> $array'],
        'assertInstanceOf'                 => ['@phpstan-assert =ExpectedType $actual', '@psalm-assert ExpectedType $actual'],
        'assertNotInstanceOf'              => ['@phpstan-assert !ExpectedType $actual', '@psalm-assert !ExpectedType $actual'],
        'assertContainsOnlyInstancesOf'    => ['@phpstan-assert iterable<T> $haystack', '@psalm-assert iterable<T> $haystack'],
        'assertIsArray'                    => ['@phpstan-assert array<mixed> $actual', '@psalm-assert array<mixed> $actual'],
        'assertIsNotArray'                 => ['@phpstan-assert !array<mixed> $actual', '@psalm-assert !array<mixed> $actual'],
        'assertIsBool'                     => ['@phpstan-assert bool $actual', '@psalm-assert bool $actual'],
        'assertIsNotBool'                  => ['@phpstan-assert !bool $actual', '@psalm-assert !bool $actual'],
        'assertIsCallable'                 => ['@phpstan-assert callable $actual', '@psalm-assert callable $actual'],
        'assertIsNotCallable'              => ['@phpstan-assert !callable $actual', '@psalm-assert !callable $actual'],
        'assertIsClosedResource'           => ['@phpstan-assert resource $actual', '@psalm-assert resource $actual'],
        'assertIsNotClosedResource'        => ['@phpstan-assert !resource $actual', '@psalm-assert !resource $actual'],
        'assertIsFloat'                    => ['@phpstan-assert float $actual', '@psalm-assert float $actual'],
        'assertIsNotFloat'                 => ['@phpstan-assert !float $actual', '@psalm-assert !float $actual'],
        'assertIsInt'                      => ['@phpstan-assert int $actual', '@psalm-assert int $actual'],
        'assertIsNotInt'                   => ['@phpstan-assert !int $actual', '@psalm-assert !int $actual'],
        'assertIsIterable'                 => ['@phpstan-assert iterable<mixed> $actual', '@psalm-assert iterable<mixed> $actual'],
        'assertIsNotIterable'              => ['@phpstan-assert !iterable<mixed> $actual', '@psalm-assert !iterable<mixed> $actual'],
        'assertIsNumeric'                  => ['@phpstan-assert numeric $actual', '@psalm-assert numeric $actual'],
        'assertIsNotNumeric'               => ['@phpstan-assert !numeric $actual', '@psalm-assert !numeric $actual'],
        'assertIsObject'                   => ['@phpstan-assert object $actual', '@psalm-assert object $actual'],
        'assertIsNotObject'                => ['@phpstan-assert !object $actual', '@psalm-assert !object $actual'],
        'assertIsResource'                 => ['@phpstan-assert resource $actual', '@psalm-assert resource $actual'],
        'assertIsNotResource'              => ['@phpstan-assert !resource $actual', '@psalm-assert !resource $actual'],
        'assertIsScalar'                   => ['@phpstan-assert scalar $actual', '@psalm-assert scalar $actual'],
        'assertIsNotScalar'                => ['@phpstan-assert !scalar $actual', '@psalm-assert !scalar $actual'],
        'assertIsString'                   => ['@phpstan-assert string $actual', '@psalm-assert string $actual'],
        'assertIsNotString'                => ['@phpstan-assert !string $actual', '@psalm-assert !string $actual'],
        'assertContainsOnlyArray'          => ['@phpstan-assert iterable<array<mixed>> $haystack', '@psalm-assert iterable<array<mixed>> $haystack'],
        'assertContainsOnlyBool'           => ['@phpstan-assert iterable<bool> $haystack', '@psalm-assert iterable<bool> $haystack'],
        'assertContainsOnlyCallable'       => ['@phpstan-assert iterable<callable> $haystack', '@psalm-assert iterable<callable> $haystack'],
        'assertContainsOnlyFloat'          => ['@phpstan-assert iterable<float> $haystack', '@psalm-assert iterable<float> $haystack'],
        'assertContainsOnlyInt'            => ['@phpstan-assert iterable<int> $haystack', '@psalm-assert iterable<int> $haystack'],
        'assertContainsOnlyIterable'       => ['@phpstan-assert iterable<iterable<mixed>> $haystack', '@psalm-assert iterable<iterable<mixed>> $haystack'],
        'assertContainsOnlyNull'           => ['@phpstan-assert iterable<null> $haystack', '@psalm-assert iterable<null> $haystack'],
        'assertContainsOnlyNumeric'        => ['@phpstan-assert iterable<numeric> $haystack', '@psalm-assert iterable<numeric> $haystack'],
        'assertContainsOnlyObject'         => ['@phpstan-assert iterable<object> $haystack', '@psalm-assert iterable<object> $haystack'],
        'assertContainsOnlyResource'       => ['@phpstan-assert iterable<resource> $haystack', '@psalm-assert iterable<resource> $haystack'],
        'assertContainsOnlyClosedResource' => ['@phpstan-assert iterable<resource> $haystack', '@psalm-assert iterable<resource> $haystack'],
        'assertContainsOnlyScalar'         => ['@phpstan-assert iterable<scalar> $haystack', '@psalm-assert iterable<scalar> $haystack'],
        'assertContainsOnlyString'         => ['@phpstan-assert iterable<string> $haystack', '@psalm-assert iterable<string> $haystack'],
    ];

    /**
     * The narrowing the extension answers better than any portable tag
     * could: `empty()` has no type, `!==` against an expression has no
     * negated-equals tag, and the count/key/property knowledge is
     * shaped-array or has-offset, not a type at all. These keep their
     * extension-only answers and must never grow a lying tag.
     *
     * @var list<non-empty-string>
     */
    private const array EXTENSION_ONLY = [
        'assertEmpty',
        'assertNotEmpty',
        'assertNotSame',
        'assertCount',
        'assertNotCount',
        'assertArrayHasKey',
        'assertArrayNotHasKey',
        'assertObjectHasProperty',
        'assertObjectNotHasProperty',
    ];

    /**
     * The annotated methods the extension has never narrowed (F-10's
     * closed-resource pair; the contains-only family, which
     * AssertConditions has never spoken for): the tags are all these
     * have, on every analyser alike.
     *
     * @var list<non-empty-string>
     */
    private const array ANNOTATION_ONLY = [
        'assertIsClosedResource',
        'assertIsNotClosedResource',
        'assertContainsOnlyArray',
        'assertContainsOnlyBool',
        'assertContainsOnlyCallable',
        'assertContainsOnlyFloat',
        'assertContainsOnlyInt',
        'assertContainsOnlyIterable',
        'assertContainsOnlyNull',
        'assertContainsOnlyNumeric',
        'assertContainsOnlyObject',
        'assertContainsOnlyResource',
        'assertContainsOnlyClosedResource',
        'assertContainsOnlyScalar',
        'assertContainsOnlyString',
    ];

    public function testEveryNarrowingMethodCarriesItsPortableAnnotation(): void
    {
        foreach (self::ANNOTATIONS as $method => $tags) {
            $doc = (new ReflectionMethod(Assert::class, $method))->getDocComment();

            self::assertIsString($doc, $method . ' lost its docblock.');

            foreach ($tags as $tag) {
                self::assertStringContainsString(
                    ' * ' . $tag . "\n",
                    $doc,
                    $method . ' no longer carries: ' . $tag,
                );
            }
        }
    }

    public function testEveryExtensionNarrowingMethodIsAccountedFor(): void
    {
        foreach ($this->extensionNarrowingMethods() as $method) {
            $portable  = isset(self::ANNOTATIONS[$method]);
            $justified = in_array($method, self::EXTENSION_ONLY, true);

            self::assertTrue(
                $portable || $justified,
                $method . ' narrows in AssertConditions but carries neither an annotation nor an extension-only entry.',
            );

            if ($justified) {
                self::assertFalse($portable, $method . ' is listed extension-only yet annotated.');
            }
        }
    }

    public function testTheAnnotationOnlyListNamesExactlyThoseMethods(): void
    {
        $derived = array_values(array_diff(array_keys(self::ANNOTATIONS), $this->extensionNarrowingMethods()));

        sort($derived);

        $expected = self::ANNOTATION_ONLY;
        sort($expected);

        self::assertSame($expected, $derived, 'A method moved between the annotation and extension worlds without updating the list.');
    }

    public function testTheExtensionOnlyMethodsCarryNoAssertAnnotation(): void
    {
        foreach (self::EXTENSION_ONLY as $method) {
            $raw = (new ReflectionMethod(Assert::class, $method))->getDocComment();
            $doc = is_string($raw) ? $raw : '';

            self::assertStringNotContainsString('@phpstan-assert', $doc, $method . ' carries a tag its extension-only entry forbids.');
            self::assertStringNotContainsString('@psalm-assert', $doc, $method . ' carries a tag its extension-only entry forbids.');
        }
    }

    public function testTheTagsNarrowWithNoExtensionLoaded(): void
    {
        // The portability claim itself: PHPStan with no Crucible
        // extension included narrows purely from the docblocks — the
        // same reading Mago and Psalm do, proven on the shape of every
        // annotation form: a type, a negated type, numeric, !true, the
        // templates of assertSame/assertInstanceOf, list, iterable<T>,
        // resource, and the contains-only family.
        $rendered = Analysis::rendered(Analysis::run(
            [Analysis::root() . '/tests/_fixtures/phpstan/PortableAnnotationFixture.php'],
        ));

        self::assertSame([
            'phpstan.dumpType: Dumped type: string',
            'phpstan.dumpType: Dumped type: int|string',
            'phpstan.dumpType: Dumped type: int|null',
            'phpstan.dumpType: Dumped type: int|numeric-string',
            'phpstan.dumpType: Dumped type: false',
            'phpstan.dumpType: Dumped type: stdClass',
            'phpstan.dumpType: Dumped type: list<mixed>',
            'phpstan.dumpType: Dumped type: resource',
            'phpstan.dumpType: Dumped type: array<0|1, int>',
            'phpstan.dumpType: Dumped type: array<0|1, stdClass>',
            "phpstan.dumpType: Dumped type: 'const'",
        ], $rendered);
    }

    /**
     * The methods the extension narrows today, read from
     * AssertConditions' own tables so a future narrowing method lands
     * in this gate the moment it is added.
     *
     * @return list<non-empty-string>
     */
    private function extensionNarrowingMethods(): array
    {
        $reflection = new ReflectionClass(AssertConditions::class);
        $methods    = [];

        foreach (['IS_FUNCTIONS', 'IS_NOT_FUNCTIONS'] as $constant) {
            $table = $reflection->getConstant($constant);

            if (is_array($table)) {
                foreach (array_keys($table) as $method) {
                    if (is_string($method) && $method !== '') {
                        $methods[] = $method;
                    }
                }
            }
        }

        $other = $reflection->getConstant('OTHER_METHODS');

        if (is_array($other)) {
            foreach ($other as $method) {
                if (is_string($method) && $method !== '') {
                    $methods[] = $method;
                }
            }
        }

        return $methods;
    }
}
