<?php

declare(strict_types=1);

namespace CrucibleProbe\Types;

/*
 * Values of known, wide types for the narrowing fixtures. Each function
 * hides its answer behind a runtime choice so the analyser cannot see
 * through it: what a fixture line narrows is the declared type, never a
 * constant it happened to infer.
 */

final class Widget
{
    public int $id = 1;
}

function mixedValue(): mixed
{
    return random_int(0, 1) === 1 ? 'x' : null;
}

function maybeString(): ?string
{
    return random_int(0, 1) === 1 ? 'x' : null;
}

function maybeWidget(): ?Widget
{
    return random_int(0, 1) === 1 ? new Widget() : null;
}

function intOrString(): int|string
{
    return random_int(0, 1) === 1 ? 1 : 'x';
}

/** @return array<string, mixed> */
function payload(): array
{
    return random_int(0, 1) === 1 ? ['id' => 1] : [];
}

/** @return array<int, mixed> */
function items(): array
{
    return random_int(0, 1) === 1 ? [new Widget()] : [];
}

function object(): object
{
    return new Widget();
}

function maybeBool(): ?bool
{
    return random_int(0, 1) === 1 ? true : null;
}
