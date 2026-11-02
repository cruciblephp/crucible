<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\PhpUnitExporter;
use Override;

use function is_string;
use function mb_detect_encoding;
use function mb_stripos;
use function mb_strtolower;
use function sprintf;
use function str_contains;
use function strlen;

final class StringContains extends Constraint
{
    public function __construct(
        private readonly string $needle,
        private readonly bool $ignoreCase = false,
        private readonly bool $ignoreLineEndings = false,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_string($other)) {
            return false;
        }

        if ($this->ignoreLineEndings) {
            $other = StringEqualsStringIgnoringLineEndings::normalize($other);
        }

        if ($this->needle === '') {
            return true;
        }

        return $this->ignoreCase
            ? mb_stripos($other, $this->needle) !== false
            : str_contains($other, $this->needle);
    }

    public function toString(): string
    {
        $needle = $this->ignoreCase ? mb_strtolower($this->needle) : $this->needle;

        return sprintf('contains "%s" %s', $needle, $this->annotation($needle));
    }

    /** ✓ PHPUnit 13.4.3 writes each string with its encoding and its length in bytes. */
    #[Override]
    protected function failureDescription(mixed $other): string
    {
        if (!is_string($other)) {
            return parent::failureDescription($other);
        }

        // Ignoring line endings, the haystack is written as given and measured as compared.
        $compared = $this->ignoreLineEndings ? StringEqualsStringIgnoringLineEndings::normalize($other) : $other;

        return sprintf('Failed asserting that %s %s %s.', PhpUnitExporter::export($other), $this->annotation($compared), $this->toString());
    }

    private function annotation(string $string): string
    {
        $encoding = $this->ignoreCase ? 'Encoding ignored' : (string) mb_detect_encoding($string, ['ASCII', 'UTF-8'], true);

        return sprintf('[%s](length: %d)', $encoding === '' ? 'Encoding detection failed' : $encoding, strlen($string));
    }
}
