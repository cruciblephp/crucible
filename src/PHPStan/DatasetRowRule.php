<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Dialect\Pest\TestCall;
use Override;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

use function count;
use function in_array;
use function sprintf;

/**
 * A dataset row the test cannot take is wrong at the keyboard, not at
 * the first run (D-129) — the principle PropertyParameterRule applies to
 * generators (D-067), applied to the rows of an inline Pest dataset:
 *
 *     it('adds', function (int $a, int $b) { ... })->with([
 *         'strings' => ['1', '2'],        // reported: $a declares int
 *     ]);
 *
 * Only what would fail at run time is reported: a row with fewer values
 * than the closure requires (an ArgumentCountError), and a value its
 * declared parameter definitely cannot accept (a TypeError). A value the
 * parameter might accept is not reported, and neither is an extra value
 * (PHP passes it and the closure ignores it). Out of reach for now, and
 * left alone: named datasets, a closure producing the rows, several
 * ->with() calls on one test, and closure values (Pest calls them
 * lazily, so their result is what the parameter receives).
 *
 * Read from the whole statement: a dataset is known only once every
 * ->with() on the test is seen, and one call cannot see the call it sits
 * inside.
 *
 * @implements Rule<Expression>
 */
final readonly class DatasetRowRule implements Rule
{
    #[Override]
    public function getNodeType(): string
    {
        return Expression::class;
    }

    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $withs = [];
        $chain = $node->expr;

        while ($chain instanceof MethodCall) {
            if ($chain->name instanceof Identifier && $chain->name->toString() === 'with') {
                $withs[] = $chain;
            }

            $chain = $chain->var;
        }

        // One dataset, or none to read: several ->with() calls form a
        // Cartesian product whose rows split the parameters between them.
        if (count($withs) !== 1) {
            return [];
        }

        $with = $withs[0];

        if (!(new ObjectType(TestCall::class))->isSuperTypeOf($scope->getType($with->var))->yes()) {
            return [];
        }

        $args = $with->getArgs();

        if (count($args) !== 1 || !$args[0]->value instanceof Array_) {
            return [];
        }

        $closure = $this->testClosure($chain);

        if ($closure === null) {
            return [];
        }

        $errors = [];

        foreach ($args[0]->value->items as $index => $row) {
            if ($row->unpack) {
                continue;
            }

            $values = $this->rowValues($row->value);

            if ($values === null) {
                continue;
            }

            $typed = [];

            foreach ($values as $position => $value) {
                // Pest calls a closure value and passes its result.
                $typed[$position] = [
                    $value instanceof Closure || $value instanceof ArrowFunction ? null : $scope->getType($value),
                    $value->getStartLine(),
                ];
            }

            foreach (DataRows::check(DataRows::fromNodes($closure->getParams(), $scope), $typed, 'The test closure', $this->rowName($row, $index), $row->getStartLine(), $scope, 'crucible.dataset') as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** The test closure a chain was opened with: test()/it()'s second argument. */
    private function testClosure(Expr $chain): Closure|ArrowFunction|null
    {
        if (!$chain instanceof FuncCall || !$chain->name instanceof Name || !in_array($chain->name->toLowerString(), ['test', 'it'], true)) {
            return null;
        }

        $args    = $chain->getArgs();
        $closure = isset($args[1]) ? $args[1]->value : null;

        return $closure instanceof Closure || $closure instanceof ArrowFunction ? $closure : null;
    }

    /**
     * A row's values in order: an array literal's items, or one value
     * that is not an array literal. Null when the row cannot be read
     * item by item (string keys, spreads).
     *
     * @return ?list<Expr>
     */
    private function rowValues(Expr $row): ?array
    {
        if (!$row instanceof Array_) {
            return [$row];
        }

        $values = [];

        foreach ($row->items as $item) {
            if ($item->unpack || $item->key instanceof String_) {
                return null;
            }

            $values[] = $item->value;
        }

        return $values;
    }

    /**
     * `dataset "name"` for a keyed row, `row #N` otherwise.
     *
     * @return non-empty-string
     */
    private function rowName(ArrayItem $row, int $index): string
    {
        if ($row->key instanceof String_) {
            return sprintf('dataset "%s"', $row->key->value);
        }

        if ($row->key instanceof Int_) {
            return sprintf('row #%d', $row->key->value);
        }

        return sprintf('row #%d', $index);
    }
}
