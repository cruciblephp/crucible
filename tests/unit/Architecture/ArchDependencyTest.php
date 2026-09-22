<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Architecture;

use Closure;
use LucianoPereira\Crucible\Architecture\Architecture;
use LucianoPereira\Crucible\Architecture\ArchitectureUniverse;
use LucianoPereira\Crucible\Architecture\ArchPredicates;
use LucianoPereira\Crucible\Architecture\ArchRule;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;

/**
 * What "uses" means for the dependency matchers, in both spellings.
 *
 * ✓ Settled by executing pest 5.1.1 (the package version — its own
 * banner prints 5.0.5 from a constant the release did not bump), not
 * by reasoning about which
 * reading is tidier: **only a reference that crosses the target's
 * boundary counts**. Two classes inside the same target referencing
 * each other are one unit as far as the rule is concerned, so
 * `expect('App\Models')->toUseNothing()` passes while a model uses a
 * sibling model and fails the moment one reaches out of `App\Models`.
 * The incumbent answers that way for `toUseNothing`, `toBeUsedInNothing`
 * and `toOnlyUse` alike — each measured, each with a control proving
 * the matcher can still fail.
 *
 * Targets are Crucible's own namespaces, where the answer is known
 * without consulting the implementation: `Clock` is four classes that
 * reference only each other, and the `PHPStan` rules are wired by
 * `phpstan.neon` rather than referenced from any source file.
 */
#[CoversClass(ArchPredicates::class)]
#[CoversClass(ArchRule::class)]
#[CoversClass(Expectation::class)]
final class ArchDependencyTest extends TestCase
{
    /** Four classes that reference each other and nothing else of ours. */
    private const string SIBLINGS_ONLY = 'LucianoPereira\Crucible\Clock';

    /**
     * Referenced by nothing OUTSIDE itself in src/ — the rules are wired
     * by phpstan.neon — but its classes do reference each other, which
     * is what makes it the inbound sibling case.
     */
    private const string WIRED_BY_CONFIG = 'LucianoPereira\Crucible\PHPStan';

    /** Reaches Configuration, Dialect and Impact, so it uses plenty. */
    private const string REACHES_OUT = 'LucianoPereira\Crucible\Architecture';

    /**
     * The pest spelling reads the ambient universe, so it is set here
     * explicitly rather than inherited from whatever ran before. It is
     * deliberately NOT reset afterwards: the run configures the same
     * universe at bootstrap (`crucible.php` sources `src`), and
     * resetting it leaves every later arch test targeting nothing.
     */
    protected function setUp(): void
    {
        Architecture::configure(new Source(includeDirectories: ['src']), new WorkingDirectory(dirname(__DIR__, 3)));
    }

    public function testASiblingReferenceInsideTheTargetIsNotAUse(): void
    {
        self::assertTrue($this->arch(self::SIBLINGS_ONLY, 'toUseNothing'));
        self::assertTrue($this->expect(self::SIBLINGS_ONLY, 'toUseNothing'));
    }

    public function testAReferenceOutOfTheTargetIsAUse(): void
    {
        self::assertFalse($this->arch(self::REACHES_OUT, 'toUseNothing'));
        self::assertFalse($this->expect(self::REACHES_OUT, 'toUseNothing'));
    }

    /**
     * ⚠ This asserted the OPPOSITE until 2026-09-06, and was wrong.
     *
     * The 2026-09-02 measurement established that only a reference
     * crossing the target's boundary counts, and applied it to both
     * directions. ✓ Re-measured against pest 5.1.1 with the failure
     * message printed: `expect('…\Deps\Pure')->toBeUsedInNothing()`
     * fails with "Expecting 'Deps\Pure\Beta' not to be used on
     * 'Deps\Pure\Alpha'" — both classes inside the target. The
     * incumbent is asymmetric: outbound ignores siblings, inbound does
     * not.
     *
     * The control that day had no sibling reference in the inbound
     * direction, so it proved the outbound half twice and this test
     * pinned the wrong rule in place.
     */
    public function testASiblingReferenceDoesMakeAClassUsed(): void
    {
        self::assertFalse($this->arch(self::WIRED_BY_CONFIG, 'toBeUsedInNothing'));
        self::assertFalse($this->expect(self::WIRED_BY_CONFIG, 'toBeUsedInNothing'));
    }

    public function testAReferenceFromOutsideMakesAClassUsed(): void
    {
        $used = \LucianoPereira\Crucible\Assert\ValueType::class;

        self::assertFalse($this->arch($used, 'toBeUsedInNothing'));
        self::assertFalse($this->expect($used, 'toBeUsedInNothing'));
    }

    /**
     * The property the shared predicates exist for, asserted over every
     * case above rather than over one example — two implementations
     * agreeing on one input is what five copies of a verdict looked
     * like before one of them drifted (D-108).
     */
    public function testBothSpellingsAgreeOnEveryCase(): void
    {
        $cases = [
            [self::SIBLINGS_ONLY, 'toUseNothing'],
            [self::REACHES_OUT, 'toUseNothing'],
            [self::WIRED_BY_CONFIG, 'toUseNothing'],
            [self::SIBLINGS_ONLY, 'toBeUsedInNothing'],
            [self::REACHES_OUT, 'toBeUsedInNothing'],
            [self::WIRED_BY_CONFIG, 'toBeUsedInNothing'],
        ];

        foreach ($cases as [$target, $matcher]) {
            self::assertSame(
                $this->arch($target, $matcher),
                $this->expect($target, $matcher),
                $matcher . ' answers differently for ' . $target,
            );
        }
    }

    /** Did the `arch()` spelling pass? */
    private function arch(string $target, string $matcher): bool
    {
        return $this->passes(function () use ($target, $matcher): void {
            $rule = new ArchRule(new ArchitectureUniverse(
                new Source(includeDirectories: ['src']),
                new WorkingDirectory(dirname(__DIR__, 3)),
            ));

            $chain = $rule->expect($target);

            // Named rather than dynamic: the rule's fluent return type
            // is what makes assert() reachable at all.
            ($matcher === 'toUseNothing' ? $chain->toUseNothing() : $chain->toBeUsedInNothing())->assert();
        });
    }

    /** Did the pest `expect()` spelling pass? */
    private function expect(string $target, string $matcher): bool
    {
        return $this->passes(static function () use ($target, $matcher): void {
            (new Expectation($target))->{$matcher}();
        });
    }

    private function passes(Closure $work): bool
    {
        try {
            $work();
        } catch (AssertionFailedError) {
            return false;
        }

        return true;
    }
}
