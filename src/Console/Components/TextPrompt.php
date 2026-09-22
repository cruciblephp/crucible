<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Components;

use Closure;
use LucianoPereira\Crucible\Console\Concerns\TypedValue;

/**
 * Prompt the user for a single line of free-form text.
 */
final class TextPrompt extends Prompt
{
    use TypedValue;

    /**
     * @param (Closure(string): (string|null))|null $validate
     */
    public function __construct(
        public readonly string $label,
        public readonly string $placeholder = '',
        string $default = '',
        public readonly string $hint = '',
        bool|string $required = false,
        ?Closure $validate = null,
    ) {
        $this->configureValidation($required, $validate);
        $this->trackTypedValue($default);
    }

    public function value(): string
    {
        return $this->typedValue;
    }

    protected function frame(): array
    {
        if ($this->isFinished()) {
            return [
                $this->title($this->label),
                $this->body($this->value(), $this->dim()),
                $this->closing(),
            ];
        }

        return [
            $this->title($this->label),
            $this->appendCursor($this->bar(), $this->value(), $this->cursorPosition(), $this->placeholder),
            $this->closing($this->hint),
        ];
    }
}
