<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Architecture;

use Closure;
use Composer\Autoload\ClassLoader;
use LucianoPereira\Crucible\Assert\Assert;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use UnitEnum;

use function array_any;
use function array_keys;
use function array_values;
use function class_exists;
use function implode;
use function interface_exists;
use function is_a;
use function preg_match;
use function preg_quote;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function trait_exists;

/**
 * One architecture rule (D-088): a set of targeted classes and the
 * expectations they must satisfy.
 *
 * Built fluently and evaluated once, when the test that `arch()`
 * registered runs — so a rule is an ordinary test with an ordinary
 * failure, counted and reported like every other assertion rather than
 * living in a parallel reporting world.
 *
 * The targeting layer is the load-bearing part. Expectations are cheap
 * to add once "which classes does this rule mean?" has a single answer,
 * so that question is settled first and every expectation reads the one
 * resolved set.
 */
final class ArchRule
{
    /** @var list<string> */
    private array $targets = [];

    /** @var list<string> */
    private array $ignored = [];

    /** @var list<Closure(): void> */
    private array $expectations = [];

    /** @var list<string> the targets, resolved once by assert() */
    private array $resolved = [];

    /**
     * The universe is resolved **when the rule runs**, not when it is
     * built: `arch()` is called while a file is being collected, and the
     * run configures the ambient afterwards. Binding eagerly gave every
     * rule an empty universe — caught by the "matches nothing" guard
     * below rather than passing silently, which is the whole reason that
     * guard exists.
     */
    public function __construct(private ?ArchitectureUniverse $universe = null) {}

    /**
     * The classes this rule is about, by namespace or exact name.
     * `*` matches within a namespace segment, `**` across segments, and
     * a pattern with no wildcard is a prefix — naming a namespace is
     * what a rule almost always means.
     */
    public function expect(string ...$targets): self
    {
        foreach ($targets as $target) {
            $this->targets[] = $target;
        }

        return $this;
    }

    /** Classes the rule does not apply to; same pattern grammar. */
    public function ignoring(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->ignored[] = $pattern;
        }

        return $this;
    }

    // -- dependencies ---------------------------------------------------------

    /** Every targeted class references at least one of these. */
    public function toUse(string ...$prefixes): self
    {
        $prefixes = array_values($prefixes);

        return $this->each(
            fn(string $class): bool => array_any(
                $this->universe()->referencesOf($class),
                static fn(string $reference): bool => self::matchesAny($reference, $prefixes),
            ),
            sprintf('use %s', implode(' or ', $prefixes)),
        );
    }

    /**
     * Every reference a targeted class makes **into the project's own
     * source** is one of these — the layering rule. It says what a
     * module may depend on, and names anything else, which is the part
     * that makes it useful.
     *
     * References outside the configured source — PHP built-ins like
     * `PhpToken`, and vendor packages — are not considered. This is a
     * rule about your own layering, and counting `Closure` as a
     * dependency would force every rule to carry a whitelist of the
     * standard library before it said anything at all.
     */
    public function toOnlyUse(string ...$prefixes): self
    {
        $prefixes = array_values($prefixes);

        $this->expectations[] = function () use ($prefixes): void {
            $violations = [];

            foreach ($this->ownReferences() as $pair) {
                if (!self::matchesAny($pair[1], $prefixes)) {
                    $violations[] = sprintf('%s uses %s', $pair[0], $pair[1]);
                }
            }

            Assert::assertSame([], $violations, sprintf(
                'Expected the targeted classes to only use %s.',
                implode(' or ', $prefixes),
            ));
        };

        return $this;
    }

    /** No targeted class references anything from the project's source. */
    public function toUseNothing(): self
    {
        $this->expectations[] = function (): void {
            $violations = [];

            foreach ($this->ownReferences() as $pair) {
                $violations[] = sprintf('%s uses %s', $pair[0], $pair[1]);
            }

            Assert::assertSame([], $violations, 'Expected the targeted classes to use nothing from the source.');
        };

        return $this;
    }

    /**
     * Only these may reference the targeted classes — the inverse
     * layering rule, and the one that catches a boundary crossed from
     * the far side, where the offending code is not the code the rule
     * is about.
     */
    public function toOnlyBeUsedIn(string ...$prefixes): self
    {
        $prefixes = array_values($prefixes);

        $this->expectations[] = function () use ($prefixes): void {
            $violations = [];

            foreach (ArchPredicates::inboundPairs($this->universe(), $this->resolved) as [$user, $class]) {
                if (!self::matchesAny($user, $prefixes)) {
                    $violations[] = sprintf('%s uses %s', $user, $class);
                }
            }

            Assert::assertSame([], $violations, sprintf(
                'Expected the targeted classes to only be used in %s.',
                implode(' or ', $prefixes),
            ));
        };

        return $this;
    }

    // -- file quality ---------------------------------------------------------

    /** Every targeted class's file declares strict types. */
    public function toUseStrictTypes(): self
    {
        return $this->each(fn(string $class): bool => ArchPredicates::declaresStrictTypes($this->universe())($class), 'declare strict types');
    }

    // -- shape ----------------------------------------------------------------

    public function toBeFinal(): self
    {
        return $this->reflected(static fn(ReflectionClass $class): bool => $class->isFinal(), 'be final');
    }

    public function toBeReadonly(): self
    {
        return $this->reflected(static fn(ReflectionClass $class): bool => $class->isReadOnly(), 'be readonly');
    }

    public function toBeAbstract(): self
    {
        return $this->reflected(static fn(ReflectionClass $class): bool => $class->isAbstract(), 'be abstract');
    }

    public function toBeInterfaces(): self
    {
        return $this->reflected(static fn(ReflectionClass $class): bool => $class->isInterface(), 'be interfaces');
    }

    /**
     * Shape matchers, pinned against Pest 5.1.1 by reading the
     * incumbent rather than by inference: there, the PLURAL name is
     * literally `return $this->toBeClass();` — an alias, not a
     * different question. Both spellings are carried here for the same
     * reason, so a rule reads correctly whether it names one symbol or
     * a namespace.
     */
    public function toBeClasses(): self
    {
        return $this->reflected(
            static fn(ReflectionClass $class): bool => !$class->isInterface() && !$class->isTrait() && !$class->isEnum(),
            'be classes',
        );
    }

    public function toBeTraits(): self
    {
        return $this->reflected(static fn(ReflectionClass $class): bool => $class->isTrait(), 'be traits');
    }

    public function toBeEnums(): self
    {
        return $this->reflected(static fn(ReflectionClass $class): bool => $class->isEnum(), 'be enums');
    }

    public function toBeIntBackedEnums(): self
    {
        return $this->backedBy('int');
    }

    public function toBeStringBackedEnums(): self
    {
        return $this->backedBy('string');
    }

    /** No targeted class has a parent. */
    public function toExtendNothing(): self
    {
        return $this->each(ArchPredicates::extendsNothing(), 'extend nothing');
    }

    /** No targeted class implements an interface. */
    public function toImplementNothing(): self
    {
        return $this->each(ArchPredicates::implementsNothing(), 'implement nothing');
    }

    /**
     * Nothing outside the target references a targeted class —
     * `toOnlyBeUsedIn()` with an empty allowlist, which is how it is
     * implemented rather than as a second traversal. A reference from
     * one targeted class to another does not count: ✓ measured against
     * the incumbent, which reads the target as one unit.
     */
    public function toBeUsedInNothing(): self
    {
        // Reads usedByNothing(), NOT toOnlyBeUsedIn(): the two questions
        // differ on a sibling. "Nothing uses it" counts a user inside
        // the target set — ✓ measured against pest 5.1.1 on 2026-09-06,
        // which fails `expect('…\Deps\Pure')->toBeUsedInNothing()`
        // naming two classes both INSIDE the target — while "only these
        // may use it" is a question about outside users and keeps
        // inboundPairs().
        //
        // Delegating to toOnlyBeUsedIn() is what made the two spellings
        // of one dialect answer differently, which ArchDependencyTest
        // exists to catch.
        $this->expectations[] = function (): void {
            $used = ArchPredicates::usedByNothing($this->universe(), $this->resolved);

            $violations = [];

            foreach ($this->resolved as $class) {
                if (!$used($class)) {
                    $violations[] = $class;
                }
            }

            Assert::assertSame([], $violations, 'Expected the targeted classes to be used by nothing.');
        };

        return $this;
    }

    /**
     * ✓ Pinned against the incumbent, which reads the source text for
     * `' == '` and `' != '` — spaced, so `$a == $b` is caught while
     * `==` inside a string or a comment is not distinguished. Copied
     * deliberately: the point is the same answer, and a cleverer
     * token-aware reading would diverge on the exact files a user is
     * likely to test.
     */
    public function toUseStrictEquality(): self
    {
        return $this->each(fn(string $class): bool => ArchPredicates::usesStrictEquality($this->universe())($class), 'use strict equality');
    }

    /**
     * The class a file declares is the class its path implies.
     *
     * ✓ Pinned to the incumbent, which derives the expected name from
     * composer's PSR-4 map — directory prefix stripped, separators
     * turned into namespace separators — and compares it to the real
     * one. Read through the registered ClassLoader, the same way the
     * impact tier already finds it, so a project's own autoload
     * configuration is what answers rather than a convention assumed
     * here.
     */
    public function toBeCasedCorrectly(): self
    {
        return $this->each(fn(string $class): bool => ArchPredicates::casedCorrectly($this->universe())($class), 'be named as their path implies');
    }

    /**
     * Every method the file itself declares carries a docblock.
     *
     * ✓ The incumbent's three qualifications are kept, because each
     * changes the answer: an inherited method is not this file's to
     * document (compared by realpath), an enum's generated `from`,
     * `tryFrom` and `cases` have no source to annotate, and the check
     * is for a docblock's PRESENCE, not its content.
     */
    public function toHaveMethodsDocumented(): self
    {
        return $this->each(fn(string $class): bool => ArchPredicates::documentsMethods($this->universe())($class), 'document their methods');
    }

    /**
     * The same for properties, minus promoted ones — a promoted
     * property is a constructor parameter, and the constructor is where
     * it would be documented.
     */
    public function toHavePropertiesDocumented(): self
    {
        return $this->each(fn(string $class): bool => ArchPredicates::documentsProperties($this->universe())($class), 'document their properties');
    }



    /**
     * @param 'int'|'string' $type
     */
    private function backedBy(string $type): self
    {
        return $this->reflected(
            static function (ReflectionClass $class) use ($type): bool {
                $name = $class->getName();

                // is_a() rather than isEnum() alone: it is what narrows
                // the name to a UnitEnum class-string, which is what
                // ReflectionEnum requires.
                if (!$class->isEnum() || !is_a($name, UnitEnum::class, true)) {
                    return false;
                }

                $backing = (new ReflectionEnum($name))->getBackingType();

                // Casting a ReflectionType to string is deprecated; a
                // backed enum's type is always a named one.
                return $backing instanceof ReflectionNamedType && $backing->getName() === $type;
            },
            sprintf('be %s-backed enums', $type),
        );
    }

    public function toExtend(string $parent): self
    {
        return $this->reflected(
            static fn(ReflectionClass $class): bool => $class->isSubclassOf($parent),
            sprintf('extend %s', $parent),
        );
    }

    public function toImplement(string $interface): self
    {
        return $this->reflected(
            static fn(ReflectionClass $class): bool => $class->implementsInterface($interface),
            sprintf('implement %s', $interface),
        );
    }

    public function toHaveSuffix(string $suffix): self
    {
        return $this->each(
            static fn(string $class): bool => str_ends_with($class, $suffix),
            sprintf('have the suffix %s', $suffix),
        );
    }

    // -- evaluation -----------------------------------------------------------

    /**
     * Runs every expectation against the resolved targets.
     *
     * An `arch()` that matches nothing is a **failure**, not a silent
     * pass: a rule whose namespace was renamed would otherwise stay
     * green forever while enforcing nothing, which is the worst outcome
     * on offer.
     */
    public function assert(): void
    {
        $this->resolved = $this->resolve();

        Assert::assertNotSame([], $this->resolved, sprintf(
            'No class matches %s — an architecture rule that targets nothing enforces nothing.',
            implode(', ', $this->targets),
        ));

        Assert::assertNotSame([], $this->expectations, 'This architecture rule states no expectation.');

        foreach ($this->expectations as $expectation) {
            $expectation();
        }
    }

    /**
     * Resolved when the rule RUNS, never when it is built.
     *
     * `arch()` chains are assembled while a file is being collected,
     * and `Architecture::configure()` has not run yet at that point —
     * so a matcher that reaches for the universe eagerly memoizes the
     * empty pre-configuration one and the rule then matches nothing.
     * ✓ Measured: doing exactly that made every rule in
     * tests/unit/Architecture/rules.pest.php resolve to nothing, in
     * 0.002s instead of 0.064s. The predicates below are therefore
     * built per call rather than per chain.
     */
    private function universe(): ArchitectureUniverse
    {
        return $this->universe ??= Architecture::universe();
    }

    /**
     * Every (class, reference) pair where the reference is another class
     * of the project's own source — the shape both dependency rules read.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function ownReferences(): array
    {
        $pairs = [];

        foreach ($this->resolved as $class) {
            foreach (ArchPredicates::crossingsOf($this->universe(), $this->resolved, $class) as $reference) {
                $pairs[] = [$class, $reference];
            }
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    private function resolve(): array
    {
        $classes = [];

        foreach (array_keys($this->universe()->classes()) as $class) {
            if (self::matchesAny($class, $this->targets) && !self::matchesAny($class, $this->ignored)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param Closure(string): bool $predicate
     */
    private function each(Closure $predicate, string $description): self
    {
        $this->expectations[] = function () use ($predicate, $description): void {
            $violations = [];

            foreach ($this->resolved as $class) {
                if (!$predicate($class)) {
                    $violations[] = $class;
                }
            }

            Assert::assertSame([], $violations, sprintf('Expected every targeted class to %s.', $description));
        };

        return $this;
    }

    /**
     * @param Closure(ReflectionClass<object>): bool $predicate
     */
    private function reflected(Closure $predicate, string $description): self
    {
        return $this->each(
            static function (string $class) use ($predicate): bool {
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                    return false;
                }

                return $predicate(new ReflectionClass($class));
            },
            $description,
        );
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(string $class, array $patterns): bool
    {
        return array_any($patterns, fn($pattern) => self::matches($class, $pattern));
    }

    private static function matches(string $class, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return $class === $pattern || str_starts_with($class, rtrim($pattern, '\\') . '\\');
        }

        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\*\*', '.*', $regex);
        $regex = str_replace('\*', '[^\\\\\\\\]*', $regex);

        return preg_match('/^' . $regex . '$/', $class) === 1;
    }
}
