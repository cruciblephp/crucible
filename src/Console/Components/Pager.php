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
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Screen\Buffer;
use LucianoPereira\Crucible\Console\Screen\Line;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Terminal\Terminal;
use Override;

use function count;
use function max;
use function mb_strtolower;
use function mb_substr;
use function min;
use function preg_match;
use function round;
use function str_contains;

/**
 * A scrollable text/markdown viewer — the heart of the Norton-Guides-style
 * documentation browser. Arrows/PageUp/PageDown scroll, `/` searches, and
 * number keys follow the document's links (which the host resolves).
 */
final class Pager extends Prompt
{
    public ?int $followLink = null;

    public bool $goBack = false;

    public bool $quit = false;

    private int $scrollTop = 0;

    private bool $searching = false;

    private string $query = '';

    /** @var list<int> */
    private array $matches = [];

    private int $matchPos = 0;

    private readonly Terminal $terminal;

    /**
     * @param list<Line> $content
     * @param list<string> $links
     */
    public function __construct(
        private readonly array $content,
        public readonly string $title = '',
        private readonly array $links = [],
    ) {
        $this->terminal = Runtime::terminal();

        $this->on('key', function (string $key): void {
            $this->searching ? $this->handleSearch($key) : $this->handleNormal($key);
        });
    }

    public function value(): null
    {
        return null;
    }

    #[Override]
    public function measure(int $width): int
    {
        return $this->windowHeight() + 2;
    }

    protected function frame(): array
    {
        $height = $this->windowHeight();
        $lines  = [$this->header()];

        $current = $this->matches[$this->matchPos] ?? -1;

        for ($row = 0; $row < $height; ++$row) {
            $index = $this->scrollTop + $row;
            $line  = new Line();

            if (isset($this->content[$index])) {
                $line->add($index === $current ? '▸ ' : '  ', $this->accent());

                foreach ($this->contentSegments($index) as $segment) {
                    $line->add($segment['text'], $segment['style']);
                }
            }

            $lines[] = $line;
        }

        $lines[] = $this->footer();

        return $lines;
    }

    #[Override]
    public function render(Buffer $buffer): void
    {
        $height = $this->windowHeight();

        $this->header()->drawInto($buffer, 0, 0);

        $current = $this->matches[$this->matchPos] ?? -1;

        for ($row = 0; $row < $height; ++$row) {
            $index = $this->scrollTop + $row;

            if (! isset($this->content[$index])) {
                continue;
            }

            $buffer->put(0, $row + 1, $index === $current ? '▸' : ' ', $this->accent());
            $this->content[$index]->drawInto($buffer, 2, $row + 1);
        }

        $this->footer()->drawInto($buffer, 0, $height + 1);
    }

    private function handleNormal(string $key): void
    {
        if (Key::is($key, Key::up())) {
            $this->scrollBy(-1);
        } elseif (Key::is($key, Key::down())) {
            $this->scrollBy(1);
        } elseif (Key::is($key, [Key::SPACE, "\e[6~"])) {
            $this->scrollBy($this->windowHeight());
        } elseif (Key::is($key, "\e[5~")) {
            $this->scrollBy(-$this->windowHeight());
        } elseif (Key::is($key, Key::HOME)) {
            $this->scrollTop = 0;
        } elseif (Key::is($key, Key::END)) {
            $this->scrollTop = $this->maxScroll();
        } elseif (Key::is($key, '/')) {
            $this->searching = true;
            $this->query     = '';
        } elseif (Key::is($key, 'n')) {
            $this->cycleMatch(1);
        } elseif (Key::is($key, 'N')) {
            $this->cycleMatch(-1);
        } elseif (preg_match('/^[1-9]$/', $key) === 1 && (int) $key <= count($this->links)) {
            $this->followLink = (int) $key - 1;
            $this->submit();
        } elseif (Key::is($key, [Key::BACKSPACE, 'h'])) {
            $this->goBack = true;
            $this->submit();
        } elseif (Key::is($key, ['q', Key::ESCAPE])) {
            $this->quit = true;
            $this->submit();
        }
    }

    private function handleSearch(string $key): void
    {
        if (Key::is($key, Key::enter())) {
            $this->runSearch();
            $this->searching = false;
        } elseif (Key::is($key, Key::ESCAPE)) {
            $this->searching = false;
        } elseif (Key::is($key, [Key::BACKSPACE, Key::CTRL_H])) {
            $this->query = mb_substr($this->query, 0, -1);
        } elseif (Key::isPrintable($key)) {
            $this->query .= $key;
        }
    }

    private function runSearch(): void
    {
        $needle        = mb_strtolower($this->query);
        $this->matches = [];

        if ($needle === '') {
            return;
        }

        foreach ($this->content as $index => $line) {
            if (str_contains(mb_strtolower($line->plainText()), $needle)) {
                $this->matches[] = $index;
            }
        }

        $this->matchPos = 0;
        $this->scrollToMatch();
    }

    private function cycleMatch(int $direction): void
    {
        if ($this->matches === []) {
            return;
        }

        $count          = count($this->matches);
        $this->matchPos = ($this->matchPos + $direction + $count) % $count;
        $this->scrollToMatch();
    }

    private function scrollToMatch(): void
    {
        if (isset($this->matches[$this->matchPos])) {
            $this->scrollTop = min($this->matches[$this->matchPos], $this->maxScroll());
        }
    }

    private function scrollBy(int $delta): void
    {
        $this->scrollTop = max(0, min($this->maxScroll(), $this->scrollTop + $delta));
    }

    private function maxScroll(): int
    {
        return max(0, count($this->content) - $this->windowHeight());
    }

    private function windowHeight(): int
    {
        $max = max(1, $this->terminal->lines() - 4);

        return max(1, min(count($this->content), $max));
    }

    private function header(): Line
    {
        $percent = $this->maxScroll() === 0 ? 100 : (int) round($this->scrollTop / $this->maxScroll() * 100);

        return (new Line())
            ->add(self::TOP . ' ', $this->stateStyle())
            ->add($this->title === '' ? 'Viewer' : $this->title, $this->bold())
            ->add('  ' . $percent . '%', $this->dim());
    }

    private function footer(): Line
    {
        if ($this->searching) {
            return (new Line())
                ->add(self::BOTTOM . ' ', $this->stateStyle())
                ->add('/', $this->accent())
                ->add($this->query . '▏');
        }

        $hint = '↑/↓ scroll · / search · n next';
        $hint .= $this->links === [] ? '' : ' · 1-9 open';
        $hint .= ' · ⌫ back · q quit';

        return (new Line())
            ->add(self::BOTTOM . ' ', $this->stateStyle())
            ->add($hint, $this->dim());
    }

    /**
     * @return list<array{text: string, style: Style}>
     */
    private function contentSegments(int $index): array
    {
        return [['text' => $this->content[$index]->plainText(), 'style' => Style::none()]];
    }
}
