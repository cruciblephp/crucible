<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Override;

use function array_keys;
use function array_map;
use function implode;
use function preg_quote;
use function preg_replace_callback;
use function preg_split;
use function str_contains;
use function str_replace;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Negates any constraint; every assertNot*() is LogicalNot around the
 * positive form. Also the class behind the incumbent's name for it,
 * which frameworks construct directly: Laravel's assertDatabaseMissing()
 * is LogicalNot around its own HasInDatabase.
 */
final class LogicalNot extends Constraint
{
    /** Each positive phrase and its negation, applied to a framework's description. */
    private const array NEGATIONS = [
        'contains '    => 'does not contain ',
        'exists'       => 'does not exist',
        'has '         => 'does not have ',
        'is '          => 'is not ',
        'are '         => 'are not ',
        'matches '     => 'does not match ',
        'starts with ' => 'starts not with ',
        'ends with '   => 'ends not with ',
        'reference '   => "don't reference ",
    ];

    public function __construct(
        private readonly Constraint $constraint,
    ) {}

    /**
     * Through evaluate(), not matches(): a framework constraint may
     * replace evaluate() wholesale and leave matches() at the base.
     */
    #[Override]
    public function matches(mixed $other): bool
    {
        return $this->constraint->evaluate($other, '', true) === false;
    }

    public function toString(): string
    {
        $positive = $this->constraint->toString();

        foreach (['is ' => 'is not ', 'has ' => 'does not have ', 'contains ' => 'does not contain ', 'matches ' => 'does not match ', 'starts with ' => 'does not start with ', 'ends with ' => 'does not end with ', 'exists' => 'does not exist'] as $from => $to) {
            if (str_contains($positive, $from)) {
                return str_replace($from, $to, $positive);
            }
        }

        return 'not( ' . $positive . ' )';
    }

    /**
     * A framework constraint describes its own failure (Laravel's names
     * the table and lists the rows it found), so the negation is that
     * description with its wording negated: "a row in the table [users]
     * does not match the attributes ...". Crucible's own constraints keep
     * the sentence built from the negated toString().
     */
    #[Override]
    protected function failureDescription(mixed $other): string
    {
        if (self::writesItsOwnSentence($this->constraint)) {
            return parent::failureDescription($other);
        }

        return 'Failed asserting that ' . $this->negate($this->constraint->failureDescription($other)) . '.';
    }

    /**
     * Negates the wording of a description and leaves every quoted
     * value alone: an exported string or a JSON key that happens to
     * contain "is " is data, not wording.
     */
    private function negate(string $description): string
    {
        $parts = preg_split('/(\'[^\']*\'|"[^"]*")/', $description, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $description;
        }

        $pattern = '/\\b(' . implode('|', array_map(static fn(string $phrase): string => preg_quote($phrase, '/'), array_keys(self::NEGATIONS))) . ')/';
        $negated = '';

        foreach ($parts as $index => $part) {
            $negated .= $index % 2 === 1
                ? $part
                : (preg_replace_callback($pattern, static fn(array $match): string => self::NEGATIONS[$match[1]], $part) ?? $part);
        }

        return $negated;
    }
}
