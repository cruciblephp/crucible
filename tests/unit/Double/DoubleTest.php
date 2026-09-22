<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Double;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Double\DoubleCreationException;
use LucianoPereira\Crucible\Double\Generator;
use LucianoPereira\Crucible\Double\TestDoubles;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;

interface Mailer
{
    public function send(string $to, string $subject): bool;

    public function queueSize(): int;

    public function transport(): Transport;
}

interface Transport
{
    public function name(): string;
}

abstract class Repository
{
    abstract public function find(int $id): ?string;

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return ['real'];
    }
}

final class Sealed {}

#[CoversClass(Generator::class)]
#[CoversClass(TestDoubles::class)]
final class DoubleTest extends TestCase
{
    public function testStubReturnsConfiguredAndDefaultValues(): void
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('send')->willReturn(true);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertTrue($mailer->send('a@b.c', 'hi'));
        $this->assertSame(0, $mailer->queueSize());
    }

    public function testObjectReturnTypesYieldRecursiveStubs(): void
    {
        $mailer = $this->createStub(Mailer::class);

        $transport = $mailer->transport();

        $this->assertInstanceOf(Transport::class, $transport);
        $this->assertSame('', $transport->name());
    }

    public function testWithMatchersSelectBehaviorPerArguments(): void
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('send')->willReturn(false);
        $mailer->method('send')->with('vip@x.com', $this->stringStartsWithConstraint())->willReturn(true);

        $this->assertTrue($mailer->send('vip@x.com', 'urgent: hello'));
        $this->assertFalse($mailer->send('other@x.com', 'urgent: hello'));
    }

    public function testConsecutiveCallsMapAndThrow(): void
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('queueSize')->willReturnOnConsecutiveCalls(1, 2);

        $this->assertSame(1, $mailer->queueSize());
        $this->assertSame(2, $mailer->queueSize());

        $mailer->method('send')->willReturnMap([
            ['a@b.c', 'one', true],
            ['a@b.c', 'two', false],
        ]);

        $this->assertTrue($mailer->send('a@b.c', 'one'));
        $this->assertFalse($mailer->send('a@b.c', 'two'));

        $mailer->method('queueSize')->willThrowException(new RuntimeException('down'));
        $this->expectException(RuntimeException::class);
        $mailer->queueSize();
    }

    public function testAbstractClassDoubleStubsAllPublicMethods(): void
    {
        $repository = $this->createStub(Repository::class);
        $repository->method('find')->willReturn('found');

        $this->assertSame('found', $repository->find(1));
        // Per the spec, concrete public methods are stubbed too:
        // default return, not the real implementation (onlyMethods()
        // for partials is a later parity item).
        $this->assertSame([], $repository->all());

        $repository->method('all')->willReturn(['configured']);
        $this->assertSame(['configured'], $repository->all());
    }

    public function testMockExpectationSatisfied(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->exactly(2))->method('send')->willReturn(true);

        $mailer->send('a@b.c', 'one');
        $mailer->send('a@b.c', 'two');
        // Verified automatically at the end of the test; counts as an assertion.
    }

    /**
     * A method's return value configured once (often via a shared
     * helper), with an invocation-count expectation layered on
     * separately and with no willReturn() of its own — the newer,
     * emptier configurator must not shadow the earlier configured
     * return value with a fabricated default (D-046).
     */
    public function testLayeredExpectationKeepsEarlierConfiguredReturnValue(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->method('send')->willReturn(true);
        $mailer->expects($this->once())->method('send');

        $this->assertTrue($mailer->send('a@b.c', 'hi'));
        // The count expectation verifies automatically at test end.
    }

    public function testExceededExpectationFailsAtCallTime(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->never())->method('send');

        $this->expectException(AssertionFailedError::class);
        $mailer->send('a@b.c', 'nope');
    }

    public function testUnmetExpectationFailsVerification(): void
    {
        $doubles = new TestDoubles();

        $mailer = $doubles->create(Mailer::class);
        $mailer->expects($this->once())->method('send');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('send() was expected to be called once, but was called 0 time(s).');

        $doubles->verify();
    }

    public function testFinalClassesCannotBeDoubled(): void
    {
        $this->expectException(DoubleCreationException::class);

        /** @var class-string $sealed intersection with Mocked is intentionally unresolvable for final classes */
        $sealed = Sealed::class;

        (new TestDoubles())->create($sealed);
    }

    private function stringStartsWithConstraint(): \LucianoPereira\Crucible\Assert\Constraint\StringStartsWith
    {
        return new \LucianoPereira\Crucible\Assert\Constraint\StringStartsWith('urgent');
    }
}
