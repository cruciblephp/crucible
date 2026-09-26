<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

use function array_pop;
use function count;
use function sprintf;

/**
 * `table($subject, $rows)` rows against the subject's parameters (D-129):
 * every row is the arguments, then the expected value, so all but the
 * last value of a row are passed to the callable. A row it cannot take
 * — too few arguments, a value its parameter definitely cannot accept —
 * fails at run time, and is reported here first (DataRows).
 *
 * Only a subject the analyser resolves to one signature is checked; an
 * overloaded or unknown callable is left alone.
 *
 * @implements Rule<FuncCall>
 */
final readonly class TableRowRule implements Rule
{
    #[Override]
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || $scope->resolveName($node->name) !== 'table') {
            return [];
        }

        $args = $node->getArgs();

        if (count($args) !== 2 || $args[0]->unpack || !$args[1]->value instanceof Array_) {
            return [];
        }

        $subject   = $scope->getType($args[0]->value);
        $acceptors = $subject->isCallable()->yes() ? $subject->getCallableParametersAcceptors($scope) : [];

        if (count($acceptors) !== 1) {
            return [];
        }

        $parameters = [];

        foreach ($acceptors[0]->getParameters() as $parameter) {
            $parameters[] = [
                'name'     => $parameter->getName(),
                'type'     => $parameter->getType(),
                'optional' => $parameter->isOptional(),
                'variadic' => $parameter->isVariadic(),
            ];
        }

        $errors = [];

        foreach ($args[1]->value->items as $index => $row) {
            if ($row->unpack || !$row->value instanceof Array_) {
                continue;
            }

            $values = [];

            foreach ($row->value->items as $position => $item) {
                if ($item->unpack || $item->key !== null) {
                    continue 2;
                }

                $values[$position] = [$scope->getType($item->value), $item->value->getStartLine()];
            }

            // The last value is the expectation, not an argument.
            array_pop($values);

            $name = match (true) {
                $row->key instanceof String_ => sprintf('row "%s"', $row->key->value),
                $row->key instanceof Int_    => sprintf('row #%d', $row->key->value),
                default                      => sprintf('row #%d', $index),
            };

            foreach (DataRows::check($parameters, $values, 'The table() subject', $name, $row->getStartLine(), $scope, 'crucible.table') as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }
}
