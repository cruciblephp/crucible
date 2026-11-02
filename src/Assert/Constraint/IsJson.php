<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Override;

use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function json_validate;
use function sprintf;

final class IsJson extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && $other !== '' && json_validate($other);
    }

    public function toString(): string
    {
        return 'is valid JSON';
    }

    /** ✓ PHPUnit 13.4.3: `an empty string is valid JSON`, else `a string is valid JSON (` the parser's error `)`. */
    #[Override]
    protected function failureDescription(mixed $other): string
    {
        if (!is_string($other)) {
            return parent::failureDescription($other);
        }

        if ($other === '') {
            return 'Failed asserting that an empty string is valid JSON.';
        }

        json_decode($other);

        // ✓ PHPUnit 13.4.3 words each error itself, not as PHP does.
        $error = match (json_last_error()) {
            JSON_ERROR_DEPTH          => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'Underflow or the modes mismatch',
            JSON_ERROR_CTRL_CHAR      => 'Unexpected control character found',
            JSON_ERROR_SYNTAX         => 'Syntax error, malformed JSON',
            JSON_ERROR_UTF8           => 'Malformed UTF-8 characters, possibly incorrectly encoded',
            default                   => json_last_error_msg(),
        };

        return sprintf('Failed asserting that a string is valid JSON (%s).', $error);
    }
}
