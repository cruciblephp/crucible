<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Components;

use LucianoPereira\Crucible\Console\Concerns\Scrolling;
use LucianoPereira\Crucible\Console\Input\Key;
use LucianoPereira\Crucible\Console\Screen\Line;
use LucianoPereira\Crucible\Console\Support\Options;

use function max;
use function min;
use function sprintf;

/**
 * Prompt the user to choose a single option from a scrollable list.
 *
 * Given no `$default` it refuses to answer for a script: see
 * {@see unanswerableWithoutTerminal()}. Every other prompt here has a
 * default and so runs unattended; a list of plugins does not have an
 * obvious first choice, and picking one anyway is the silent wrong answer.
 */
final class SelectPrompt extends Prompt
{
    use Scrolling;

    public readonly Options $options;

    /** Whether the caller named a starting option, and so an answer a script can use. */
    private readonly bool $hasDefault;

    /**
     * @param array<int|string, string> $options value => label, or a plain list of labels
     */
    public function __construct(
        public readonly string $label,
        array $options,
        int|string|null $default = null,
        int $scroll = 5,
        public readonly string $hint = '',
    ) {
        $this->options    = Options::from($options);
        $this->scroll     = max(1, $scroll);
        $this->hasDefault = $this->options->indexOfValue($default) !== null;

        $this->initializeScrolling(
            $this->options->indexOfValue($default) ?? ($this->options->isEmpty() ? null : 0),
        );

        $this->on('key', function (string $key): void {
            if (Key::is($key, Key::up())) {
                $this->highlightPrevious($this->options->count());
            } elseif (Key::is($key, Key::down())) {
                $this->highlightNext($this->options->count());
            } elseif (Key::is($key, Key::enter())) {
                $this->submit();
            }
        });
    }

    public function unanswerableWithoutTerminal(): ?string
    {
        return $this->hasDefault
            ? null
            : sprintf('%s — and there is no terminal to ask. Name the choice on the command line instead.', $this->label);
    }

    public function value(): int|string|null
    {
        return $this->highlighted === null ? null : $this->options->valueAt($this->highlighted);
    }

    public function highlightedLabel(): string
    {
        return $this->highlighted === null ? '' : $this->options->labelAt($this->highlighted);
    }

    protected function totalScrollableItems(): int
    {
        return $this->options->count();
    }

    protected function frame(): array
    {
        if ($this->isFinished()) {
            return [
                $this->title($this->label),
                $this->body($this->highlightedLabel(), $this->dim()),
                $this->closing(),
            ];
        }

        $lines = [$this->title($this->label)];

        foreach ($this->window() as $line) {
            $lines[] = $line;
        }

        $lines[] = $this->closing($this->hint);

        return $lines;
    }

    /** @return list<Line> */
    private function window(): array
    {
        $count = $this->options->count();
        $last  = min($count, $this->firstVisible + $this->scroll);
        $lines = [];

        for ($i = $this->firstVisible; $i < $last; ++$i) {
            $active = $i === $this->highlighted;

            $line = $this->bar()
                ->add($active ? '❯ ' : '  ', $this->accent())
                ->add($this->options->labelAt($i), $active ? $this->accent() : $this->dim());

            $this->addScrollMarker($line, $i, $this->firstVisible, $last - 1, $count);

            $lines[] = $line;
        }

        return $lines;
    }

    private function addScrollMarker(Line $line, int $index, int $first, int $lastVisible, int $count): void
    {
        if ($index === $first && $first > 0) {
            $line->add('  ')->add('↑', $this->dim());
        } elseif ($index === $lastVisible && $lastVisible < $count - 1) {
            $line->add('  ')->add('↓', $this->dim());
        }
    }
}
