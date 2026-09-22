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
use PhpParser\Node\Expr\Eval_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_keys;
use function implode;
use function sprintf;
use function str_ends_with;

/**
 * `eval()` may only appear where it has been argued for.
 *
 * Generated code is the one region of this codebase no instrument
 * reads: PHPStan cannot see inside a string literal, phpcpd finds no
 * clone there (D-108), and the mutation runner drops eval()'d
 * pseudo-paths outright (`MutationCommand.php:334`). Every defect that
 * lives there is therefore invisible until it reaches a user — the
 * shape corpus found two on its first run, one of them an uncatchable
 * fatal.
 *
 * So the count of such places is held still by a gate rather than by a
 * grep. It was a grep until this rule existed, and the grep was wrong:
 * it reported six sites, because `eval(` also matches four docblocks
 * and one comment.
 *
 * Adding a site means adding a line here with the reason, which is the
 * point — a sentence in a review rather than a silent sixth blind spot.
 * Only THIS repository's analysis registers the rule; a project using
 * Crucible's PHPStan extension is not told where it may call eval().
 *
 * @implements Rule<Eval_>
 */
final readonly class ApprovedEvalRule implements Rule
{
    /**
     * Path suffix => why that site generates code at runtime.
     *
     * @var array<string, string>
     */
    private const array APPROVED = [
        'src/Generated/GeneratedCode.php' => 'the one place Crucible compiles code it wrote itself',
    ];

    #[Override]
    public function getNodeType(): string
    {
        return Eval_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();

        foreach (array_keys(self::APPROVED) as $approved) {
            if (str_ends_with($file, $approved)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'eval() is not approved here. Code that exists only as a string is invisible to every gate — '
                    . 'no type check, no clone check, no mutation. If this site really must generate code, add '
                    . 'it to ApprovedEvalRule with the reason; the approved ones are: %s.',
                implode(', ', array_keys(self::APPROVED)),
            ))
                ->identifier('crucible.evalNotApproved')
                ->build(),
        ];
    }
}
