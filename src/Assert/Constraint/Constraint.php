<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\ComparisonFailure;
use LucianoPereira\Crucible\Assert\Exporter;
use ReflectionMethod;

use function str_starts_with;

/**
 * The shared evaluation core. Every assert*() method — and, in the
 * growth tier, every expect() matcher — compiles to a Constraint, so
 * failures look and behave identically across dialects (D-008).
 */
abstract class Constraint
{
    /**
     * The match predicate. Not abstract, per the spec's contract: a
     * subclass either overrides this or replaces evaluate() wholesale
     * — forcing both would reject valid spec-written constraints.
     *
     * protected, matching real PHPUnit's own Constraint::matches()
     * exactly (verified against installed phpunit/phpunit source) —
     * a real-world Constraint subclass, written against real
     * PHPUnit's contract and declaring its own override protected
     * too, fatals ("access level ... must be public") if this base
     * declared it public instead: PHP forbids narrowing visibility on
     * override, and that base becomes the effective parent once
     * PhpUnitCompatibility aliases PHPUnit\Framework\Constraint\Constraint
     * onto this class. Found via a real, un-aliased PHPUnit constraint
     * subclass (phpcpd-next/phpcpd's DuplicationConstraint) under
     * ->phpunitCompatibility(true). Callers outside this hierarchy use
     * evaluate($other, '', returnResult: true) instead, which already
     * wraps this the same way real PHPUnit's own Assert::assertThat()
     * does.
     */
    protected function matches(mixed $other): bool
    {
        return false;
    }

    /**
     * The predicate as a sentence fragment, e.g. "is true",
     * "is identical to 3".
     */
    abstract public function toString(): string;

    /**
     * The spec's evaluation contract, overridable because it is the
     * documented extension point: frameworks subclass Constraint and
     * replace evaluate() wholesale (the Phase 9 Laravel gate found
     * exactly that). With $returnResult the verdict is returned
     * instead of thrown.
     *
     * @throws AssertionFailedError when the constraint does not match and $returnResult is false
     */
    public function evaluate(mixed $other, string $description = '', bool $returnResult = false): ?bool
    {
        if ($this->matches($other)) {
            return $returnResult ? true : null;
        }

        if ($returnResult) {
            return false;
        }

        $failure = $this->failureSentence($other) . $this->additionalFailureDescription($other);

        if ($description !== '') {
            $failure = $description . "\n" . $failure;
        }

        throw new AssertionFailedError($failure, $this->comparison($other));
    }

    protected function failureDescription(mixed $other): string
    {
        return 'Failed asserting that ' . Exporter::describe($other) . ' ' . $this->toString() . '.';
    }

    /**
     * The failure as a whole sentence. Crucible's constraints write the
     * sentence in failureDescription() themselves; a framework's
     * constraint, written to the incumbent's contract, returns only the
     * fragment after "Failed asserting that" (Laravel's HasInDatabase:
     * "a row in the table [users] matches the attributes ..."), and the
     * sentence is built around it, as the incumbent builds it.
     */
    final protected function failureSentence(mixed $other): string
    {
        $description = $this->failureDescription($other);

        return self::writesItsOwnSentence($this)
            ? $description
            : 'Failed asserting that ' . $description . '.';
    }

    /** Whether $constraint's failureDescription() is Crucible's, which returns a whole sentence. */
    final protected static function writesItsOwnSentence(self $constraint): bool
    {
        $declaredBy = (new ReflectionMethod($constraint, 'failureDescription'))->getDeclaringClass()->getName();

        return str_starts_with($declaredBy, 'LucianoPereira\\Crucible\\');
    }

    protected function additionalFailureDescription(mixed $other): string
    {
        $comparison = $this->comparison($other);

        return $comparison instanceof \LucianoPereira\Crucible\Assert\ComparisonFailure && $comparison->diff !== '' ? "\n" . $comparison->diff : '';
    }

    /**
     * Structured expected/actual detail, when this constraint is a
     * comparison. Feeds the event stream's Failure diff payload.
     */
    protected function comparison(mixed $other): ?ComparisonFailure
    {
        return null;
    }
}
