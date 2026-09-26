<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

use function count;
use function is_string;
use function sprintf;

/**
 * One data row against the parameters it feeds (D-129), for every form a
 * row is written in: an inline Pest dataset, `#[TestWith]`,
 * `#[TestWithJson]`, `#[Check(args:)]`. Only what fails at run time is
 * reported — too few values for the required parameters (an
 * ArgumentCountError), a value its declared parameter definitely cannot
 * accept (a TypeError). A value the parameter might accept, and a value
 * beyond the last parameter, are not.
 */
final class DataRows
{
    /**
     * Parameter descriptions from declared parameters in the source.
     *
     * @param array<Param> $parameters
     *
     * @return list<array{name: ?string, type: ?Type, optional: bool, variadic: bool}>
     */
    public static function fromNodes(array $parameters, Scope $scope): array
    {
        $described = [];

        foreach ($parameters as $parameter) {
            $described[] = [
                'name'     => $parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : null,
                'type'     => $parameter->type === null ? null : $scope->getFunctionType($parameter->type, false, false),
                'optional' => $parameter->default !== null,
                'variadic' => $parameter->variadic,
            ];
        }

        return $described;
    }

    /**
     * @param list<array{name: ?string, type: ?Type, optional: bool, variadic: bool}> $parameters
     * @param array<int|string, array{?Type, int}>      $values     position or parameter name => [type (null: not checkable), line]
     * @param non-empty-string                          $subject    'The test closure', 'Temperature::toFahrenheit()'
     * @param non-empty-string                          $row        'dataset "name"', 'row #2', '#[TestWith] #1'
     * @param non-empty-string                          $prefix     the identifier family, e.g. 'crucible.dataset'
     *
     * @return list<IdentifierRuleError>
     */
    public static function check(array $parameters, array $values, string $subject, string $row, int $line, Scope $scope, string $prefix): array
    {
        $errors   = [];
        $required = 0;
        $missing  = 0;

        foreach ($parameters as $position => $parameter) {
            $name = $parameter['name'];

            if ($parameter['variadic']) {
                continue;
            }

            $value = $values[$position] ?? ($name !== null ? $values[$name] ?? null : null);

            if (!$parameter['optional']) {
                $required++;
            }

            if ($value === null) {
                if (!$parameter['optional']) {
                    $missing++;
                }

                continue;
            }

            [$given, $valueLine] = $value;
            $declared            = $parameter['type'];

            if ($given === null || $declared === null) {
                continue;
            }

            if (!$declared->accepts($given, $scope->isDeclareStrictTypes())->no()) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s parameter %s declares %s, but %s supplies %s.',
                $subject,
                $name !== null ? '$' . $name : sprintf('#%d', $position + 1),
                $declared->describe(VerbosityLevel::typeOnly()),
                $row,
                $given->describe(VerbosityLevel::precise()),
            ))->identifier($prefix . 'Parameter')->line($valueLine)->build();
        }

        if ($missing > 0) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s requires %d argument%s, but %s supplies %d.',
                $subject,
                $required,
                $required === 1 ? '' : 's',
                $row,
                count($values),
            ))->identifier($prefix . 'Arity')->line($line)->build();
        }

        return $errors;
    }
}
