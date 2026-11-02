<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Double;

use Error;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Double\DoubleState;
use LucianoPereira\Crucible\Double\InvocationCount;
use LucianoPereira\Crucible\Double\MethodConfigurator;
use LucianoPereira\Crucible\Double\PhpUnitInvocation;
use LucianoPereira\Crucible\Double\TestDoubles;
use LucianoPereira\Crucible\Framework\TestCase;
use stdClass;

use function fclose;
use function fopen;
use function str_repeat;

interface Ledger
{
    /** @param array<string, mixed> $options */
    public function find(int $id, array $options = []): ?object;

    /** @param ?list<int> $c */
    public function record(int $a, string $b = 'x', ?array $c = null, int ...$rest): bool;

    public function total(): int;
}

enum Coin: string
{
    case Head = 'h';
}

/**
 * A failing mock reads as PHPUnit 13.4.3 writes it: each expected text
 * below is that version's output for the same calls.
 */
#[CoversClass(PhpUnitInvocation::class)]
#[CoversClass(MethodConfigurator::class)]
#[CoversClass(DoubleState::class)]
#[CoversClass(InvocationCount::class)]
final class PhpUnitMockMessagesTest extends TestCase
{
    /** @param callable(): mixed $act */
    private function failure(callable $act): string
    {
        try {
            $act();
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        self::fail('the double accepted what PHPUnit fails');
    }

    /** @return iterable<string, array{InvocationCount, int, string}> */
    public static function counts(): iterable
    {
        yield 'once, never called' => [InvocationCount::once(), 0, 'once but was never invoked'];
        yield 'exactly 2, called once' => [InvocationCount::exactly(2), 1, '2 times but was invoked once'];
        yield 'exactly 3, called once' => [InvocationCount::exactly(3), 1, '3 times but was invoked once'];
        yield 'at least once, never called' => [InvocationCount::atLeastOnce(), 0, 'at least once but was never invoked'];
        yield 'at least 1, never called' => [InvocationCount::atLeast(1), 0, 'at least 1 time but was never invoked'];
        yield 'at least 2, called once' => [InvocationCount::atLeast(2), 1, 'at least 2 times but was invoked once'];
        yield 'at most once, called twice' => [InvocationCount::atMost(1), 2, 'at most once but was invoked 2 times'];
        yield 'at most 2, called 3 times' => [InvocationCount::atMost(2), 3, 'at most 2 times but was invoked 3 times'];
    }

    #[DataProvider('counts')]
    public function testAnUnmetCountIsJudgedAtTheEndInPhpUnitsWords(InvocationCount $count, int $calls, string $expected): void
    {
        $doubles = new TestDoubles();
        $ledger  = $doubles->create(Ledger::class);
        $ledger->expects($count)->method('total');

        for ($call = 0; $call < $calls; $call++) {
            $ledger->total();
        }

        self::assertSame(Ledger::class . '::total() was expected to be invoked ' . $expected . '.', $this->failure($doubles->verify(...)));
    }

    public function testAnExactCountFailsAtTheCallThatExceedsIt(): void
    {
        $ledger = (new TestDoubles())->create(Ledger::class);
        $ledger->expects($this->exactly(2))->method('total');
        $ledger->total();
        $ledger->total();

        self::assertSame(
            Ledger::class . '::total(): int was not expected to be called more than 2 times, actually called 3 times.',
            $this->failure($ledger->total(...)),
        );
    }

    public function testACallToANeverMethodNamesItsArguments(): void
    {
        $ledger = (new TestDoubles())->create(Ledger::class);
        $ledger->expects($this->never())->method('find');

        self::assertSame(
            Ledger::class . "::find(7, [...]): object was not expected to be called, actually called 1 time.",
            $this->failure(static fn() => $ledger->find(7, ['deep' => [1]])),
        );
    }

    public function testAWithThatRejectsTheCallFailsItAndNamesTheParameter(): void
    {
        $ledger = (new TestDoubles())->create(Ledger::class);
        $ledger->expects($this->once())->method('find')->with(8);

        self::assertStringStartsWith(
            "Expectation for " . Ledger::class . "::find() failed.\nParameter \$id for invocation " . Ledger::class . "::find(7, []): object does not match expected value.\n",
            $this->failure(static fn() => $ledger->find(7)),
        );
    }

    public function testAParameterPastTheDeclaredOnesIsNamedByPosition(): void
    {
        $ledger = (new TestDoubles())->create(Ledger::class);
        $ledger->expects($this->once())->method('record')->with(1, 'x', null, 4, 6);

        self::assertStringContainsString(
            "Parameter 4 for invocation " . Ledger::class . "::record(1, 'x', null, 4, 5): bool does not match expected value.",
            $this->failure(static fn() => $ledger->record(1, 'x', null, 4, 5)),
        );
    }

    public function testTooFewArgumentsForTheWithIsTooLow(): void
    {
        $ledger = (new TestDoubles())->create(Ledger::class);
        $ledger->expects($this->once())->method('find')->with(7, [], 'extra');

        self::assertSame(
            "Expectation for " . Ledger::class . "::find() failed.\nParameter count for invocation " . Ledger::class . "::find(7, []): object is too low.",
            $this->failure(static fn() => $ledger->find(7)),
        );
    }

    public function testAnOmittedArgumentIsComparedAsItsDefault(): void
    {
        $ledger = (new TestDoubles())->create(Ledger::class);
        $ledger->expects($this->once())->method('record')->with(1, 'x')->willReturn(true);

        self::assertTrue($ledger->record(1));
    }

    public function testAMismatchTheCodeCaughtIsReportedAgainAtTheEnd(): void
    {
        $doubles = new TestDoubles();
        $ledger  = $doubles->create(Ledger::class);
        $ledger->expects($this->once())->method('total')->with();
        $ledger->expects($this->once())->method('find')->with(1);

        $first = $this->failure(static fn() => $ledger->find(2));
        $ledger->total();

        self::assertSame($first, $this->failure($doubles->verify(...)));
    }

    public function testAStubHasNoExpects(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('::expects()');

        $this->createStub(Ledger::class)->expects($this->once());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function arguments(): iterable
    {
        $object    = new stdClass();
        $object->a = 1;
        $closed    = fopen('php://memory', 'r');
        self::assertIsResource($closed);
        fclose($closed);

        yield 'short string' => ['abc', "'abc'"];
        yield 'long string' => [str_repeat('abcdefghij', 8), "'abcdefghijabcdefghijabcdefghi...efghij'"];
        yield 'forty exported characters' => [str_repeat('a', 38), "'" . str_repeat('a', 38) . "'"];
        yield 'multibyte' => [str_repeat('ñ', 45), "'ñññññññññññññññññññññññññññññ...ññññññ'"];
        yield 'line breaks' => ["a\r\nb", "'a\\r\\nb'"];
        yield 'quote and backslash' => ["a'b\\c", "'a'b\\c'"];
        yield 'control character' => ["a\x1bb", "'a\\u{001B}b'"];
        yield 'nul' => ["a\tb\0c", 'Binary String: 0x6109620063'];
        yield 'long binary' => [str_repeat("a\0", 30), 'Binary String: 0x6100610061006...1006100'];
        yield 'whole float' => [2.0, '2.0'];
        yield 'big float' => [1e20, '1.0E+20'];
        yield 'empty array' => [[], '[]'];
        yield 'array' => [[1, 2], '[...]'];
        yield 'empty object' => [new stdClass(), 'stdClass Object ()'];
        yield 'object' => [$object, 'stdClass Object (...)'];
        yield 'backed enum' => [Coin::Head, Coin::class . " Enum (Head, 'h')"];
        yield 'closed resource' => [$closed, 'resource (closed)'];
    }

    #[DataProvider('arguments')]
    public function testAnArgumentIsShortenedAsPhpUnitShortensIt(mixed $value, string $expected): void
    {
        self::assertSame($expected, PhpUnitInvocation::shortened($value));
    }
}
