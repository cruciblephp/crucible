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

use function is_string;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * assertStringMatchesFormat(): the EXPECTF-style placeholder grammar
 * shared with PHP's own .phpt format (RESEARCH.md §6 — needed here
 * regardless of the skipped phpt dialect).
 *
 *   %e directory separator   %s one line, non-empty   %S one line
 *   %a any, non-empty        %A any                   %w whitespace
 *   %i signed int            %d unsigned int          %x hex
 *   %f float                 %c single char           %% literal %
 */
final class StringMatchesFormat extends Constraint
{
    public function __construct(
        private readonly string $format,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && preg_match($this->toRegex(), $other) === 1;
    }

    public function toString(): string
    {
        return sprintf('matches format "%s"', $this->format);
    }

    /**
     * @return non-empty-string
     */
    private function toRegex(): string
    {
        $translated = preg_replace_callback(
            '/%[%easSAwidxfc]/',
            static fn(array $match): string => match ($match[0]) {
                '%%'    => '%',
                '%e'    => preg_quote(DIRECTORY_SEPARATOR, '/'),
                '%s'    => '[^\r\n]+',
                '%S'    => '[^\r\n]*',
                '%a'    => '.+',
                '%A'    => '.*',
                '%w'    => '\s*',
                '%i'    => '[+-]?\d+',
                '%d'    => '\d+',
                '%x'    => '[0-9a-fA-F]+',
                '%f'    => '[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[Ee][+-]?\d+)?',
                '%c'    => '.',
                default => $match[0],
            },
            preg_quote($this->format, '/'),
        );

        return '/^' . $translated . '$/s';
    }
}
