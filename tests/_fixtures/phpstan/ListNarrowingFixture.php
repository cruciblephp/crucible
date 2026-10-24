<?php

declare(strict_types=1);

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan;

use LucianoPereira\Crucible\Assert\Assert;

use function PHPStan\dumpType;

/*
 * toBeList() and assertIsList() read as PHPStan reads is_array() &&
 * array_is_list() with phpstan-src#6800 (D-158): the value intersected with
 * list, and array{} where only the empty array can be both.
 */

/**
 * @param array<string, mixed>           $stringKeyed
 * @param non-empty-array<string, mixed> $nonEmpty
 * @param array<positive-int, int>       $positive
 * @param non-empty-list<string>         $list
 * @param array{0: int, 1: string}       $pair
 * @param array<int, string>|string      $listOrText
 */
function lists(array $stringKeyed, array $nonEmpty, array $positive, array $list, array $pair, array|string $listOrText): void
{
    expect($stringKeyed)->toBeList();
    dumpType($stringKeyed);

    expect($positive)->toBeList();
    dumpType($positive);

    expect($list)->toBeList();
    dumpType($list);

    expect($pair)->toBeList();
    dumpType($pair);

    expect($listOrText)->toBeList();
    dumpType($listOrText);

    Assert::assertIsList($nonEmpty);
    dumpType($nonEmpty);
}
