<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use Override;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use PHPStan\Type\ObjectType;

use function array_reverse;
use function in_array;
use function method_exists;

/**
 * The subject narrows, not only the chain (D-129): after the statement
 * `expect($x)->toBeString();` the analyser knows `$x` is a string — the
 * way it knows after `assertIsString($x)`. pest-plugin-phpstan narrows
 * the chain's value and leaves the variable as it was (probe 30 records
 * both), so a Pest suite repeats the check in an `if` or asserts twice.
 *
 * Sound because an expectation that fails throws: nothing after the
 * statement runs unless every matcher in it passed. The negated form
 * narrows too — `expect($x)->not->toBeNull()` removes null — which the
 * incumbent does not do even for the chain.
 *
 * The chain is read back from the statement's last call to `expect(...)`,
 * step by step as ExpectationSteps defines them. A step after which the
 * matchers are about something else — `and()`, `json()`, a spread `each`
 * — ends the reading, and what the matchers before it proved stands. A
 * step this cannot read — a higher-order member, a method forwarded to
 * the value — leaves the subject as it was: narrowing nothing is never
 * wrong.
 */
final class ExpectationSubjectTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    private TypeSpecifier $typeSpecifier;

    public function __construct(private readonly ExpectationNarrowing $narrowing) {}

    #[Override]
    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }

    #[Override]
    public function getClass(): string
    {
        return Expectation::class;
    }

    #[Override]
    public function isMethodSupported(MethodReflection $methodReflection, MethodCall $node, TypeSpecifierContext $context): bool
    {
        // Statements only: an expectation used as a value narrows nothing.
        if (!$context->null()) {
            return false;
        }

        $chain = new ObjectType(Expectation::class);

        foreach ($methodReflection->getVariants() as $variant) {
            if (!$chain->isSuperTypeOf($variant->getReturnType())->yes()) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function specifyTypes(MethodReflection $methodReflection, MethodCall $node, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
    {
        $steps   = [];
        $current = $node;

        while (!$current instanceof FuncCall) {
            if (!$current instanceof MethodCall && !$current instanceof PropertyFetch || !$current->name instanceof Identifier) {
                return new SpecifiedTypes();
            }

            $steps[] = $current;
            $current = $current->var;
        }

        $args = $current->getArgs();

        if (!$current->name instanceof Name || $current->name->toLowerString() !== 'expect' || !isset($args[0]) || $args[0]->unpack) {
            return new SpecifiedTypes();
        }

        $subject  = $args[0]->value;
        $original = $scope->getType($subject);
        $type     = $original;
        $negated  = false;

        foreach (array_reverse($steps) as $step) {
            $name = $step->name instanceof Identifier ? $step->name->toString() : '';

            // From here on the matchers are about the items, or about
            // another value: what the steps before proved still holds.
            if (ExpectationSteps::spreads($step) || ($step instanceof MethodCall && in_array($name, ExpectationSteps::NEW_SUBJECT, true))) {
                break;
            }

            if (ExpectationSteps::negates($step)) {
                $negated = true;

                continue;
            }

            // Any other property, or a method the class does not declare
            // (a higher-order member, one forwarded to the value), changes
            // the subject in a way this does not read: narrow nothing.
            if ($step instanceof PropertyFetch || !method_exists(Expectation::class, $name)) {
                return new SpecifiedTypes();
            }

            if (in_array($name, ExpectationSteps::NEUTRAL, true)) {
                continue;
            }

            $type = $this->narrowing->afterMatcher($type, $name, $step, $subject, $scope, $negated);

            // ->not negates exactly the next matcher.
            $negated = false;
        }

        if ($type->equals($original)) {
            return new SpecifiedTypes();
        }

        // Overwrite, not intersect: $type was computed from $original
        // itself, by the same rules as the chain's value, so it is already
        // the answer. An intersection would turn toBeList() on a
        // string-keyed array into never, where the chain says list.
        return $this->typeSpecifier->create($subject, $type, TypeSpecifierContext::createTruthy(), $scope)->setAlwaysOverwriteTypes();
    }

}
