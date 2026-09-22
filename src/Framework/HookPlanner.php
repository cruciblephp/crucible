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
        $parser = new MetadataParser();
        $before = $preConditions = $postConditions = $after = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            $methodName = $method->getName();

            /** @var non-empty-string $methodName */
            $metadata = $parser->forMethod($class->getName(), $methodName);

            foreach ($metadata->ofType(Before::class) as $attribute) {
                $before[] = [$attribute->priority, $methodName];
            }

            foreach ($metadata->ofType(PreCondition::class) as $attribute) {
                $preConditions[] = [$attribute->priority, $methodName];
            }

            foreach ($metadata->ofType(PostCondition::class) as $attribute) {
                $postConditions[] = [$attribute->priority, $methodName];
            }

            foreach ($metadata->ofType(After::class) as $attribute) {
                $after[] = [$attribute->priority, $methodName];
            }
        }

        return new HookPlan(
            self::byPriority($before, descending: true),
            self::byPriority($preConditions, descending: true),
            self::byPriority($postConditions, descending: false),
            self::byPriority($after, descending: false),
        );
    }

    /**
     * @param list<array{int, non-empty-string}> $hooks
     *
     * @return list<non-empty-string>
     */
    private static function byPriority(array $hooks, bool $descending): array
    {
        usort(
            $hooks,
            static fn(array $a, array $b): int => $descending ? $b[0] <=> $a[0] : $a[0] <=> $b[0],
        );

        $names = [];

        foreach ($hooks as [, $name]) {
            $names[] = $name;
        }

        return $names;
    }
}
