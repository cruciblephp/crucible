<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Property;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Property\CannotGenerate;
use LucianoPereira\Crucible\Property\ChoiceSource;
use LucianoPereira\Crucible\Property\Gen;
use LucianoPereira\Crucible\Property\Property;
use LucianoPereira\Crucible\Property\TypeGen;
use LucianoPereira\Crucible\Types\TypeExpression;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function json_encode;
use function sprintf;

/**
 * Gen::of() draws from the type toMatchShape() checks (D-132): every value
 * it makes fits the type it was made from, and a failing property shrinks
 * to the smallest counterexample the type allows.
 */
#[CoversClass(Gen::class)]
#[CoversClass(TypeGen::class)]
final class GenOfTest extends TestCase
{
    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function types(): iterable
    {
        yield 'a DTO shape' => ['array{id: positive-int, email: non-empty-string, tags: list<string>, nick?: string}'];
        yield 'a range' => ['int<3, 7>'];
        yield 'nullable' => ['?non-empty-string'];
        yield 'string keys' => ['array<string, int>'];
        yield 'a non-empty list of literals' => ["non-empty-list<'a'|'b'>"];
        yield 'nested' => ['array{user: array{name: non-empty-string, roles: list<int<1, 3>>}}'];
        yield 'numeric strings' => ['list<numeric-string>'];
        yield 'a range open below' => ['int<min, -5>'];
        yield 'a range open above' => ['int<5, max>'];
        yield 'int keys' => ['array<int, string>'];
        yield 'any keys' => ['array<array-key, bool>'];
        yield 'non-empty with keys' => ['non-empty-array<string, int>'];
    }

    /**
     * @param non-empty-string $type
     */
    #[DataProvider('types')]
    public function testEveryDrawnValueFitsItsType(string $type): void
    {
        $generator = Gen::of($type);
        $check     = TypeExpression::parse($type);

        for ($seed = 0; $seed < 200; $seed++) {
            $value = $generator->generate(new ChoiceSource(new Randomizer(new Mt19937($seed))));

            self::assertNull($check->mismatch($value), sprintf('seed %d drew %s', $seed, json_encode($value)));
        }
    }

    public function testATypeWithNoSoundWayToDrawIsRefused(): void
    {
        $this->expectException(CannotGenerate::class);
        $this->expectExceptionMessage('cannot draw values of DateTime');

        Gen::of('\DateTime');
    }

    public function testANonEmptyTypeWhoseEveryKeyPhpRewritesIsRefused(): void
    {
        // PHP turns each numeric-string key into an int, so no array of
        // this type can hold an entry: drawing [] would hand the property
        // a value its own type forbids.
        $generator = Gen::of('non-empty-array<numeric-string, int>');

        $this->expectException(CannotGenerate::class);

        $generator->generate(new ChoiceSource(new Randomizer(new Mt19937(0))));
    }

    public function testAFailingPropertyShrinksToTheSmallestShape(): void
    {
        try {
            Property::forAll(Gen::of('array{id: positive-int, nick?: non-empty-string}'))
                ->seed(20260926)
                ->check(static fn(array $user) => Assert::assertLessThan(50, $user['id']));
        } catch (AssertionFailedError $failure) {
            // The boundary, and the optional key gone: nothing the
            // failure does not need.
            self::assertStringContainsString("Counterexample: ['id' => 50]", $failure->getMessage());

            return;
        }

        self::fail('The false property was not falsified.');
    }
}
