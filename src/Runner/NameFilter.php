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
use function explode;
use function implode;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;

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

    /** The filter as typed, when it was a substring rather than a regular expression. */
    private ?string $substring;

    /**
     * @param non-empty-string $filter
     */
    public function __construct(string $filter)
    {
        $this->substring = @preg_match($filter, '') === false ? $filter : null;
        $this->pattern   = $this->substring !== null
            ? sprintf('/%s/i', str_replace(['/', '*'], ['\\/', '.*'], $filter))
            : $filter;
    }

    /**
     * Whether a name that is not a PHP test id matches — a Vitest test's
     * full name, which is what `vitest -t` matches too.
     */
    public function matchesName(string $name): bool
    {
        return preg_match($this->pattern, $name) === 1;
    }

    /**
     * The same filter as a JavaScript regular expression source, for
     * `vitest -t` (D-125). A substring keeps its meaning exactly: the
     * text is escaped, `*` stays a wildcard, and each letter matches
     * either case, since a pattern given on Vitest's command line takes
     * no flags. A regular expression passes its body through; its flags
     * cannot follow, and {@see matchesName()} then decides by PHP's
     * reading of it.
     *
     * @return non-empty-string
     */
    public function vitestPattern(): string
    {
        if ($this->substring === null) {
            $delimiter = $this->pattern[0];
            $closing   = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$delimiter] ?? $delimiter;
            $end       = strrpos($this->pattern, $closing);
            $body      = $end === false || $end === 0 ? '' : substr($this->pattern, 1, $end - 1);

            return $body !== '' ? $body : '.*';
        }

        $parts = [];

        foreach (explode('*', $this->substring) as $part) {
            $parts[] = preg_replace_callback(
                '/[a-z]/i',
                static fn(array $letter): string => '[' . strtolower($letter[0]) . strtoupper($letter[0]) . ']',
                preg_quote($part),
            );
        }

        return implode('.*', $parts);
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
