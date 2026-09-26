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
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\VerbosityLevel;

use function array_is_list;
use function in_array;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * The data a method's attributes feed it, checked against its signature
 * (D-129): `#[TestWith]` and `#[TestWithJson]` rows against the test
 * method's parameters, and `#[Check(args:, returns:)]` claims against the
 * method they sit on — the arguments against its parameters, the claimed
 * return against its declared return type. Attribute arguments are
 * constant expressions, so the analyser knows every value exactly; a row
 * that cannot be passed is a mistake visible before anything runs.
 *
 * What is reported is only what fails at run time (DataRows): a missing
 * required argument, a value the parameter definitely cannot accept, and
 * for `#[Check]` a `returns:` the declared return type definitely cannot
 * produce.
 *
 * @implements Rule<FunctionLike>
 */
final readonly class DataAttributeRule implements Rule
{
    private const array CHECK = [\LucianoPereira\Crucible\Attributes\Check::class];

    private const array TEST_WITH = [
        \LucianoPereira\Crucible\Attributes\TestWith::class,
        'PHPUnit\\Framework\\Attributes\\TestWith',
    ];

    private const array TEST_WITH_JSON = [
        \LucianoPereira\Crucible\Attributes\TestWithJson::class,
        'PHPUnit\\Framework\\Attributes\\TestWithJson',
    ];

    #[Override]
    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod && !$node instanceof Function_) {
            return [];
        }

        $subject = $node instanceof ClassMethod
            ? sprintf('%s::%s()', $scope->getClassReflection()?->getDisplayName() ?? 'class', $node->name->toString())
            : sprintf('%s()', $node->name->toString());

        $errors = [];
        $count  = ['check' => 0, 'with' => 0];

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $attribute->name->toString();

                if (in_array($name, self::CHECK, true)) {
                    $count['check']++;
                    $errors = [...$errors, ...$this->checkClaim($node, $attribute, $subject, sprintf('#[Check] #%d', $count['check']), $scope)];
                } elseif (in_array($name, self::TEST_WITH, true)) {
                    $count['with']++;
                    $errors = [...$errors, ...$this->testWith($node, $attribute, $subject, sprintf('#[TestWith] #%d', $count['with']), $scope)];
                } elseif (in_array($name, self::TEST_WITH_JSON, true)) {
                    $count['with']++;
                    $errors = [...$errors, ...$this->testWithJson($node, $attribute, $subject, sprintf('#[TestWithJson] #%d', $count['with']), $scope)];
                }
            }
        }

        return $errors;
    }

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $row
     *
     * @return list<IdentifierRuleError>
     */
    private function checkClaim(ClassMethod|Function_ $function, Attribute $attribute, string $subject, string $row, Scope $scope): array
    {
        $args    = $this->argument($attribute, 'args', 0);
        $returns = $this->argument($attribute, 'returns', 1);
        $throws  = $this->argument($attribute, 'throws', 2);
        $errors  = [];

        if (!$args instanceof \PhpParser\Node\Arg || $args->value instanceof Array_) {
            $values = [];
            $next   = 0;

            foreach ($args?->value instanceof Array_ ? $args->value->items : [] as $item) {
                if ($item->unpack) {
                    return [];
                }

                $key = match (true) {
                    $item->key instanceof String_ => $item->key->value,
                    $item->key instanceof Int_    => $item->key->value,
                    default                       => $next,
                };

                if (!$item->key instanceof String_) {
                    $next = (int) $key + 1;
                }

                $values[$key] = [$scope->getType($item->value), $item->value->getStartLine()];
            }

            $errors = DataRows::check(DataRows::fromNodes($function->getParams(), $scope), $values, $subject, $row, $attribute->getStartLine(), $scope, 'crucible.check');
        }

        // A claim that expects a throw makes no claim about the return.
        if (!$returns instanceof \PhpParser\Node\Arg || $throws instanceof \PhpParser\Node\Arg || !$function->returnType instanceof \PhpParser\Node) {
            return $errors;
        }

        $declared = $scope->getFunctionType($function->returnType, false, false);
        $claimed  = $scope->getType($returns->value);

        if ($declared->accepts($claimed, $scope->isDeclareStrictTypes())->no()) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s declares it returns %s, but %s claims it returns %s.',
                $subject,
                $declared->describe(VerbosityLevel::typeOnly()),
                $row,
                $claimed->describe(VerbosityLevel::precise()),
            ))->identifier('crucible.checkReturns')->line($attribute->getStartLine())->build();
        }

        return $errors;
    }

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $row
     *
     * @return list<IdentifierRuleError>
     */
    private function testWith(ClassMethod|Function_ $function, Attribute $attribute, string $subject, string $row, Scope $scope): array
    {
        $data = $this->argument($attribute, 'data', 0);

        if (!$data instanceof \PhpParser\Node\Arg || !$data->value instanceof Array_) {
            return [];
        }

        $values = [];

        foreach ($data->value->items as $position => $item) {
            if ($item->unpack || $item->key !== null) {
                return [];
            }

            $values[$position] = [$scope->getType($item->value), $item->value->getStartLine()];
        }

        return DataRows::check(DataRows::fromNodes($function->getParams(), $scope), $values, $subject, $row, $attribute->getStartLine(), $scope, 'crucible.testWith');
    }

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $row
     *
     * @return list<IdentifierRuleError>
     */
    private function testWithJson(ClassMethod|Function_ $function, Attribute $attribute, string $subject, string $row, Scope $scope): array
    {
        $json = $this->argument($attribute, 'json', 0);

        if (!$json instanceof \PhpParser\Node\Arg || !$json->value instanceof String_) {
            return [];
        }

        $decoded = json_decode($json->value->value, true);

        // Malformed JSON is the attribute's own error at run time; not this rule's.
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        $values = [];

        foreach ($decoded as $position => $value) {
            $values[$position] = [ConstantTypeHelper::getTypeFromValue($value), $attribute->getStartLine()];
        }

        return DataRows::check(DataRows::fromNodes($function->getParams(), $scope), $values, $subject, $row, $attribute->getStartLine(), $scope, 'crucible.testWith');
    }

    /** An attribute argument by its name, or by its position when unnamed. */
    private function argument(Attribute $attribute, string $name, int $position): ?Arg
    {
        foreach ($attribute->args as $index => $arg) {
            if ($arg->name?->toString() === $name || ($arg->name === null && $index === $position)) {
                return $arg;
            }
        }

        return null;
    }
}
