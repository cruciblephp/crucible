<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Double;

use Countable;
use LucianoPereira\Crucible\Attributes\AllowMockObjectsWithoutExpectations;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Double\DoubleConfigurationException;
use LucianoPereira\Crucible\Double\DoubleCreationException;
use LucianoPereira\Crucible\Double\DoubleSpecification;
use LucianoPereira\Crucible\Double\MockBuilder;
use LucianoPereira\Crucible\Double\TestDoubles;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Fixtures\MockeryShapes\ShapeTrait;

use function md5;

/**
 * The builder fixture: a real constructor, a real method, a stubbable
 * method, and clone bookkeeping — every builder knob is observable on
 * it. Semantics pinned against the PHPUnit 13 oracle by black-box
 * probes (D-046) and by conformance fixture 17-builder-doubles.
 */
class Brewer
{
    public bool $constructed = false;

    public string $bean = 'unset';

    public int $cloned = 0;

    public function __construct(string $bean = 'arabica')
    {
        $this->constructed = true;
        $this->bean        = $bean;
    }

    public function grind(): string
    {
        return 'ground:' . $this->bean;
    }

    public function brew(): string
    {
        return 'espresso';
    }

    public function __clone()
    {
        $this->cloned++;
    }
}

interface Grinder
{
    public function setting(): int;
}

#[CoversClass(MockBuilder::class)]
#[AllowMockObjectsWithoutExpectations] // these mocks exist to observe builder knobs
final class MockBuilderTest extends TestCase
{
    public function testTheOriginalConstructorRunsByDefault(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)->onlyMethods(['brew'])->getMock();

        $this->assertTrue($mock->constructed);
        $this->assertSame('arabica', $mock->bean);
    }

    public function testConstructorArgsReachTheOriginalConstructor(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)
            ->setConstructorArgs(['robusta'])
            ->onlyMethods(['brew'])
            ->getMock();

        $this->assertSame('robusta', $mock->bean);
    }

    public function testDisableOriginalConstructorBypassesIt(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['brew'])
            ->getMock();

        $this->assertFalse($mock->constructed);
        $this->assertSame('unset', $mock->bean);
    }

    public function testOnlyMethodsLeavesUnlistedMethodsReal(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)
            ->setConstructorArgs(['probe'])
            ->onlyMethods(['brew'])
            ->getMock();

        $mock->method('brew')->willReturn('configured');

        $this->assertSame('configured', $mock->brew());
        $this->assertSame('ground:probe', $mock->grind());
    }

    public function testEmptyOnlyMethodsDoublesNothing(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)->onlyMethods([])->getMock();

        $this->assertSame('espresso', $mock->brew());
        $this->assertSame('ground:arabica', $mock->grind());
    }

    public function testUnknownMethodInOnlyMethodsIsANamedError(): void
    {
        $this->expectException(DoubleCreationException::class);
        $this->expectExceptionMessage('no method named missing()');

        $this->getMockBuilder(Brewer::class)->onlyMethods(['missing'])->getMock();
    }

    public function testOnlyMethodsOnAnInterfaceIsANamedError(): void
    {
        // The oracle fatals here (abstract method left unimplemented);
        // a named error is the better behavior, recorded in D-046.
        $this->expectException(DoubleCreationException::class);
        $this->expectExceptionMessage('every method must be implemented');

        $this->getMockBuilder(Grinder::class)->onlyMethods(['setting'])->getMock();
    }

    public function testConfiguringAnUndoubledMethodIsANamedError(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)->onlyMethods(['brew'])->getMock();

        $this->expectException(DoubleConfigurationException::class);
        $this->expectExceptionMessage('grind() cannot be configured');

        $mock->method('grind');
    }

    public function testExpectsOnAnUndoubledMethodIsANamedErrorToo(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)->onlyMethods(['brew'])->getMock();

        $this->expectException(DoubleConfigurationException::class);

        $mock->expects($this->once())->method('grind');
    }

    public function testExpectationsOnBuilderMocksVerify(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)->onlyMethods(['brew'])->getMock();
        $mock->expects($this->once())->method('brew')->willReturn('shot');

        $this->assertSame('shot', $mock->brew());
    }

    public function testTheOriginalCloneRunsByDefaultAndCanBeDisabled(): void
    {
        $default = $this->getMockBuilder(Brewer::class)->onlyMethods(['brew'])->getMock();
        $copy    = clone $default;

        $this->assertSame(1, $copy->cloned);

        $silent = $this->getMockBuilder(Brewer::class)
            ->onlyMethods(['brew'])
            ->disableOriginalClone()
            ->getMock();
        $untouched = clone $silent;

        $this->assertSame(0, $untouched->cloned);
    }

    public function testDisabledAutoReturnGenerationRefusesUnconfiguredCalls(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)
            ->onlyMethods(['brew'])
            ->disableAutoReturnValueGeneration()
            ->getMock();

        $this->expectException(DoubleConfigurationException::class);
        $this->expectExceptionMessage('brew() has no configured return value');

        $mock->brew();
    }

    public function testSetMockClassNameNamesTheGeneratedClass(): void
    {
        $mock = $this->getMockBuilder(Brewer::class)
            ->setMockClassName('NamedBrewerDouble')
            ->onlyMethods(['brew'])
            ->getMock();

        $this->assertSame('NamedBrewerDouble', $mock::class);

        // The same specification reuses the generated class; a clash
        // with an existing name is a named error.
        $again = $this->getMockBuilder(Brewer::class)
            ->setMockClassName('NamedBrewerDouble')
            ->onlyMethods(['brew'])
            ->getMock();

        $this->assertSame('NamedBrewerDouble', $again::class);

        $this->expectException(DoubleCreationException::class);
        $this->expectExceptionMessage('already in use');

        $this->getMockBuilder(Brewer::class)->setMockClassName('NamedBrewerDouble')->getMock();
    }

    /**
     * ✓ Measured: for a trait `class_exists` and `interface_exists` are
     * both false while `trait_exists` is true, and declaring a class
     * over the name is an uncatchable fatal — exit 255, and
     * `catch (Throwable)` never fires. An enum needs no clause: PHP
     * reports `class_exists` true for one, so it was already refused.
     */
    public function testATraitNameIsRefusedRatherThanCompiledIntoAFatal(): void
    {
        require_once __DIR__ . '/../../_fixtures/mockery-shapes/shapes.php';

        $this->expectException(DoubleCreationException::class);
        $this->expectExceptionMessage('already in use');

        $this->getMockBuilder(Brewer::class)->setMockClassName(ShapeTrait::class)->getMock();
    }

    /**
     * The name is a pure function of the specification, so the recorded
     * corpus is the set of distinct shapes `GeneratedCode::record()`
     * claims it is. Under the old counter an unrelated double created
     * first renamed this one, which made the claim false and left
     * `analyse:generated` deterministic only by accident.
     */
    public function testAGeneratedDoubleIsNamedByItsSpecificationNotByCallOrder(): void
    {
        $specification = new DoubleSpecification(
            [Brewer::class],
            mergeConfigurations: true,
            prefixArgumentMatching: true,
        );

        // Something unrelated first: this is exactly what used to shift
        // every later name by one.
        (new TestDoubles())->create(Countable::class);

        $this->assertSame(
            'CrucibleDouble_Brewer_' . md5($specification->classIdentity()),
            (new TestDoubles())->create(Brewer::class)::class,
        );
    }

    public function testCreatePartialMockBypassesTheConstructorAndKeepsTheRestReal(): void
    {
        $partial = $this->createPartialMock(Brewer::class, ['brew']);
        $partial->method('brew')->willReturn('half');

        $this->assertFalse($partial->constructed);
        $this->assertSame('half', $partial->brew());
        $this->assertSame('ground:unset', $partial->grind());

        $nothingDoubled = $this->createPartialMock(Brewer::class, []);

        $this->assertSame('espresso', $nothingDoubled->brew());
    }

    public function testIntersectionMocksImplementEveryInterface(): void
    {
        $mock = $this->createMockForIntersectionOfInterfaces([Grinder::class, Countable::class]);

        self::assertInstanceOf(Grinder::class, $mock, 'The intersection mock must implement every interface.');
        self::assertInstanceOf(Countable::class, $mock, 'The intersection mock must implement every interface.');

        $mock->method('setting')->willReturn(7);
        $mock->method('count')->willReturn(2);

        $this->assertSame(7, $mock->setting());
        $this->assertSame(2, $mock->count());
    }

    public function testExpectationlessMocksAreNamedAndStubsAreNot(): void
    {
        $doubles = new TestDoubles();

        $advisedMock = $doubles->fromSpecification(new DoubleSpecification([Grinder::class]), advisesExpectations: true);
        $doubles->fromSpecification(new DoubleSpecification([Grinder::class])); // stub-flavored

        $this->assertSame([Grinder::class], $doubles->expectationlessMocks());

        self::assertInstanceOf(Grinder::class, $advisedMock, 'The double must implement its target.');

        // An expectation clears the advisory.
        $advisedMock->expects($this->once())->method('setting')->willReturn(1);
        $advisedMock->setting();

        $this->assertSame([], $doubles->expectationlessMocks());
        $doubles->verify();
    }

    public function testTheStubBuilderIsTheMockBuilderWithStubSpellings(): void
    {
        $stub = $this->getStubBuilder(Brewer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['brew'])
            ->getStub();

        $stub->method('brew')->willReturn('stubbed');

        $this->assertSame('stubbed', $stub->brew());
        $this->assertFalse($stub->constructed);
    }
}
