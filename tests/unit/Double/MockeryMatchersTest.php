<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Double;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Double\Mockery\CapturesArgument;
use LucianoPereira\Crucible\Double\Mockery\ContainsValues;
use LucianoPereira\Crucible\Double\Mockery\HasDuckType;
use LucianoPereira\Crucible\Double\Mockery\InvalidCountException;
use LucianoPereira\Crucible\Double\Mockery\IsAnyOf;
use LucianoPereira\Crucible\Double\Mockery\MatchesArraySubset;
use LucianoPereira\Crucible\Double\Mockery\MatchesPattern;
use LucianoPereira\Crucible\Double\Mockery\MockeryApi;
use LucianoPereira\Crucible\Double\Mockery\MockeryContainer;
use LucianoPereira\Crucible\Double\Mockery\MockeryException;
use LucianoPereira\Crucible\Double\Mockery\NoMatchingExpectationException;
use LucianoPereira\Crucible\Framework\TestCase;
use stdClass;
use Stringable;

use function is_int;

/**
 * M3: the §4 matcher algebra, semantics as oracle-pinned in
 * spec/mockery-api.md §4 batteries 8/8a/8b — each test mirrors a
 * probe, strictness asymmetries included.
 */
#[CoversClass(MockeryApi::class)]
#[CoversClass(CapturesArgument::class)]
#[CoversClass(ContainsValues::class)]
#[CoversClass(HasDuckType::class)]
#[CoversClass(IsAnyOf::class)]
#[CoversClass(MatchesArraySubset::class)]
#[CoversClass(MatchesPattern::class)]
final class MockeryMatchersTest extends TestCase
{
    public function testContainsIsLooseAndOrderInsensitive(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::contains(1))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::contains('1'))->andReturn('HIT');
        $m->shouldReceive('h')->with(MockeryApi::contains(1, 2))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(['1']));
        $this->assertSame('HIT', $m->g([1]));
        $this->assertSame('HIT', $m->h([3, 2, 1]));
    }

    public function testContainsOnANonArrayIsACleanNoMatch(): void
    {
        // The oracle crashes with a TypeError here (§4 battery 8);
        // Crucible pins the clean no-match (§12 posture).
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::contains(1))->andReturn('HIT');

        $this->assertNoMatch(static fn(): mixed => $m->f(1));
    }

    public function testHasValueIsStrictUnlikeContains(): void
    {
        $shared = new DateTimeImmutable('2026-01-01');

        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::hasValue(1))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::hasValue($shared))->andReturn('HIT');

        $this->assertSame('HIT', $m->f([1]));
        $this->assertSame('HIT', $m->g([$shared]));
        $this->assertNoMatch(static fn(): mixed => $m->f(['1']));
        $this->assertNoMatch(static fn(): mixed => $m->g([new DateTimeImmutable('2026-01-01')]));
    }

    public function testHasKeyRefusesNonArrays(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::hasKey('k'))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(['k' => 1]));
        $this->assertNoMatch(static fn(): mixed => $m->f('str'));
    }

    public function testAnyOfIsStrictButNotAnyOfIsLoose(): void
    {
        // The oracle's asymmetry, probed in both directions (§4
        // battery 8): anyOf refuses '1' against (1, 2); notAnyOf
        // ALSO refuses '1' — it considers '1' a member.
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::anyOf(1, 2))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::notAnyOf(1, 2))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(2));
        $this->assertSame('HIT', $m->g(3));
        $this->assertNoMatch(static fn(): mixed => $m->f('1'));
        $this->assertNoMatch(static fn(): mixed => $m->g('1'));
        $this->assertNoMatch(static fn(): mixed => $m->g(2));
    }

    public function testNotIsStrictSoIdentityFallsOutForObjects(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::not(1))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::not(new DateTimeImmutable('2026-01-01')))->andReturn('HIT');

        $this->assertSame('HIT', $m->f('1')); // 1 !== '1' — strict
        $this->assertSame('HIT', $m->f(2));
        $this->assertSame('HIT', $m->g(new DateTimeImmutable('2026-01-01'))); // equal-by-value, not identical
        $this->assertNoMatch(static fn(): mixed => $m->f(1));
    }

    public function testSubsetRecursesWithExtraKeysAllowedAtEveryDepth(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::subset(['a' => ['b' => 1]]))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(['a' => ['b' => 1, 'c' => 2], 'd' => 3]));
        $this->assertNoMatch(static fn(): mixed => $m->f(['a' => ['b' => '1']])); // strict at depth
        $this->assertNoMatch(static fn(): mixed => $m->f('notarray'));
    }

    public function testSubsetLooseFlagAndPositionalKeys(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::subset(['a' => ['b' => 1]], false))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::subset([0 => 1]))->andReturn('HIT');
        $m->shouldReceive('h')->with(MockeryApi::subset([]))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(['a' => ['b' => '1']])); // loose at depth too
        $this->assertSame('HIT', $m->h([1, 2]));                // empty part matches
        $this->assertNoMatch(static fn(): mixed => $m->g([2, 1])); // value at the wrong index
    }

    public function testTypeFollowsTheFunctionExistenceRule(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::type('int'))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::type('integer'))->andReturn('HIT');
        $m->shouldReceive('h')->with(MockeryApi::type('numeric'))->andReturn('HIT');
        $m->shouldReceive('i')->with(MockeryApi::type(DateTimeInterface::class))->andReturn('HIT');
        $m->shouldReceive('j')->with(MockeryApi::type('float'))->andReturn('HIT');
        $m->shouldReceive('k')->with(MockeryApi::type('bogustype'))->andReturn('HIT');
        $m->shouldReceive('l')->with(MockeryApi::type('boolean'))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(5));
        $this->assertSame('HIT', $m->g(5));                           // long spelling
        $this->assertSame('HIT', $m->h('5'));
        $this->assertSame('HIT', $m->i(new DateTimeImmutable()));     // instanceof at match time
        $this->assertNoMatch(static fn(): mixed => $m->f('5'));       // strict — the headline pin
        $this->assertNoMatch(static fn(): mixed => $m->j(1));         // int is not float
        $this->assertNoMatch(static fn(): mixed => $m->k(5));         // unknown name: silent no-match
        $this->assertNoMatch(static fn(): mixed => $m->l(true));      // is_boolean() does not exist — oracle parity
    }

    public function testPatternCastsScalarsAndStringables(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'abc';
            }
        };

        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::pattern('/12/'))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::pattern('/b/'))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(123));
        $this->assertSame('HIT', $m->f(12.5));
        $this->assertSame('HIT', $m->g($stringable));
        // the oracle crashes on a non-stringable object; Crucible pins the no-match
        $this->assertNoMatch(static fn(): mixed => $m->g(new stdClass()));
    }

    public function testDucktypeRequiresEveryRealMethod(): void
    {
        $duck = new class {
            public function quack(): string
            {
                return 'q';
            }

            public function waddle(): void {}
        };

        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::ducktype('quack', 'waddle'))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::ducktype('quack', 'fly'))->andReturn('HIT');

        $this->assertSame('HIT', $m->f($duck));
        $this->assertNoMatch(static fn(): mixed => $m->g($duck));     // one method missing
        $this->assertNoMatch(static fn(): mixed => $m->f('string'));  // non-object

        // magic __call methods are not seen (oracle parity): a mock's
        // stubbed method is not a declared method
        $other = MockeryApi::mock();
        $other->shouldReceive('quack');
        $this->assertNoMatch(static fn(): mixed => $m->f($other));
    }

    public function testCaptureAssignsByReferenceLastCallWins(): void
    {
        $single = null;
        $last   = null;

        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::capture($single))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::capture($last))->andReturn('HIT');

        $this->assertSame('HIT', $m->f(42));
        $this->assertSame(42, $single);

        $m->g(1);
        $m->g(2);
        $this->assertSame(2, $last);
    }

    public function testCaptureAssignsDuringTheFailedMatchAttempt(): void
    {
        // Oracle-pinned: matchers run left to right, so the capture
        // fires even when the NEXT matcher refuses the call — while
        // an arity mismatch never reaches the matcher at all.
        $bag   = 'untouched';
        $arity = 'untouched';

        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::capture($bag), 99)->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::capture($arity))->andReturn('HIT');

        $this->assertNoMatch(static fn(): mixed => $m->f(42, 1));
        $this->assertSame(42, $bag);

        $this->assertNoMatch(static fn(): mixed => $m->g());
        $this->assertSame('untouched', $arity);
    }

    public function testCaptureComposesWithCountsAndReturnUsing(): void
    {
        $bag = null;

        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::capture($bag))->andReturnUsing(static fn(mixed ...$args): mixed => is_int($args[0] ?? null) ? $args[0] * 2 : null)->once();

        $this->assertSame(42, $m->f(21));
        $this->assertSame(21, $bag);

        $m->f(1); // returns fine — counts settle at close (§5)

        $this->expectException(InvalidCountException::class);
        MockeryContainer::settle();
    }

    public function testAnyIsPerArgumentSoArityStillHolds(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::any())->andReturn('HIT');

        $this->assertSame('HIT', $m->f(null));
        $this->assertNoMatch(static fn(): mixed => $m->f());
        $this->assertNoMatch(static fn(): mixed => $m->f(1, 2));
    }

    public function testOnAndWithArgsPredicatesMustReturnTrueStrictly(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::on(static fn(int $v): bool => $v > 3))->andReturn('HIT');
        $m->shouldReceive('g')->with(MockeryApi::on(static fn(int $v): int => 1))->andReturn('HIT');
        $m->shouldReceive('h')->withArgs(static fn(int $a, int $b): bool => $a + $b === 3)->andReturn('HIT');
        $m->shouldReceive('i')->withArgs(static fn(int $a): int => 1)->andReturn('HIT');

        $this->assertSame('HIT', $m->f(5));
        $this->assertSame('HIT', $m->h(1, 2));
        $this->assertNoMatch(static fn(): mixed => $m->g(5)); // truthy 1 is not === true
        $this->assertNoMatch(static fn(): mixed => $m->i(5));
    }

    public function testWithArgsArrayFormIsBareWith(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->withArgs([1, 2])->andReturn('HIT');

        $this->assertSame('HIT', $m->f('1', '2')); // loose, like with()
    }

    public function testWithArgsRefusesOtherTypesInTheOraclesWords(): void
    {
        $m = MockeryApi::mock();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only array and closure are allowed');

        $m->shouldReceive('f')->withArgs('notacallable');
    }

    public function testWithSomeOfArgsIsStrictAndAllMustBePresent(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->withSomeOfArgs(2)->andReturn('HIT');
        $m->shouldReceive('g')->withSomeOfArgs(3, 1)->andReturn('HIT');
        $m->shouldReceive('h')->withSomeOfArgs(2, 5)->andReturn('HIT');
        $m->shouldReceive('i')->withSomeOfArgs('1')->andReturn('HIT');
        $m->shouldReceive('j')->withSomeOfArgs()->andReturn('HIT');

        $this->assertSame('HIT', $m->f(1, 2, 3));
        $this->assertSame('HIT', $m->g(1, 2, 3));            // order-insensitive
        $this->assertSame('HIT', $m->j(1));                  // zero-argument form matches any call
        $this->assertNoMatch(static fn(): mixed => $m->h(1, 2, 3)); // 5 absent — all must be present
        $this->assertNoMatch(static fn(): mixed => $m->i(1, 2));    // strict
    }

    public function testMatchersMixWithLiteralsInsideWith(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(MockeryApi::type('int'), 'x')->andReturn('HIT');

        $this->assertSame('HIT', $m->f(5, 'x'));
    }

    public function testMustBeStaysANamedError(): never
    {
        $this->expectException(MockeryException::class);
        $this->expectExceptionMessage('long-tail surface');

        MockeryApi::mustBe();
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertNoMatch(callable $call): void
    {
        try {
            $call();
            $this->fail('expected no matching handler');
        } catch (NoMatchingExpectationException) {
            $this->addToAssertionCount(1);
        }
    }
}
