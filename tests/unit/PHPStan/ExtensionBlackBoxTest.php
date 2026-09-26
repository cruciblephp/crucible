<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\AssertMethodTypeSpecifyingExtension;
use LucianoPereira\Crucible\PHPStan\AssertStaticMethodTypeSpecifyingExtension;
use LucianoPereira\Crucible\PHPStan\DialectClosureThisExtension;
use LucianoPereira\Crucible\PHPStan\DialectMagicReflectionExtension;
use LucianoPereira\Crucible\PHPStan\DialectMethodClosureThisExtension;
use LucianoPereira\Crucible\PHPStan\DialectThisResolver;
use LucianoPereira\Crucible\PHPStan\MockeryMockReflectionExtension;
use LucianoPereira\Crucible\PHPStan\PropertyClosureTypeExtension;
use LucianoPereira\Crucible\PHPStan\PropertyFunctionClosureTypeExtension;
use LucianoPereira\Crucible\PHPStan\PropertyParameterRule;

/**
 * The extension proven the same way the conformance suite proves the
 * engine: black-box, through the real phpstan binary. One fixture,
 * one run, three claims — the PHPUnit-shaped parent resolves (alias
 * bootstrap), the narrowed calls analyse clean (both extensions), and
 * exactly one sentinel error survives (the analysis is not vacuous).
 */
#[CoversClass(AssertStaticMethodTypeSpecifyingExtension::class)]
#[CoversClass(AssertMethodTypeSpecifyingExtension::class)]
#[CoversClass(DialectThisResolver::class)]
#[CoversClass(DialectClosureThisExtension::class)]
#[CoversClass(DialectMethodClosureThisExtension::class)]
#[CoversClass(DialectMagicReflectionExtension::class)]
#[CoversClass(MockeryMockReflectionExtension::class)]
#[CoversClass(PropertyClosureTypeExtension::class)]
#[CoversClass(PropertyFunctionClosureTypeExtension::class)]
#[CoversClass(PropertyParameterRule::class)]
#[Group('phpstan-blackbox')]
final class ExtensionBlackBoxTest extends TestCase
{
    public function testTheFixtureAnalysesToExactlyTheSentinel(): void
    {
        $rendered = Analysis::rendered(Analysis::run([Analysis::root() . '/tests/_fixtures/phpstan/AliasNarrowingFixture.php'], Analysis::extension()));

        // Exactly the sentinel plus the two D-051 type dumps: no
        // class.notFound (aliases resolved), no failures on any
        // narrowed call — including the named-argument probe, which
        // PHPStan normalizes before the extension runs — and the
        // property closure parameters carry their exact per-position
        // types recovered from the forAll chain.
        self::assertSame([
            'argument.type: Parameter #1 $value of method '
                . 'LucianoPereira\Crucible\Tests\Fixtures\PHPStan\AliasNarrowingFixture::half() expects int, string given.',
            'phpstan.dumpType: Dumped type: int',
            'phpstan.dumpType: Dumped type: list<string>',
            'phpstan.dumpType: Dumped type: bool',
            'crucible.propertyParameter: The check() closure parameter $wrong declares string, but its generator supplies int.',
            'crucible.propertyParameter: The property() closure parameter $alsoWrong declares int, but its generator supplies string.',
        ], $rendered);
    }

    public function testASpreadEachLeavesTheValueToTheItems(): void
    {
        $rendered = Analysis::rendered(Analysis::run([Analysis::root() . '/tests/_fixtures/phpstan/EachSpreadFixture.php'], Analysis::extension()));
        $chain    = 'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Dialect\\Pest\\Expectation<';

        self::assertSame([
            'return.unusedType: Function LucianoPereira\\Crucible\\Tests\\Fixtures\\PHPStan\\listOrText() never returns string so it can be removed from the return type.',
            // The matchers after a spread are about the items: the value
            // stays as it was, however many follow.
            $chain . 'list<int>|string>',
            $chain . 'list<int>|string>',
            // What came before the spread holds; each($callback) is no spread.
            $chain . 'list<int>>',
            $chain . 'list<int>>',
            // The variable, by the same reading.
            'phpstan.dumpType: Dumped type: list<int>',
            'phpstan.dumpType: Dumped type: list<int>',
            'phpstan.dumpType: Dumped type: list<int>|string',
        ], $rendered);
    }

    public function testScopedUsesInResolvesThroughAncestorPestConfigs(): void
    {
        $tree = Analysis::root() . '/tests/_fixtures/phpstan/scoped';

        // A real project's uses() classes come in through its
        // autoloader; the fixture tree has none, so the class loads the
        // same way the D-050 statement describes.
        $rendered = Analysis::rendered(Analysis::run([$tree . '/Feature'], Analysis::extension(), parameters: [
            'bootstrapFiles' => [$tree . '/ScopedCase.php'],
        ]));

        // $this resolves to the class the ancestor Pest.php scoped in
        // (D-067) — the espresso() call analyses clean, and the dump
        // proves the type is the scoped class, not the default.
        self::assertSame(
            ['phpstan.dumpType: Dumped type: LucianoPereira\Crucible\Tests\Fixtures\PHPStan\Scoped\ScopedCase'],
            $rendered,
        );
    }

    public function testTheMethodLevelMagicAndMockerySurfacesResolve(): void
    {
        $tree = Analysis::root() . '/tests/_fixtures/phpstan/dialect';

        $rendered = Analysis::rendered(Analysis::run([$tree . '/Feature'], Analysis::extension(), parameters: [
            'bootstrapFiles' => [$tree . '/DialectCase.php'],
        ]));

        $case = 'LucianoPereira\\Crucible\\Tests\\Fixtures\\PHPStan\\Dialect\\DialectCase';

        // Three surfaces, one run, and nothing but the dumps — a
        // method.notFound or an undefined-member error anywhere here
        // would mean the extension did not fire.
        self::assertSame([
            // ScopeRegistration hooks and TestCall closures bind to
            // the scoped class, the method-level half of D-050.
            'phpstan.dumpType: Dumped type: ' . $case,
            'phpstan.dumpType: Dumped type: string',
            'phpstan.dumpType: Dumped type: ' . $case,
            'phpstan.dumpType: Dumped type: ' . $case,
            // The magic grammar: an undeclared member continues the
            // chain in kind rather than being an unknown method.
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Dialect\\Pest\\Expectation',
            // D-128: the chain keeps its value's type through a magic step.
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Dialect\\Pest\\Expectation<\'a\'>',
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Dialect\\Pest\\TestCall',
            // D-060: the four verbs open an expectation, anything
            // else on a mock is the runtime's mixed.
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Double\\Mockery\\MockeryExpectation',
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Double\\Mockery\\MockeryExpectation',
            'phpstan.dumpType: Dumped type: mixed',
        ], $rendered);
    }
}
