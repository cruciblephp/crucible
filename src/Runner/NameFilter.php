<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Test\TestId;

use function ctype_digit;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * The spec's --filter semantics: a pattern that already parses as a
 * regular expression is used verbatim; anything else becomes an
 * unanchored, case-insensitive substring pattern with `*` as a
 * wildcard. Matched against `Fully\Qualified\Class::method`, with the
 * spec's dataset spellings appended for parameterized rows — so
 * patterns targeting `with data set "name"` behave identically on
 * both runners.
 */
final readonly class NameFilter
{
    /** @var non-empty-string */
    private string $pattern;

    /**
     * @param non-empty-string $filter
     */
    public function __construct(string $filter)
    {
        $this->pattern = @preg_match($filter, '') === false
            ? sprintf('/%s/i', str_replace(['/', '*'], ['\\/', '.*'], $filter))
            : $filter;
    }

    /**
     * @param non-empty-string $className
     */
    public function matches(string $className, TestId $id): bool
    {
        $name = $className . '::' . $id->name;

        if ($id->dataset !== null) {
            $name .= ctype_digit($id->dataset)
                ? sprintf(' with data set #%s', $id->dataset)
                : sprintf(' with data set "%s"', $id->dataset);
        }

        return preg_match($this->pattern, $name) === 1;
    }
}
