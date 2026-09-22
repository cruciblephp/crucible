<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect;

use InvalidArgumentException;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Php;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Dialect\Inline\InlineBuilder;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\TestDiscoverer;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;

use function array_map;
use function dirname;

#[CoversClass(InlineBuilder::class)]
final class InlineDialectTest extends TestCase
{
    private const string FIXTURES = 'tests/unit/Dialect/Fixtures';

    public function testOneSourceFileYieldsChecksAndDoctestsInDeclarationOrder(): void
    {
        $group = $this->build('Inline/Temperature.php');

        self::assertSame('tests/unit/Dialect/Fixtures/Inline/Temperature.php', $group->name);
        self::assertSame([
            'Temperature#crucible 1',
            'Temperature::toFahrenheit#check 1',
            'Temperature::toFahrenheit#boiling point',
            'Temperature::toFahrenheit#check 3',
            'Temperature::assertPhysical#check 1',
            'Temperature::assertPhysical#check 2',
            'Temperature::feels#check 1',
            'Temperature::feels#crucible 1',
            'Temperature::feels#crucible 2',
            'Temperature::whisper#check 1',
            'shoutForInlineFixture#check 1',
            'shoutForInlineFixture#check 2',
        ], array_map(
            static fn(TestDefinition $definition): string => $definition->id->name
                . ($definition->id->dataset !== null ? '#' . $definition->id->dataset : ''),
            $group->tests,
        ));
    }

    public function testReturnsClaimComparesWithEqualitySemantics(): void
    {
        $result = $this->run('Inline/Temperature.php', 'Temperature::toFahrenheit#check 1');

        self::assertSame(32.0, $result);
    }

    public function testNamedArgumentsBindByParameterName(): void
    {
        $result = $this->run('Inline/Temperature.php', 'Temperature::toFahrenheit#check 3');

        self::assertSame(104.0, $result);
    }

    public function testThrowsClaimAcceptsTheExpectedException(): void
    {
        self::assertNull($this->run('Inline/Temperature.php', 'Temperature::assertPhysical#check 1'));
    }

    public function testBareCheckClaimsTheCallCompletes(): void
    {
        self::assertSame(21.0, $this->run('Inline/Temperature.php', 'Temperature::assertPhysical#check 2'));
    }

    public function testInstanceMethodsRunOnAFreshInstance(): void
    {
        self::assertSame('mild', $this->run('Inline/Temperature.php', 'Temperature::feels#check 1'));
    }

    public function testNonPublicMethodsAreCheckable(): void
    {
        self::assertSame('brr', $this->run('Inline/Temperature.php', 'Temperature::whisper#check 1'));
    }

    public function testFreeFunctionsAreCheckable(): void
    {
        self::assertSame('CRUCIBLE', $this->run('Inline/Temperature.php', 'shoutForInlineFixture#check 1'));
    }

    public function testDoctestsRunOnTheExpectSurface(): void
    {
        $this->run('Inline/Temperature.php', 'Temperature#crucible 1');
        $this->run('Inline/Temperature.php', 'Temperature::feels#crucible 1');
        $this->run('Inline/Temperature.php', 'Temperature::feels#crucible 2');

        self::assertTrue(true); // three doctests evaluated without a failure
    }

    public function testGroupAttributesTravelIntoMetadata(): void
    {
        $group = $this->build('Inline/Temperature.php');
        $first = $group->tests[1]->metadata->first(Group::class);

        self::assertInstanceOf(Group::class, $first, 'The check carries no Group metadata.');

        self::assertSame('inline-fixture', $first->name);
    }

    public function testAWrongReturnsClaimFailsTheTest(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->run('Inline/WrongClaim.php', 'WrongClaim::identity#check 1');
    }

    public function testAnUnthrownExceptionFailsTheTest(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected RuntimeException to be thrown');

        $this->run('Inline/WrongClaim.php', 'WrongClaim::calm#check 1');
    }

    public function testAnUnexpectedExceptionEscapesUnwrapped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nobody claimed this one');

        $this->run('Inline/WrongClaim.php', 'WrongClaim::explode#check 1');
    }

    public function testAFailingDoctestFailsTheTest(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->run('Inline/WrongClaim.php', 'WrongClaim#crucible 1');
    }

    public function testClaimingBothReturnsAndThrowsIsALoadError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('pick one');

        $this->build('InlineInvalid/ConflictingClaims.php');
    }

    public function testARequiredConstructorArgumentIsALoadError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('constructor requires arguments');

        $this->build('InlineInvalid/NeedsConstructorArgs.php');
    }

    public function testADoctestThatDoesNotParseIsALoadError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not parse');

        $this->build('InlineInvalid/BrokenDoctest.php');
    }

    /**
     * The wrapper is `return ( <author text> );`. Without the
     * parentheses the documented one-expression contract was unenforced
     * — ✓ the payload below closed the closure, declared a function and
     * opened another, and the builder returned a Closure, built a
     * TestGroup and reported nothing.
     */
    public function testADoctestThatEscapesTheWrapperIsALoadError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not parse');

        $this->build('InlineInvalid/EscapingDoctest.php');
    }

    /**
     * And the parentheses change no answer: every real doctest in the
     * tree is one `expect()->toBe()` shape, so the forms most likely to
     * be disturbed are exercised here rather than reasoned about.
     */
    public function testTheWrappersParenthesesAreNeutralOverTheExpressionForms(): void
    {
        $group = $this->build('Inline/ExpressionForms.php');

        self::assertCount(6, $group->tests);

        foreach ($group->tests as $definition) {
            // expect() throws on a wrong answer, so reaching the next
            // row is the assertion.
            ($definition->test)([]);
        }
    }

    public function testDiscoveryScansTheSourceIncludes(): void
    {
        $groups = (new TestDiscoverer())->discover($this->configuration(), new WorkingDirectory($this->root()));

        $names = array_map(static fn(TestGroup $group): string => $group->name, $groups);

        self::assertContains(self::FIXTURES . '/Inline/Temperature.php', $names);
        self::assertContains(self::FIXTURES . '/Inline/WrongClaim.php', $names);
    }

    public function testTestsuiteSelectionSkipsInlineTests(): void
    {
        self::assertSame([], (new TestDiscoverer())->discover($this->configuration(), new WorkingDirectory($this->root()), ['unit']));
    }

    public function testSourceExcludesAreHonored(): void
    {
        $configuration = $this->configuration(excludeDirectories: [self::FIXTURES . '/Inline']);

        self::assertSame([], (new TestDiscoverer())->discover($configuration, new WorkingDirectory($this->root())));
    }

    /**
     * @param list<non-empty-string> $excludeDirectories
     */
    private function configuration(array $excludeDirectories = []): Configuration
    {
        return new Configuration(
            [],
            new Source(includeDirectories: [self::FIXTURES . '/Inline'], excludeDirectories: $excludeDirectories),
            new Php(),
        );
    }

    /**
     * @param non-empty-string $fixture
     */
    private function build(string $fixture): TestGroup
    {
        $group = (new InlineBuilder())->build(
            $this->root() . '/' . self::FIXTURES . '/' . $fixture,
            self::FIXTURES . '/' . $fixture,
        );

        self::assertInstanceOf(TestGroup::class, $group, $fixture . ' produced no inline test group.');

        return $group;
    }

    /**
     * Runs one inline definition the way the engine would.
     *
     * @param non-empty-string $fixture
     * @param non-empty-string $id name#dataset within the fixture
     */
    private function run(string $fixture, string $id): mixed
    {
        return ($this->definitionOf($this->build($fixture), $id)->test)([]);
    }

    /**
     * @param non-empty-string $id name#dataset within the group
     */
    private function definitionOf(TestGroup $group, string $id): TestDefinition
    {
        foreach ($group->tests as $definition) {
            $candidate = $definition->id->name
                . ($definition->id->dataset !== null ? '#' . $definition->id->dataset : '');

            if ($candidate === $id) {
                return $definition;
            }
        }

        self::fail($id . ' is not in the group.');
    }

    /**
     * @return non-empty-string
     */
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
