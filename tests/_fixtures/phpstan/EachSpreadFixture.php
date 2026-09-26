<?php

declare(strict_types=1);

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan;

use function PHPStan\dumpType;

/*
 * A spread `each` (D-137): the matchers after `->each` or `each()` are
 * about the items, to the end of the statement, and what came before it
 * still holds. `each($callback)` keeps the chain on the value.
 */

/** @return list<int>|string */
function listOrText(): array|string
{
    return [1];
}

function spread(): void
{
    $value = listOrText();

    dumpType(expect($value)->each()->toBeString());
    dumpType(expect($value)->each->toBeString()->toBeInt());
    dumpType(expect($value)->toBeArray()->each->toBeInt());
    dumpType(expect($value)->each(static fn($item) => $item)->toBeArray());

    $before = listOrText();
    expect($before)->toBeArray()->each->toBeInt();
    dumpType($before);

    $callback = listOrText();
    expect($callback)->each(static fn($item) => $item)->toBeArray();
    dumpType($callback);

    $items = listOrText();
    expect($items)->each()->toBeString();
    dumpType($items);
}
