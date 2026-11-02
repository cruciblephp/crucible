<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Double;

use LogicException;

use function sprintf;

/**
 * ✓ PHPUnit 13.4.3 refuses a second with() for one method of a mock: with()
 * is an expectation, not a way to choose a return value.
 */
final class MethodParametersAlreadyConfiguredForAnotherMatcherException extends LogicException
{
    public static function for(string $method): self
    {
        return new self(sprintf(
            'Parameters for method "%s" are already configured for another matcher. with() configures an expectation (the method must be called with the specified arguments), it does not select a return value based on arguments. Use willReturnMap() to return different values based on arguments.',
            $method,
        ));
    }
}
