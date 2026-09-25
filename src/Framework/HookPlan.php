<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Framework;

use function in_array;

/**
 * The per-test hook methods, in the order they run. Each list holds the
 * attribute hooks (#[Before], #[PreCondition], #[PostCondition], #[After])
 * merged with the template method of its phase, at priority 0, the way
 * the incumbent merges them:
 *
 *   before:         #[Before] at priority >= 0, setUp, #[Before] below 0
 *   preConditions:  #[PreCondition] at >= 0, assertPreConditions, the rest
 *   postConditions: #[PostCondition] above 0, assertPostConditions, the rest
 *   after:          #[After] above 0, tearDown, the rest
 *
 * So by default an attribute hook runs before setUp() and after
 * tearDown(): a trait's #[Before] reset cannot wipe what setUp() wires.
 * Per test: before -> preConditions -> test -> postConditions -> after,
 * and the after list always runs once the test body was entered.
 */
final readonly class HookPlan
{
    public const string SET_UP = 'setUp';

    public const string PRE_CONDITIONS = 'assertPreConditions';

    public const string POST_CONDITIONS = 'assertPostConditions';

    public const string TEAR_DOWN = 'tearDown';

    /**
     * @param list<non-empty-string> $before
     * @param list<non-empty-string> $preConditions
     * @param list<non-empty-string> $postConditions
     * @param list<non-empty-string> $after
     */
    public function __construct(
        public array $before = [self::SET_UP],
        public array $preConditions = [self::PRE_CONDITIONS],
        public array $postConditions = [self::POST_CONDITIONS],
        public array $after = [self::TEAR_DOWN],
    ) {}

    /** Whether $hook is a phase's template method rather than an attribute hook. */
    public static function isTemplate(string $hook): bool
    {
        return in_array($hook, [self::SET_UP, self::PRE_CONDITIONS, self::POST_CONDITIONS, self::TEAR_DOWN], true);
    }
}
