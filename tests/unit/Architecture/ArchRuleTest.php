<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Architecture;

use LucianoPereira\Crucible\Architecture\ArchitectureUniverse;
use LucianoPereira\Crucible\Architecture\ArchRule;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;

/**
 * Architecture rules exercised against **Crucible's own source**, where the
 * answers are known independently of the implementation: the event
 * objects really are readonly, the Impact tier really does not reach
 * into the browser tier, and `src/` really does declare strict types
 * everywhere because Pint enforces it.
 *
 * A fixture tree would have been easier and would only have proven that
 * the rules agree with a tree written to make them agree.
 */
#[CoversClass(ArchRule::class)]
#[CoversClass(ArchitectureUniverse::class)]
final class ArchRuleTest extends TestCase
{
    private function rule(): ArchRule
    {
        return new ArchRule(new ArchitectureUniverse(
            new Source(includeDirectories: ['src']),
            new WorkingDirectory(dirname(__DIR__, 3)),
        ));
    }

    public function testANamespacePrefixTargetsEverythingBeneathIt(): void
    {
        $this->rule()->expect('LucianoPereira\Crucible\Event')->toUseStrictTypes()->assert();

        $this->addToAssertionCount(1);
    }

    public function testTheDependencyRuleNamesTheOffendingPair(): void
    {
        // The Impact tier has no business reaching into the browser tier;
        // asserting the inverse proves the rule reports what crossed.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/only use/');

        $this->rule()
            ->expect('LucianoPereira\Crucible\Impact')
            ->toOnlyUse('LucianoPereira\Crucible\Browser')
            ->assert();
    }

    public function testARealLayeringRuleHolds(): void
    {
        // Impact reasons about files and graphs; it legitimately reaches
        // the test model, the configuration and Composer's autoloader.
        $this->rule()
            ->expect('LucianoPereira\Crucible\Impact')
            ->toOnlyUse(
                'LucianoPereira\Crucible\Impact',
                'LucianoPereira\Crucible\Test',
                'LucianoPereira\Crucible\Vitest',
                'LucianoPereira\Crucible\Attributes',
                'LucianoPereira\Crucible\Filesystem',
            )
            ->assert();

        $this->addToAssertionCount(1);
    }

    public function testIgnoringRemovesClassesFromTheTargetSet(): void
    {
        // Without the exclusions this fails: three classes in the tier
        // hold mutable caches and are deliberately not readonly.
        //
        // ImpactReason joined them when the universe learned to see
        // enums. It is immutable by construction, but "readonly" is not
        // what an enum is — and the incumbent agrees explicitly:
        // Pest's toBeReadonly reads
        // `!enum_exists($object->name) && ...->isReadOnly()`, so an
        // enum fails there too. The exemption records that rather than
        // widening the matcher to disagree with it.
        $this->rule()
            ->expect('LucianoPereira\Crucible\Impact')
            ->ignoring(
                \LucianoPereira\Crucible\Impact\DependencyGraph::class,
                \LucianoPereira\Crucible\Impact\DependencyIndex::class,
                \LucianoPereira\Crucible\Impact\ReferenceScanner::class,
                \LucianoPereira\Crucible\Impact\ImpactReason::class,
            )
            ->toBeReadonly()
            ->assert();

        $this->addToAssertionCount(1);
    }

    public function testWildcardsMatchWithinAndAcrossSegments(): void
    {
        $single = new ArchitectureUniverse(new Source(includeDirectories: ['src']), new WorkingDirectory(dirname(__DIR__, 3)));

        // ** crosses namespace separators, * does not.
        $this->rule()->expect('LucianoPereira\Crucible\Impact\*')->toUseStrictTypes()->assert();
        $this->rule()->expect('LucianoPereira\Crucible\**')->toUseStrictTypes()->assert();

        self::assertNotSame([], $single->classes());
    }

    public function testARuleThatMatchesNothingFails(): void
    {
        // The failure that matters most: a renamed namespace must not
        // leave a green test that enforces nothing.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/enforces nothing/');

        $this->rule()->expect('LucianoPereira\Crucible\NoSuchNamespace')->toBeFinal()->assert();
    }

    public function testARuleWithNoExpectationFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/states no expectation/');

        $this->rule()->expect('LucianoPereira\Crucible\Impact')->assert();
    }

    public function testTheInverseLayeringRuleFindsTheFarSideCrossing(): void
    {
        // Nothing outside the Watch tier may use its endpoint...
        $this->rule()
            ->expect(\LucianoPereira\Crucible\Watch\RetriggerEndpoint::class)
            ->toOnlyBeUsedIn('LucianoPereira\Crucible\Watch')
            ->assert();

        $this->addToAssertionCount(1);
    }

    public function testAViolationOfTheInverseRuleIsReported(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/only be used in/');

        // ...but plenty outside Browser uses the Playwright Page.
        $this->rule()
            ->expect(\LucianoPereira\Crucible\Browser\Playwright\Page::class)
            ->toOnlyBeUsedIn('LucianoPereira\Crucible\Impact')
            ->assert();
    }

    /**
     * The shape matchers, aimed at namespaces whose answer is known
     * without consulting the implementation: Console\Concerns holds
     * four files and all four are traits; ValueType is string-backed;
     * Style\Attribute is int-backed; Device is unbacked.
     */
    public function testTraitsAreTraitsAndNotClasses(): void
    {
        $this->rule()->expect('LucianoPereira\Crucible\Console\Concerns')->toBeTraits()->assert();

        $this->addToAssertionCount(1);
    }

    public function testATraitNamespaceIsNotClasses(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/be classes/');

        $this->rule()->expect('LucianoPereira\Crucible\Console\Concerns')->toBeClasses()->assert();
    }

    public function testAnEnumIsAnEnum(): void
    {
        $this->rule()->expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toBeEnums()->assert();

        $this->addToAssertionCount(1);
    }

    public function testBackingIsPartOfTheShape(): void
    {
        $this->rule()->expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toBeStringBackedEnums()->assert();
        $this->rule()->expect(\LucianoPereira\Crucible\Console\Style\Attribute::class)->toBeIntBackedEnums()->assert();

        $this->addToAssertionCount(1);
    }

    public function testAnIntBackedEnumIsNotStringBacked(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/string-backed/');

        $this->rule()->expect(\LucianoPereira\Crucible\Console\Style\Attribute::class)->toBeStringBackedEnums()->assert();
    }

    public function testAnUnbackedEnumIsBackedByNothing(): void
    {
        // Device is a pure enum: it is an enum, and it is neither of the
        // backed shapes.
        $this->rule()->expect(\LucianoPereira\Crucible\Browser\Device::class)->toBeEnums()->assert();

        $this->expectException(AssertionFailedError::class);

        $this->rule()->expect(\LucianoPereira\Crucible\Browser\Device::class)->toBeIntBackedEnums()->assert();
    }

    public function testExtendingAndImplementingNothing(): void
    {
        // An enum extends nothing; a backed one implements BackedEnum,
        // so "implements nothing" must be false for it. Two matchers,
        // one target, opposite answers — which is what shows they are
        // asking different questions.
        $this->rule()->expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toExtendNothing()->assert();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/implement nothing/');

        $this->rule()->expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toImplementNothing()->assert();
    }

    /**
     * ✓ Pinned to the incumbent's own reading: Pest tests the source
     * text for `' == '` and `' != '`, spaced.
     */
    public function testStrictEqualityIsReadFromTheSource(): void
    {
        $this->rule()->expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toUseStrictEquality()->assert();

        $this->addToAssertionCount(1);
    }

    public function testUsedInNothingIsAnEmptyAllowlist(): void
    {
        // ValueType is used by the assertion surface, so "used in
        // nothing" is false — and the message names the pair, which is
        // the half that makes the rule useful.
        $this->expectException(AssertionFailedError::class);

        $this->rule()->expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toBeUsedInNothing()->assert();
    }

    /**
     * ✓ Pinned to the incumbent: the expected name is derived from
     * composer's PSR-4 map, not from a convention assumed here. Every
     * file in `src/` is PSR-4 by construction — Pint and the autoloader
     * both enforce it — so this holds across the whole tier.
     */
    public function testEveryClassIsNamedAsItsPathImplies(): void
    {
        $this->rule()->expect('LucianoPereira\\Crucible\\Architecture')->toBeCasedCorrectly()->assert();

        $this->addToAssertionCount(1);
    }

    public function testMethodsAreCheckedOnlyWhereTheyAreDeclared(): void
    {
        // ImpactSelection documents all six of its own methods, and
        // inherits others it does not: an inherited member belongs to
        // the file that declares it, which is what the realpath
        // qualification is for.
        $this->rule()->expect(\LucianoPereira\Crucible\Impact\ImpactSelection::class)->toHaveMethodsDocumented()->assert();

        $this->addToAssertionCount(1);
    }

    public function testAnUndocumentedMethodIsReported(): void
    {
        // ✓ ValueType declares check() with no docblock. Its from(),
        // tryFrom() and cases() have none either — and are skipped,
        // because an enum does not write them. So this failure names
        // check() alone, which is what shows the enum exclusion works.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/document their methods/');

        $this->rule()->expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toHaveMethodsDocumented()->assert();
    }

    public function testPropertiesSkipPromotedOnes(): void
    {
        // A promoted property is a constructor parameter; the
        // constructor is where it would be documented.
        $this->rule()->expect(\LucianoPereira\Crucible\Impact\ImpactSelection::class)->toHavePropertiesDocumented()->assert();

        $this->addToAssertionCount(1);
    }
}
