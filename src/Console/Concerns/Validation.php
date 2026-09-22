<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Concerns;

use Closure;

use function in_array;
use function is_string;

/**
 * Adds "required" and custom-closure validation to a prompt.
 *
 * The host prompt must expose {@see Validation::setError()} (provided by the
 * base prompt) to record a failure.
 */
trait Validation
{
    protected bool $required = false;

    protected string $requiredMessage = 'This field is required.';

    /**
     * A user-supplied validator returning an error message, or null when valid.
     * Typed loosely because each prompt hands it a differently-typed value.
     */
    protected ?Closure $validator = null;

    /**
     * Configure required-ness and a custom validator in one call.
     *
     * When {@see $required} is a string it doubles as the "required" message.
     */
    protected function configureValidation(bool|string $required = false, ?Closure $validator = null): void
    {
        if (is_string($required)) {
            $this->required        = true;
            $this->requiredMessage = $required;
        } else {
            $this->required = $required;
        }

        $this->validator = $validator;
    }

    /**
     * Run required + custom validation, recording the first error found.
     */
    protected function validateValue(mixed $value): void
    {
        if ($this->required && $this->isEmptyValue($value)) {
            $this->setError($this->requiredMessage);

            return;
        }

        if ($this->validator === null) {
            return;
        }

        /** @var mixed $error */
        $error = ($this->validator)($value);

        if (is_string($error) && $error !== '') {
            $this->setError($error);
        }
    }

    protected function isEmptyValue(mixed $value): bool
    {
        return in_array($value, ['', null, [], false], true);
    }

    abstract protected function setError(string $message): void;
}
