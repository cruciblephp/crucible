<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Framework;

use LucianoPereira\Crucible\Attributes\After;
use LucianoPereira\Crucible\Attributes\Before;
use LucianoPereira\Crucible\Attributes\PostCondition;
use LucianoPereira\Crucible\Attributes\PreCondition;
use LucianoPereira\Crucible\Metadata\MetadataParser;
use ReflectionClass;
use ReflectionMethod;

use function strtolower;
use function usort;

/**
 * Builds a HookPlan by scanning a class's methods for
 * #[Before]/#[PreCondition]/#[PostCondition]/#[After] — including
 * real PHPUnit's own equivalents, via MetadataParser (see
 * RealPhpUnitHookAttributes). Shared by every dialect frontend that
 * wants hook support: the PHPUnit dialect's own TestBuilder used to
 * carry a private copy of exactly this logic; extracted here so the
 * Pest dialect (PestBuilder) can call the same code instead of either
 * duplicating it or going without — a uses() class (or a trait mixed
 * into one, like Spatie\Snapshots\MatchesSnapshots's real
 * #[PostCondition]-attributed method) gets the same hook support
 * regardless of which dialect built the test.
 */
final readonly class HookPlanner
{
    /**
     * @param ReflectionClass<object> $class
     */
    public static function forClass(ReflectionClass $class): HookPlan
    {
        // A class's hooks are fixed once it is declared; Pest asks per test,
        // and every test of a file shares one class (D-133). A readonly
        // class holds no static property, so the memo is the method's.
        /** @var array<class-string, HookPlan> $plans */
        static $plans = [];

        return $plans[$class->getName()] ??= self::plan($class);
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private static function plan(ReflectionClass $class): HookPlan
    {
        $parser = new MetadataParser();
        $before = $preConditions = $postConditions = $after = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            $methodName = $method->getName();

            /** @var non-empty-string $methodName */
            $metadata = $parser->forMethod($class->getName(), $methodName);

            // A template method is already in its phase; the incumbent
            // ignores the attribute on it rather than run it twice.
            foreach ($metadata->ofType(Before::class) as $attribute) {
                if (!self::named($methodName, HookPlan::SET_UP)) {
                    $before[] = [$attribute->priority, $methodName];
                }
            }

            foreach ($metadata->ofType(PreCondition::class) as $attribute) {
                if (!self::named($methodName, HookPlan::PRE_CONDITIONS)) {
                    $preConditions[] = [$attribute->priority, $methodName];
                }
            }

            foreach ($metadata->ofType(PostCondition::class) as $attribute) {
                if (!self::named($methodName, HookPlan::POST_CONDITIONS)) {
                    $postConditions[] = [$attribute->priority, $methodName];
                }
            }

            foreach ($metadata->ofType(After::class) as $attribute) {
                if (!self::named($methodName, HookPlan::TEAR_DOWN)) {
                    $after[] = [$attribute->priority, $methodName];
                }
            }
        }

        return new HookPlan(
            self::merge($before, HookPlan::SET_UP, prepend: true),
            self::merge($preConditions, HookPlan::PRE_CONDITIONS, prepend: true),
            self::merge($postConditions, HookPlan::POST_CONDITIONS, prepend: false),
            self::merge($after, HookPlan::TEAR_DOWN, prepend: false),
        );
    }

    /**
     * One phase's run order, merged the incumbent's way: the template
     * method starts the list at priority 0, each discovered hook is put
     * in front of it (setUp, assertPreConditions) or behind it
     * (assertPostConditions, tearDown), and a stable sort then puts the
     * highest priority first. At equal priority that places a hook
     * before setUp() and after tearDown(), and in the before phases the
     * later-discovered of two tied hooks runs first.
     *
     * @param list<array{int, non-empty-string}> $hooks
     * @param non-empty-string                   $template
     *
     * @return list<non-empty-string>
     */
    private static function merge(array $hooks, string $template, bool $prepend): array
    {
        $ordered = [[0, $template]];

        foreach ($hooks as $hook) {
            $ordered = $prepend ? [$hook, ...$ordered] : [...$ordered, $hook];
        }

        usort($ordered, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        $names = [];

        foreach ($ordered as [, $name]) {
            $names[] = $name;
        }

        return $names;
    }

    private static function named(string $methodName, string $template): bool
    {
        return strtolower($methodName) === strtolower($template);
    }
}
