<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

use LucianoPereira\Crucible\Property\Gen;

/**
 * A DTO's export — the kind of value whose keys and types drift quietly.
 *
 * @return array{id: positive-int, email: non-empty-string, tags: list<string>}
 */
function exportUser(int $id, string $email, array $tags): array
{
    return ['id' => $id, 'email' => $email, 'tags' => array_values($tags)];
}

it('states the shape of a value once, for the run and for the analyser', function (): void {
    $user = exportUser(7, 'ada@example.com', ['admin']);

    expect($user)->toMatchShape('array{id: positive-int, email: non-empty-string, tags: list<string>}');

    // PHPStan now knows the shape: $user['tags'] is list<string>.
    expect($user['tags'])->toContain('admin');
});

it('names where a value stops fitting', function (): void {
    expect(fn () => expect(['id' => 7, 'email' => ''])->toMatchShape('array{id: int, email: non-empty-string}'))
        ->toThrow(LucianoPereira\Crucible\Assert\AssertionFailedError::class, '$.email: expected non-empty-string');
});

property(
    'draws data from the same type',
    Gen::of('array{id: positive-int, email: non-empty-string, tags: list<string>}'),
    fn (array $input) => expect(exportUser($input['id'], $input['email'], $input['tags']))
        ->toMatchShape('array{id: positive-int, email: non-empty-string, tags: list<string>}'),
);
