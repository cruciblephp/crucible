<?php

declare(strict_types=1);

test('uppercases', function (string $in, string $out): void {
    expect(strtoupper($in))->toBe($out);
})->with(['lower' => ['abc', 'ABC'], 'mixed' => ['aBc', 'ABC']]);
