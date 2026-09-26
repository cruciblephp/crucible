<?php

declare(strict_types=1);

use function PHPStan\Testing\assertType;

/** @return list<int> */
function ids(): array
{
    return [1, 2];
}

assertType('list<int>', ids());
assertType('list<string>', ids());
$count = count(ids()) + 'x'; // crucible-type-error binaryOp.invalid
$fine = 1 + 2; // crucible-type-error binaryOp.invalid
echo undefinedFunctionHere();
