<?php

declare(strict_types=1);

use function PHPStan\Testing\assertNativeType;
use function PHPStan\Testing\assertSuperType;

/** @return list<int> */
function familyIds(): array
{
    return [1];
}

assertSuperType('array', familyIds());
assertSuperType('int', familyIds());
assertNativeType('array', familyIds());
