<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Generated\GeneratedCode;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_keys;
use function implode;
use function in_array;
use function sprintf;
use function str_ends_with;

/**
 * `GeneratedCode::evaluate()` may only be called where it has been
 * argued for.
 *
 * ApprovedEvalRule holds the count of eval() sites at one. This holds
 * the count of GENERATORS, which is the other half and was missing: the
 * seam is public, so a sixth generator could use it correctly, compile
 * real code, and never appear in `composer analyse:generated` — the tier
 * reads a hand-written corpus, and nothing made writing that corpus
 * mandatory. Coverage by the one gate that can see generated code was
 * therefore voluntary, which is how five sites with four covered stood
 * for months with every gate green.
 *
 * Registering is the whole mechanism. A new generator fails this rule
 * until it adds a line here with its reason, and adding that line is
 * where someone asks whether the corpus grew too.
 *
 * The allowlist is the five generators plus the seam's own test. After
 * the two-seam refactor it collapses to `GeneratedTypes` and
 * `GeneratedExpression` — an allowlist of two, which is the point of the
 * refactor rather than a reason to wait for it.
 *
 * @implements Rule<StaticCall>
 */
final readonly class ApprovedGeneratorRule implements Rule
{
    /**
     * Path suffix => why that file may compile code at runtime.
     *
     * @var array<string, string>
     */
    private const array APPROVED = [
        'src/Double/Generator.php'                             => 'the double generator: one class per specification identity',
        'src/Double/Mockery/MockeryContainer.php'              => 'the mockery surface, and the empty class nothing else declares',
        'src/Dialect/Pest/TraitComposer.php'                   => 'the composed test case a Pest file needs',
        'src/Bridge/PestSnapshots/SnapshotIdentityWrapper.php' => 'the subclass that gives a snapshot its identity',
        'src/Dialect/Inline/InlineBuilder.php'                 => 'the doctest expression, the one payload written by the author rather than by Crucible',
        'tests/unit/Generated/GeneratedCodeTest.php'           => 'the seam under test, which has to reach it to prove the diagnosis',
    ];

    /**
     * The allowlist, for the one other place that has to agree with it:
     * `phpstan/generated.php` checks that every generator approved here
     * is actually driven by a corpus. Two hand-maintained lists that
     * cannot disagree are worth more than either alone.
     *
     * @return array<string, string>
     */
    public static function approved(): array
    {
        return self::APPROVED;
    }

    #[Override]
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->reachesTheSeam($node, $scope)) {
            return [];
        }

        $file = $scope->getFile();

        foreach (array_keys(self::APPROVED) as $approved) {
            if (str_ends_with($file, $approved)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'GeneratedCode::evaluate() is not approved here. A generator the corpus does not drive is never '
                    . 'analysed, and every gate stays green while it is wrong. If this really is a new generator, '
                    . 'add it to ApprovedGeneratorRule with the reason and give it a corpus entry in '
                    . 'phpstan/generated.php; the approved ones are: %s.',
                implode(', ', array_keys(self::APPROVED)),
            ))
                ->identifier('crucible.generatorNotApproved')
                ->build(),
        ];
    }

    /**
     * A dynamic class expression is resolved through the scope rather
     * than ignored: `$seam::evaluate()` compiles the same code as the
     * spelled-out call, so a rule that only read `Name` nodes would
     * hold a count anyone could step around by assigning it first.
     */
    private function reachesTheSeam(StaticCall $node, Scope $scope): bool
    {
        if (!$node->name instanceof Identifier || $node->name->toLowerString() !== 'evaluate') {
            return false;
        }

        if ($node->class instanceof Name) {
            return $scope->resolveName($node->class) === GeneratedCode::class;
        }

        $type = $scope->getType($node->class);

        if (in_array(GeneratedCode::class, $type->getObjectClassNames(), true)) {
            return true;
        }

        foreach ($type->getConstantStrings() as $string) {
            if ($string->getValue() === GeneratedCode::class) {
                return true;
            }
        }

        return false;
    }
}
