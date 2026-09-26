<?php

declare(strict_types=1);

dataset('rows', function (): iterable {
    throw new RuntimeException('the dataset cannot be built');
});

test('uses the rows', function (int $row): void {
    expect($row)->toBeInt();
})->with('rows');
