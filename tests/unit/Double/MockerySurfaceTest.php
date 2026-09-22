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
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Double\DoubleCreationException;
use LucianoPereira\Crucible\Double\Mockery\InvalidCountException;
use LucianoPereira\Crucible\Double\Mockery\MockeryApi;
use LucianoPereira\Crucible\Double\Mockery\MockeryBadMethodCallException;
use LucianoPereira\Crucible\Double\Mockery\MockeryContainer;
use LucianoPereira\Crucible\Double\Mockery\MockeryException;
use LucianoPereira\Crucible\Double\Mockery\NoMatchingExpectationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Fixtures\MockeryShapes\ShapeClass;
use LucianoPereira\Crucible\Tests\Fixtures\MockeryShapes\ShapeInterface;
use LucianoPereira\Crucible\Tests\Fixtures\MockeryShapes\ShapeTrait;
use Mockery;
use RuntimeException;

use function class_uses;
use function is_a;
use function str_replace;

interface PaymentClient
{
    public function charge(int $amount): bool;

    public function balance(): int;

    public function gateway(): PaymentGateway;
}

interface PaymentGateway
{
    public function name(): string;
}

class CtorProbe
{
    public function __construct(public string $tag = 'default') {}

    public function hi(): string
    {
        return 'real';
    }
}

/**
 * M2: the Mockery core grammar over the one state brain, semantics as
 * oracle-pinned in spec/mockery-api.md §1–§5/§15.
 */
#[CoversClass(MockeryContainer::class)]
#[CoversClass(MockeryApi::class)]
final class MockerySurfaceTest extends TestCase
{
    public function testThePestMockingChapterShape(): void
    {
        // The exact usage Pest's docs demonstrate.
        $client = MockeryApi::mock(PaymentClient::class);
        $client->shouldReceive('charge')->with(100)->andReturn(true)->once();

        $this->assertInstanceOf(PaymentClient::class, $client);
        $this->assertTrue($client->charge(100));
    }

    public function testFirstDeclaredWins(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->andReturn('first');
        $m->shouldReceive('f')->andReturn('second');

        $this->assertSame(['first', 'first'], [$m->f(), $m->f()]);
    }

    public function testCountExhaustionFallsThrough(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->once()->andReturn('first');
        $m->shouldReceive('f')->andReturn('second');

        $this->assertSame(['first', 'second', 'second'], [$m->f(), $m->f(), $m->f()]);
    }

    public function testExceededCountSettlesAtCloseNotCallTime(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->once()->andReturn('x');

        // both calls RETURN (oracle-pinned: no call-time failure)
        $this->assertSame('x', $m->f());
        $this->assertSame('x', $m->f());

        try {
            MockeryContainer::settle();
            $this->fail('the exceeded once() must settle as a failure');
        } catch (InvalidCountException $e) {
            $this->assertStringContainsString('f()', $e->getMessage());
        }
    }

    public function testShouldNotReceiveViolationSettlesAtClose(): void
    {
        $m = MockeryApi::mock();
        $m->shouldNotReceive('f');

        $this->assertNull($m->f());

        $this->expectException(InvalidCountException::class);
        MockeryContainer::settle();
    }

    public function testUnconfiguredMethodIsBadMethodCall(): void
    {
        $m = MockeryApi::mock(PaymentClient::class);
        $m->shouldReceive('balance')->andReturn(1);

        $this->expectException(MockeryBadMethodCallException::class);
        $this->expectExceptionMessage('does not exist on this mock object');

        $m->charge(5);
    }

    public function testArgumentMismatchIsNoMatchingExpectation(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(1)->andReturn('one');

        $this->expectException(NoMatchingExpectationException::class);
        $this->expectExceptionMessage('No matching handler');

        $m->f(2);
    }

    public function testMockeryEqualityLooseScalarsIdentityObjects(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->with(1)->andReturn('matched');

        $this->assertSame('matched', $m->f('1')); // loose scalar ==

        $m->shouldReceive('g')->with(new DateTimeImmutable('2026-01-01'))->andReturn('same');

        $this->expectException(NoMatchingExpectationException::class);
        $m->g(new DateTimeImmutable('2026-01-01')); // equal-by-value, not identical
    }

    public function testTypedDefaultsMatchTheOracle(): void
    {
        $m = MockeryApi::mock(PaymentClient::class);
        $m->shouldReceive('balance');
        $m->shouldReceive('gateway');

        $this->assertSame(0, $m->balance());
        $this->assertInstanceOf(PaymentGateway::class, $m->gateway());
    }

    public function testConsecutiveReturnsRepeatTheLast(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->andReturn(1, 2, 3);

        $this->assertSame([1, 2, 3, 3], [$m->f(), $m->f(), $m->f(), $m->f()]);
    }

    public function testAndThrowWithClassAndMessage(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->andThrow(RuntimeException::class, 'boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $m->f();
    }

    public function testCountVocabulary(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('a')->twice();
        $m->shouldReceive('b')->atLeast()->once();
        $m->shouldReceive('c')->atMost()->times(2);
        $m->shouldReceive('d')->between(1, 3);
        $m->shouldReceive('e')->zeroOrMoreTimes();

        $m->a();
        $m->a();
        $m->b();
        $m->c();
        $m->d();

        MockeryContainer::settle();
        $this->addToAssertionCount(1); // settled clean
    }

    public function testBetweenUnmetSettlesAsFailure(): void
    {
        $m = MockeryApi::mock();
        $m->shouldReceive('f')->between(2, 3);
        $m->f();

        $this->expectException(InvalidCountException::class);
        MockeryContainer::settle();
    }

    public function testExpectsDefaultsToOnce(): void
    {
        $m = MockeryApi::mock();
        $m->expects('f')->andReturn('x');

        $this->expectException(InvalidCountException::class);
        MockeryContainer::settle();
    }

    public function testAllowsIsNeverVerified(): void
    {
        $m = MockeryApi::mock();
        $m->allows('f')->andReturn(1);
        $m->allows(['g' => 2, 'h' => 3]);

        $this->assertSame(2, $m->g());
        $this->assertSame(3, $m->h('any', 'args'));

        MockeryContainer::settle();
        $this->addToAssertionCount(1);
    }

    public function testQuickDefinitionsMatchAnyArguments(): void
    {
        $m = MockeryApi::mock('QuickDefsM2', ['f' => 'v1', 'g' => 'v2']);

        $this->assertSame('v1', $m->f());
        $this->assertSame('v2', $m->g('any', 'args'));
    }

    public function testQuickDefinitionsToggleMakesThemMocks(): void
    {
        MockeryApi::getConfiguration()->getQuickDefinitions()->shouldBeCalledAtLeastOnce(true);

        try {
            MockeryApi::mock('QuickDefsToggleM2', ['f' => 1]);

            $this->expectException(InvalidCountException::class);
            MockeryContainer::settle();
        } finally {
            MockeryApi::getConfiguration()->getQuickDefinitions()->shouldBeCalledAtLeastOnce(false);
            MockeryContainer::reset();
        }
    }

    public function testUnknownTypesAreDeclaredLikeTheOracle(): void
    {
        $m = MockeryApi::mock('Totally\Unknown\ThingM2');

        $this->assertTrue(is_a($m, 'Totally\Unknown\ThingM2'));
    }

    public function testConstructorArgumentsRunTheOriginalConstructor(): void
    {
        $m = MockeryApi::mock(CtorProbe::class, ['CTORARG']);

        $this->assertInstanceOf(CtorProbe::class, $m);
        $this->assertSame('CTORARG', $m->tag);
    }

    public function testAllowMockingNonExistentMethodsFalseRefuses(): void
    {
        MockeryApi::getConfiguration()->allowMockingNonExistentMethods(false);

        try {
            $m = MockeryApi::mock(CtorProbe::class);

            $this->expectException(MockeryException::class);
            $this->expectExceptionMessage('forbids mocking the method');

            $m->shouldReceive('nonexistent');
        } finally {
            MockeryApi::getConfiguration()->allowMockingNonExistentMethods(true);
        }
    }

    public function testTheGlobalAliasesAreInstalled(): void
    {
        // The suite runs through the Application, so the D-019-style
        // auto-aliases are on (mockery/mockery is not installed here).
        $m = Mockery::mock(PaymentClient::class);

        $this->assertInstanceOf(\Mockery\MockInterface::class, $m);

        Mockery::close(); // the idempotent spelling of settlement
        $this->addToAssertionCount(1);
    }

    public function testDitchedSurfaceAnswersWithNamedErrors(): never
    {
        $this->expectException(MockeryException::class);
        $this->expectExceptionMessage('reference-tier');

        MockeryApi::spy();
    }
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../_fixtures/mockery-shapes/shapes.php';
    }

    public function testAMockNameIsAName(): void
    {
        // The one generator whose job is to declare a name that does
        // not exist yet is the one that cannot lean on class_exists()
        // to vouch for its input. ✓ Measured before the guard:
        // mock('Injected {} echo "..."; class Tail') printed the echo —
        // the name was interpolated into eval() and the code ran. A
        // mock name is usually a literal in the author's own test, but
        // a data provider or a generated schema can supply one.
        try {
            MockeryContainer::mock('Injected {} echo "ran"; class Tail');
            self::fail('source text arriving as a class name must be refused');
        } catch (DoubleCreationException $refusal) {
            self::assertStringContainsString('a name is not a place to put code', $refusal->getMessage());
        }

        // And a name PHP would accept is still accepted — including the
        // high-byte range its own label grammar allows, so the guard
        // does not quietly narrow what can be doubled.
        foreach (['UndefinedThing!', 'App\\Domain\\Undefined_Thing2!', 'Ünïcode\\Klasse!'] as $spelling) {
            /** @var class-string $legitimate kept opaque: none of these exists until mock() declares it */
            $legitimate = str_replace('!', '', $spelling);

            self::assertInstanceOf($legitimate, MockeryContainer::mock($legitimate));
        }
    }

    public function testTheShapesAMockNameCanTake(): void
    {
        // declareEmptyClass() is the one generator whose input is a
        // NAME rather than a reflected type, so its corpus is the
        // shapes a name can arrive in.
        /** @var class-string $undeclared kept opaque: nothing declares it until mock() does */
        $undeclared = str_replace('!', '', 'UndeclaredGlobalThing!');
        self::assertInstanceOf($undeclared, MockeryContainer::mock($undeclared));

        /** @var class-string $nested */
        $nested = str_replace('!', '', 'Undeclared\\Deeply\\Nested!');
        self::assertInstanceOf($nested, MockeryContainer::mock($nested));

        // A leading backslash is a spelling, not a different type.
        self::assertInstanceOf($undeclared, MockeryContainer::mock('\\' . $undeclared));

        // An existing class or interface is doubled, not redeclared.
        self::assertInstanceOf(ShapeInterface::class, MockeryContainer::mock(ShapeInterface::class));
        self::assertInstanceOf(ShapeClass::class, MockeryContainer::mock(ShapeClass::class));

        // ✓ Measured before the fix: a name that is a TRAIT hit "Cannot
        // redeclare trait", an uncatchable fatal, because the empty
        // class took the trait's own name. The real mockery succeeds
        // here, so this was a parity defect and not only a diagnosis
        // one.
        self::assertIsObject(MockeryContainer::mock(ShapeTrait::class));
    }

    public function testATraitMockUsesTheTraitAndKeepsItsMethodsReal(): void
    {
        // ✓ Measured against the mockery-main oracle, all three:
        // Mockery::mock(SomeTrait::class) returns a class that USES the
        // trait, class_uses() holds it, and the trait's own methods
        // answer for themselves — an expectation on one does not
        // replace it.
        //
        // This was recorded as a divergence first and that was the
        // wrong move: a difference written down is still a difference.
        // The trait is mixed into the DOUBLE now rather than into a
        // parent it extends, which is what keeps the methods real.
        $mock = MockeryContainer::mock(ShapeTrait::class);

        self::assertContains(ShapeTrait::class, class_uses($mock));
        self::assertSame('real', $mock->shape());

        $mock->shouldReceive('shape')->andReturn('mocked');

        self::assertSame('real', $mock->shape());
    }

}
