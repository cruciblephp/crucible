<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;

use function in_array;

/**
 * What each step of an `expect()` chain does to what the matchers after
 * it are about (D-137): read in one place by the chain's value
 * (ExpectationChainReturnTypeExtension) and by the variable passed to
 * `expect()` (ExpectationSubjectTypeSpecifyingExtension), which each kept
 * their own lists and had come to disagree about `each()`.
 *
 *   - `and($other)`, `json()` start a chain on another subject;
 *   - `->each`, and `each()` without a callback, spread: every matcher
 *     after it, to the end of the statement, is about the items;
 *   - `each($callback)` runs the callback per item and keeps the chain on
 *     the value, as do `when()`, `unless()`, `sequence()`;
 *   - `->not` / `not()` negate exactly the next matcher.
 */
final class ExpectationSteps
{
    /** Steps that start a chain on another subject. */
    public const array NEW_SUBJECT = ['and', 'json'];

    /** Declared steps that keep the subject and assert nothing about its type. */
    public const array NEUTRAL = ['when', 'unless', 'sequence', 'each'];

    /** Whether $step spreads the chain over the value's items. */
    public static function spreads(Expr $step): bool
    {
        return self::named($step, 'each')
            && ($step instanceof PropertyFetch || ($step instanceof MethodCall && $step->getArgs() === []));
    }

    /** Whether $step negates the next matcher. */
    public static function negates(Expr $step): bool
    {
        return self::named($step, 'not') && ($step instanceof PropertyFetch || $step instanceof MethodCall);
    }

    /**
     * Whether a spread sits anywhere between $receiver and the start of its
     * chain — the value itself, or a step that began a new subject.
     */
    public static function spreadBefore(Expr $receiver): bool
    {
        for ($step = $receiver; $step instanceof MethodCall || $step instanceof PropertyFetch; $step = $step->var) {
            if (self::spreads($step)) {
                return true;
            }

            if ($step instanceof MethodCall && $step->name instanceof Identifier && in_array($step->name->toString(), self::NEW_SUBJECT, true)) {
                return false;
            }
        }

        return false;
    }

    private static function named(Expr $step, string $name): bool
    {
        return ($step instanceof PropertyFetch || $step instanceof MethodCall)
            && $step->name instanceof Identifier
            && $step->name->toString() === $name;
    }
}
