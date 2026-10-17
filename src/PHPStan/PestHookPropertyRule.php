<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\PHPStan;

use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Records each `$this->name = value` inside a Pest `beforeEach` hook, with
 * the value's type, for the tests of the same file (D-144). Reports nothing.
 *
 * @implements Rule<Assign>
 */
final readonly class PestHookPropertyRule implements Rule
{
    public function __construct(
        private PestHookProperties $properties,
    ) {}

    #[Override]
    public function getNodeType(): string
    {
        return Assign::class;
    }

    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $target = $node->var;

        if ($target instanceof PropertyFetch
            && $target->var instanceof Variable
            && $target->var->name === 'this'
            && $target->name instanceof Identifier
            && $this->properties->inHook($scope->getFile(), $node->getStartLine())
        ) {
            $this->properties->record($scope->getFile(), $target->name->toString(), $scope->getType($node->expr));
        }

        return [];
    }
}
