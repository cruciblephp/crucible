<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Components;

use LucianoPereira\Crucible\Console\Input\Key;
use LucianoPereira\Crucible\Console\Style\Color;

/**
 * Prompt the user for a yes/no answer.
 */
final class ConfirmPrompt extends Prompt
{
    public function __construct(
        public readonly string $label,
        public bool $confirmed = true,
        public readonly string $yesLabel = 'Yes',
        public readonly string $noLabel = 'No',
        public readonly string $hint = '',
        bool|string $required = false,
    ) {
        $this->configureValidation($required);

        $this->on('key', function (string $key): void {
            if (Key::is($key, ['y', 'Y'])) {
                $this->confirmed = true;
                $this->submit();
            } elseif (Key::is($key, ['n', 'N'])) {
                $this->confirmed = false;
                $this->submit();
            } elseif (Key::is($key, [...Key::left(), ...Key::right(), Key::TAB, Key::SPACE, 'h', 'l'])) {
                $this->confirmed = ! $this->confirmed;
            } elseif (Key::is($key, Key::enter())) {
                $this->submit();
            }
        });
    }

    public function value(): bool
    {
        return $this->confirmed;
    }

    protected function frame(): array
    {
        if ($this->isFinished()) {
            return [
                $this->title($this->label),
                $this->body($this->confirmed ? $this->yesLabel : $this->noLabel, $this->dim()),
                $this->closing(),
            ];
        }

        $line = $this->bar()
            ->add($this->confirmed ? '● ' : '○ ', $this->confirmed ? $this->fg(Color::green()) : $this->dim())
            ->add($this->yesLabel, $this->confirmed ? null : $this->dim())
            ->add('    ')
            ->add($this->confirmed ? '○ ' : '● ', $this->confirmed ? $this->dim() : $this->fg(Color::green()))
            ->add($this->noLabel, $this->confirmed ? $this->dim() : null);

        return [
            $this->title($this->label),
            $line,
            $this->closing($this->hint),
        ];
    }
}
