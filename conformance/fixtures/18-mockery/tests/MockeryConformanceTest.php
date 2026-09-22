<?php

declare(strict_types=1);

namespace CrucibleConformance\Mockery;

use DateTimeImmutable;
use Mockery;
use PHPUnit\Framework\TestCase;

interface ConformancePayments
{
    public function charge(int $amount): bool;

    public function balance(): int;
}

/*
 * The mockery conformance lane (D-066): this file runs against the
 * REAL mockery/mockery + its PHPUnit (conformance/mockery-oracle.php)
 * and against Crucible's Mockery grammar through the D-019 aliases —
 * identical per-test outcomes and exit codes required. The scenarios
 * are the M1–M3 probe pins that both brains must agree on observably;
 * deliberately-recorded deviations (final-method stubbing, attach
 * localPaths, submit) stay out of the fixture.
 */
final class MockeryConformanceTest extends TestCase
{
    // The documented host integration: with the trait, an unmet
    // expectation settles as a test FAILURE on both sides — without
    // it, a bare tearDown Mockery::close() would be a raw error on
    // the oracle (the lane's first catch, recorded in D-066).
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function testAStubReturnsTheConfiguredValue(): void
    {
        $payments = Mockery::mock(ConformancePayments::class);
        $payments->shouldReceive('charge')->with(100)->andReturn(true);

        $this->assertTrue($payments->charge(100));
    }

    public function testScalarsMatchLooselyObjectsByIdentity(): void
    {
        $m = Mockery::mock(ConformancePayments::class);
        $m->shouldReceive('charge')->with(5)->andReturn(true);

        $this->assertTrue($m->charge('5' + 0)); // loose 5

        $n = Mockery::mock();
        $n->shouldReceive('take')->with(new DateTimeImmutable('2026-01-01'))->andReturn('same');

        $this->expectException(\Mockery\Exception\NoMatchingExpectationException::class);
        $n->take(new DateTimeImmutable('2026-01-01')); // equal-by-value, not identical
    }

    public function testFirstDeclaredWinsAndExhaustionFallsThrough(): void
    {
        $m = Mockery::mock();
        $m->shouldReceive('f')->once()->andReturn('first');
        $m->shouldReceive('f')->andReturn('second');

        $this->assertSame('first', $m->f());
        $this->assertSame('second', $m->f());
        $this->assertSame('second', $m->f());
    }

    public function testConsecutiveReturnsRepeatTheLast(): void
    {
        $m = Mockery::mock();
        $m->shouldReceive('f')->andReturn(1, 2, 3);

        $this->assertSame([1, 2, 3, 3], [$m->f(), $m->f(), $m->f(), $m->f()]);
    }

    public function testTheMatcherAlgebraAgrees(): void
    {
        $m = Mockery::mock();
        $m->shouldReceive('typed')->with(Mockery::type('int'))->andReturn('int!');
        $m->shouldReceive('any')->with(Mockery::any())->andReturn('anything');
        $m->shouldReceive('negated')->with(Mockery::not(1))->andReturn('not-one');
        $m->shouldReceive('member')->with(Mockery::anyOf(1, 2))->andReturn('member');
        $m->shouldReceive('keyed')->with(Mockery::hasKey('k'))->andReturn('keyed');
        $m->shouldReceive('subset')->with(Mockery::subset(['a' => 1]))->andReturn('subset');
        $m->shouldReceive('predicate')->with(Mockery::on(static fn($v): bool => $v > 3))->andReturn('big');

        $this->assertSame('int!', $m->typed(5));
        $this->assertSame('anything', $m->any(null));
        $this->assertSame('not-one', $m->negated('1')); // strict: '1' !== 1
        $this->assertSame('member', $m->member(2));
        $this->assertSame('keyed', $m->keyed(['k' => 'v']));
        $this->assertSame('subset', $m->subset(['a' => 1, 'extra' => true]));
        $this->assertSame('big', $m->predicate(9));

        $this->expectException(\Mockery\Exception\NoMatchingExpectationException::class);
        $m->typed('5'); // type() is strict
    }

    public function testWithArgsAndSomeOfArgs(): void
    {
        $m = Mockery::mock();
        $m->shouldReceive('sum')->withArgs(static fn(int $a, int $b): bool => $a + $b === 3)->andReturn('three');
        $m->shouldReceive('holds')->withSomeOfArgs(2)->andReturn('has-two');

        $this->assertSame('three', $m->sum(1, 2));
        $this->assertSame('has-two', $m->holds(1, 2, 3));
    }

    public function testAnExceededCountReturnsAtCallTimeAndSettlesAtClose(): void
    {
        $m = Mockery::mock();
        $m->shouldReceive('f')->once()->andReturn('x');

        // Both calls RETURN — nothing count-related fails at call time.
        $this->assertSame('x', $m->f());
        $this->assertSame('x', $m->f());

        // tearDown's Mockery::close() settles this as an error.
        $this->expectException(\Mockery\Exception\InvalidCountException::class);
        Mockery::close();
    }

    public function testAnUnmetExpectationFailsTheTestAtClose(): void
    {
        $m = Mockery::mock();
        $m->shouldReceive('never_called')->once();

        // No call happens: the integration settles the unmet count as
        // a FAILURE after the body — identically on both sides.
        $this->addToAssertionCount(1);
    }
}
