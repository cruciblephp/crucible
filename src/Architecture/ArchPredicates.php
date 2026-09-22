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
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use function array_filter;
use function array_flip;
use function array_keys;
use function array_values;
use function enum_exists;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function preg_match;
use function realpath;
use function rtrim;
use function spl_autoload_functions;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * What an architecture question asks of ONE class, in one place.
 *
 * Two spellings reach these now — `arch()->expect(...)` and the pest
 * `expect(...)` arch matchers (D1) — and the answer has to be the same
 * from both. Holding the predicates here is what makes that structural
 * rather than a promise: there is one implementation to be right or
 * wrong, so the two spellings cannot drift into disagreeing.
 *
 * That is not a general preference for sharing. It is the specific
 * lesson of D-108, where one verdict classification lived in five
 * copies and the copies produced a false green — and of the guards
 * beside it, which are deliberately NOT shared because they answer
 * different questions that merely share a position. These answer the
 * same question, so they are one.
 */
final readonly class ArchPredicates
{
    /**
     * Enum members with no source to annotate; read in both directions.
     *
     * @var list<non-empty-string>
     */
    private const array GENERATED_METHODS = ['from', 'tryFrom', 'cases'];

    /** @var list<non-empty-string> */
    private const array GENERATED_PROPERTIES = ['value', 'name'];

    /** @return Closure(string): bool */
    public static function extendsNothing(): Closure
    {
        return static function (string $class): bool {
            /** @var class-string $class */
            return (new ReflectionClass($class))->getParentClass() === false;
        };
    }

    /** ✓ The incumbent's `not->toExtendNothing`: "extends a class". @return Closure(string): bool */
    public static function extendsSomething(): Closure
    {
        return static function (string $class): bool {
            /** @var class-string $class */
            return (new ReflectionClass($class))->getParentClass() !== false;
        };
    }

    /** @return Closure(string): bool */
    public static function implementsNothing(): Closure
    {
        return static function (string $class): bool {
            /** @var class-string $class */
            return (new ReflectionClass($class))->getInterfaceNames() === [];
        };
    }

    /** ✓ The incumbent's `not->toImplementNothing`. @return Closure(string): bool */
    public static function implementsSomething(): Closure
    {
        return static function (string $class): bool {
            /** @var class-string $class */
            return (new ReflectionClass($class))->getInterfaceNames() !== [];
        };
    }

    /** @return Closure(string): bool */
    public static function declaresStrictTypes(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            $source = self::sourceOf($universe, $class);

            return $source !== null && preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $source) === 1;
        };
    }

    /**
     * ✓ The incumbent's `not->toUseStrictTypes` pattern, copied not
     * reconciled: it anchors at `<?php` with no room for a comment, so a
     * file whose declare follows a header fails BOTH forms there.
     *
     * @return Closure(string): bool
     */
    public static function declaresNoStrictTypes(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            $source = self::sourceOf($universe, $class);

            return $source !== null && preg_match('/^<\?php\s+declare\(.*?strict_types\s?=\s?1.*?\);/', $source) !== 1;
        };
    }

    /**
     * ✓ Pinned to the incumbent, which scans the source text for `' == '`
     * and `' != '`, spaced. Copied deliberately rather than improved on:
     * a token-aware reading would be cleverer and would answer
     * differently on the files someone is likely to test.
     *
     * @return Closure(string): bool
     */
    public static function usesStrictEquality(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            $source = self::sourceOf($universe, $class);

            return $source !== null && !str_contains($source, ' == ') && !str_contains($source, ' != ');
        };
    }

    /**
     * ✓ The incumbent's `not->toUseStrictEquality` scans for `' === '`
     * and `' !== '` — the other two operators, not a negation of the scan
     * above. A file comparing nothing passes both; one mixing them fails both.
     *
     * @return Closure(string): bool
     */
    public static function usesNoStrictEquality(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            $source = self::sourceOf($universe, $class);

            return $source !== null && !str_contains($source, ' === ') && !str_contains($source, ' !== ');
        };
    }

    /**
     * The class a file declares is the class its path implies, judged
     * against composer's PSR-4 map — the project's own autoload
     * configuration answers, not a convention assumed here.
     *
     * @return Closure(string): bool
     */
    public static function casedCorrectly(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            $file = $universe->fileOf($class);
            $real = $file === null ? false : realpath($file);

            if ($real === false) {
                return false;
            }

            foreach (self::psr4Prefixes() as $namespace => $directories) {
                foreach ($directories as $directory) {
                    $root = realpath($directory);

                    if ($root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                        continue;
                    }

                    $relative = explode('.', substr($real, strlen($root) + 1))[0];

                    return rtrim($namespace, '\\') . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative) === $class;
                }
            }

            return false;
        };
    }

    /**
     * Every method the file itself declares carries a docblock.
     *
     * ✓ The incumbent's qualifications are kept because each changes the
     * answer: an inherited method is not this file's to document, an
     * enum's generated `from`/`tryFrom`/`cases` have no source to
     * annotate, and presence is what is checked, never content.
     *
     * @return Closure(string): bool
     */
    public static function documentsMethods(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            /** @var class-string $class */
            return self::documented($universe, $class, (new ReflectionClass($class))->getMethods(), self::GENERATED_METHODS, true);
        };
    }

    /**
     * ✓ The incumbent's `not->toHaveMethodsDocumented`: not one declared
     * method carries a docblock. A class with no methods satisfies both.
     *
     * @return Closure(string): bool
     */
    public static function documentsNoMethods(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            /** @var class-string $class */
            return self::documented($universe, $class, (new ReflectionClass($class))->getMethods(), self::GENERATED_METHODS, false);
        };
    }

    /**
     * The same for properties, minus promoted ones: a promoted property
     * is a constructor parameter, and the constructor is where it would
     * be documented.
     *
     * @return Closure(string): bool
     */
    public static function documentsProperties(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            /** @var class-string $class */
            return self::documented($universe, $class, self::ownProperties($class), self::GENERATED_PROPERTIES, true);
        };
    }

    /** ✓ The incumbent's `not->toHavePropertiesDocumented`. @return Closure(string): bool */
    public static function documentsNoProperties(ArchitectureUniverse $universe): Closure
    {
        return static function (string $class) use ($universe): bool {
            /** @var class-string $class */
            return self::documented($universe, $class, self::ownProperties($class), self::GENERATED_PROPERTIES, false);
        };
    }

    /**
     * The references a class makes that LEAVE the target set and land in
     * the project's own source — the one definition of "uses" both
     * spellings read, in the outbound direction.
     *
     * Two things do not count. References outside the configured source
     * — PHP built-ins, vendor packages — because this is a question
     * about your own layering, and counting `Closure` as a dependency
     * would force every rule to carry a whitelist of the standard
     * library first. And references to another class of the SAME
     * target, because they do not cross the boundary the rule is about:
     * ✓ measured against pest 5.1.1, `expect('App\Models')->toUseNothing()`
     * passes while one model uses a sibling model, and fails the moment
     * one reaches out of `App\Models` — with a control proving the
     * matcher can still fail.
     *
     * @param list<string> $targets every class the rule targets
     *
     * @return list<string>
     */
    public static function crossingsOf(ArchitectureUniverse $universe, array $targets, string $class): array
    {
        $known    = $universe->classes();
        $inside   = array_flip($targets);
        $crossing = [];

        foreach ($universe->referencesOf($class) as $reference) {
            if ($reference !== $class && !isset($inside[$reference]) && isset($known[$reference])) {
                $crossing[] = $reference;
            }
        }

        return $crossing;
    }

    /**
     * The inbound direction of the same boundary rule: every pair where
     * a class OUTSIDE the target set references one inside it.
     *
     * One walk of the universe for the whole target set, not one walk
     * per targeted class — the reverse direction has to read every
     * file, so asking it per class turns a rule over 200 classes into
     * 200 full traversals.
     *
     * @param list<string> $targets every class the rule targets
     *
     * @return list<array{0: string, 1: string}> the user, and the targeted class it reached
     */
    public static function inboundPairs(ArchitectureUniverse $universe, array $targets): array
    {
        $inside = array_flip($targets);
        $pairs  = [];

        foreach (array_keys($universe->classes()) as $candidate) {
            if (isset($inside[$candidate])) {
                continue;
            }

            foreach ($universe->referencesOf($candidate) as $reference) {
                if (isset($inside[$reference])) {
                    $pairs[] = [$candidate, $reference];
                }
            }
        }

        return $pairs;
    }

    /**
     * Nothing the class references crosses out of the target set into
     * the project's own source.
     *
     * @param list<string> $targets
     *
     * @return Closure(string): bool
     */
    public static function referencesNothingOwn(ArchitectureUniverse $universe, array $targets): Closure
    {
        return static fn(string $class): bool => self::crossingsOf($universe, $targets, $class) === [];
    }

    /**
     * Nothing outside the target set references the class.
     *
     * @param list<string> $targets
     *
     * @return Closure(string): bool
     */
    public static function usedByNothing(ArchitectureUniverse $universe, array $targets): Closure
    {
        $inside = array_flip($targets);
        $used   = [];

        // NOT inboundPairs(): this walk counts a SIBLING user too, and
        // that asymmetry is the incumbent's, measured rather than
        // reasoned about. ✓ pest 5.1.1, 2026-09-06:
        // `expect('ArchFixture\Deps\Pure')->toBeUsedInNothing()` fails
        // with "Expecting 'Deps\Pure\Beta' not to be used on
        // 'Deps\Pure\Alpha'" — both classes INSIDE the target — while
        // `toUseNothing()` passes over the same two files. Outbound
        // ignores the boundary's inside; inbound does not.
        //
        // ⚠ This corrects 2026-09-02, which recorded the crossing rule
        // as measured for BOTH directions and made them share one
        // implementation. The control run that day had no sibling
        // reference in the inbound direction, so it proved the outbound
        // half twice. A control only earns the name when it could have
        // come out wrong.
        //
        // toOnlyBeUsedIn() keeps inboundPairs(): "only these may use it"
        // is a question about outside users, and it takes arguments, so
        // no grid has measured it. Changing it here would be reasoning,
        // not measuring.
        foreach (array_keys($universe->classes()) as $candidate) {
            foreach ($universe->referencesOf($candidate) as $reference) {
                if ($candidate !== $reference && isset($inside[$reference])) {
                    $used[$reference] = true;
                }
            }
        }

        return static fn(string $class): bool => !isset($used[$class]);
    }

    /**
     * Every own member is in the state $documented names.
     *
     * A parameter, not a fixed `true`: the incumbent's negated forms ask
     * that every member be UNdocumented, which differs from "not every
     * member is documented" whenever there are no members.
     *
     * @param list<ReflectionMethod|ReflectionProperty> $members
     * @param list<non-empty-string>                    $generated names an enum declares without source
     */
    private static function documented(ArchitectureUniverse $universe, string $class, array $members, array $generated, bool $documented): bool
    {
        $file = $universe->fileOf($class);
        $real = $file === null ? false : realpath($file);
        $enum = enum_exists($class);

        foreach ($members as $member) {
            if ($enum && in_array($member->getName(), $generated, true)) {
                continue;
            }

            $declaredIn = $member->getDeclaringClass()->getFileName();

            if ($declaredIn === false || realpath($declaredIn) !== $real) {
                continue;
            }

            if (($member->getDocComment() !== false) !== $documented) {
                return false;
            }
        }

        return true;
    }

    /**
     * Promoted properties excluded: they are constructor parameters.
     *
     * @param class-string $class
     *
     * @return list<ReflectionProperty>
     */
    private static function ownProperties(string $class): array
    {
        return array_values(array_filter(
            (new ReflectionClass($class))->getProperties(),
            static fn(ReflectionProperty $property): bool => !$property->isPromoted(),
        ));
    }

    private static function sourceOf(ArchitectureUniverse $universe, string $class): ?string
    {
        $file   = $universe->fileOf($class);
        $source = $file === null ? false : file_get_contents($file);

        return $source === false ? null : $source;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function psr4Prefixes(): array
    {
        $prefixes = [];

        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                foreach ($function[0]->getPrefixesPsr4() as $namespace => $directories) {
                    $prefixes[$namespace] = $directories;
                }
            }
        }

        return $prefixes;
    }
}
