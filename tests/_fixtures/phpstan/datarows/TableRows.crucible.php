<?php

declare(strict_types=1);

function crucibleFixtureAdd(int $a, int $b): int
{
    return $a + $b;
}

table(crucibleFixtureAdd(...), [
    'ints'     => [1, 2, 3],
    'a string' => ['1', 2, 3],
    'too few'  => [1, 3],
]);

table('strtoupper', [['abc', 'ABC'], [12, '12']]);
