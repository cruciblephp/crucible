<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use LucianoPereira\Crucible\Assert\ValueType;
use Stringable;

use function is_object;
use function sprintf;

/**
 * What a matcher needs from its subject before it will answer at all.
 *
 * Three groups of the spec's matchers decline a subject rather than
 * reporting a verdict about it, and the distinction is invisible until
 * `->not`: a failure inverts into a pass and a refusal does not. Every
 * boundary here was measured by executing Pest 5.1.1, never read.
 *
 * The predicates delegate to ValueType where one already exists, so the
 * two enums cannot drift apart about what a string or an iterable is.
 */
enum SubjectRule
{
    /**
     * `toBeHostname`, `toBeUuid`, `toBeDomain`, `toBeIpAddress`,
     * `toBeMacAddress`, `toBeUlid`, `toBeHexadecimal`.
     *
     * Strictly `is_string`: measured, a Stringable returning
     * `'localhost'` is declined rather than accepted as a hostname.
     */
    case IsString;

    /**
     * `toHaveCamelCaseKeys` and its three siblings.
     *
     * `iterable`, not `array` — the incumbent admits an ArrayObject or a
     * Generator and then answers about its keys.
     */
    case IsIterable;

    /**
     * The cast family: `toBeAlpha`, `toBeAlphaNumeric`, `toBeDigits`,
     * `toBeLowercase`, `toBeUppercase`, `toBeSlug`, the four
     * `toBe*Case`, `toBeEmail`, `toBeUrl`.
     *
     * These read the subject THROUGH a string cast rather than requiring
     * one, which is why an array reaches `toBeAlpha` as `'Array'` and
     * passes (Quirk::StringifiedSubject). The cast is the whole
     * mechanism, so the only subject they cannot handle is one PHP
     * refuses to render: an object with no `__toString`. Measured, the
     * incumbent dies there with a raw PHP Error — over all twelve, in
     * both forms — while a resource, an array, an int and null all cast
     * and answer.
     *
     * Crucible declines instead of crashing. That is fidelity at the
     * level of the answer, which is the level that matters: both engines
     * produce no verdict, so `->not` cannot turn either into a pass.
     * Reproducing the Error itself would copy an internal, and the
     * incumbent's own guard on the eleven matchers above is the evidence
     * that declining is what it meant to do here too.
     */
    case ReadableAsString;

    public function accepts(mixed $subject): bool
    {
        return match ($this) {
            self::IsString         => ValueType::String->check($subject),
            self::IsIterable       => ValueType::Iterable->check($subject),
            self::ReadableAsString => !is_object($subject) || $subject instanceof Stringable,
        };
    }

    /**
     * @return non-empty-string
     */
    public function refusal(): string
    {
        return match ($this) {
            self::IsString, self::IsIterable => sprintf(
                'This expectation may only be used on a value of type [%s].',
                $this === self::IsString ? ValueType::String->value : ValueType::Iterable->value,
            ),
            self::ReadableAsString => 'This expectation may only be used on a value that can be read as a string.',
        };
    }
}
