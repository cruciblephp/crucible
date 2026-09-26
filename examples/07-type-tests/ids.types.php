<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Examples\Types;

use function PHPStan\Testing\assertType;

/** @return list<int> */
function ids(): array
{
    return [1, 2];
}

assertType('list<int>', ids());

$sum = ids()[0] + 'x'; // crucible-type-error binaryOp.invalid
