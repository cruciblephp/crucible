<?php

declare(strict_types=1);

namespace CrucibleConformance\BuilderDoubles;

use Countable;
use PHPUnit\Framework\TestCase;

class Roaster
{
    public bool $constructed = false;

    public string $origin = 'unset';

    public function __construct(string $origin = 'brazil')
    {
        $this->constructed = true;
        $this->origin      = $origin;
    }

    public function roast(): string
    {
        return 'roast:' . $this->origin;
    }

    public function profile(): string
    {
        return 'medium';
    }
}

interface Scale
{
    public function grams(): int;
}

final class BuilderDoublesTest extends TestCase
{
    public function testBuilderRunsTheOriginalConstructorByDefault(): void
    {
        $mock = $this->getMockBuilder(Roaster::class)->onlyMethods(['profile'])->getMock();

        $this->assertTrue($mock->constructed);
        $this->assertSame('brazil', $mock->origin);
    }

    public function testConstructorArgsAndPartialStubbing(): void
    {
        $mock = $this->getMockBuilder(Roaster::class)
            ->setConstructorArgs(['ethiopia'])
            ->onlyMethods(['profile'])
            ->getMock();

        $mock->method('profile')->willReturn('light');

        $this->assertSame('light', $mock->profile());
        $this->assertSame('roast:ethiopia', $mock->roast());
    }

    public function testDisabledConstructorSkipsInitialization(): void
    {
        $mock = $this->getMockBuilder(Roaster::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['profile'])
            ->getMock();

        $this->assertFalse($mock->constructed);
        $this->assertSame('unset', $mock->origin);
    }

    public function testEmptyOnlyMethodsKeepsEverythingReal(): void
    {
        $mock = $this->getMockBuilder(Roaster::class)->onlyMethods([])->getMock();

        $this->assertSame('roast:brazil', $mock->roast());
        $this->assertSame('medium', $mock->profile());
    }

    public function testCreatePartialMockNeverRunsTheConstructor(): void
    {
        $partial = $this->createPartialMock(Roaster::class, ['profile']);
        $partial->method('profile')->willReturn('dark');

        $this->assertFalse($partial->constructed);
        $this->assertSame('dark', $partial->profile());
        $this->assertSame('roast:unset', $partial->roast());
    }

    public function testExpectationsOnBuilderMocksVerify(): void
    {
        $mock = $this->getMockBuilder(Roaster::class)->onlyMethods(['profile'])->getMock();
        $mock->expects($this->exactly(2))->method('profile')->willReturn('espresso');

        $this->assertSame('espresso', $mock->profile());
        $this->assertSame('espresso', $mock->profile());
    }

    public function testUnmetBuilderExpectationFails(): void
    {
        $mock = $this->getMockBuilder(Roaster::class)->onlyMethods(['profile'])->getMock();
        $mock->expects($this->once())->method('profile');

        $this->assertSame('roast:brazil', $mock->roast());
        // profile() never called: the test must fail at verification.
    }

    public function testIntersectionMockImplementsEveryInterface(): void
    {
        $mock = $this->createMockForIntersectionOfInterfaces([Scale::class, Countable::class]);

        $this->assertInstanceOf(Scale::class, $mock);
        $this->assertInstanceOf(Countable::class, $mock);
        $this->assertSame(0, $mock->grams());
    }

    public function testConfiguringAnUndoubledMethodErrors(): void
    {
        $partial = $this->createPartialMock(Roaster::class, ['profile']);

        $partial->method('roast')->willReturn('never');
        // Unreachable: configuring an undoubled method must error.
    }
}
