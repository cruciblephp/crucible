<?php

declare(strict_types=1);

it('adds', function (int $a, int $b, int $sum): void {
    expect($a + $b)->toBe($sum);
})->with([
    'ints'      => [1, 2, 3],
    'a string'  => ['1', 2, 3],
    'too few'   => [1, 2],
    'a float'   => [1.5, 2, 3],
    'nullable?' => [null, 2, 3],
]);

it('greets', function (string $name): void {
    expect($name)->toBeString();
})->with(['ada', 'grace', 42]);

it('defaults', function (int $a, int $b = 2): void {
    expect($a)->toBeInt();
})->with([[1], [1, 2]]);

it('lazy', function (int $a): void {
    expect($a)->toBeInt();
})->with([fn (): int => 1]);
