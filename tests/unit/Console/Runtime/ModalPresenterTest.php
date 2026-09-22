<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Console\Runtime;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Runtime\ModalPresenter;
use LucianoPereira\Crucible\Console\Screen\Buffer;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Framework\TestCase;

use function end;
use function explode;
use function preg_replace;
use function str_contains;
use function strpos;
use function substr_count;
use function trim;

/**
 * A question asked over a running view, and the screen it gives back.
 */
#[CoversClass(ModalPresenter::class)]
final class ModalPresenterTest extends TestCase
{
    /**
     * The claim the class exists to make: the screen comes back.
     *
     * ⚠ Entering the alternate screen and leaving it is the whole
     * mechanism. Without the pair, a dialog drawn over a live frame
     * leaves its own rows behind and whatever was underneath is gone —
     * which is exactly the "never goes back to normal" this replaces.
     */
    public function testItTakesTheAlternateScreenAndHandsItBack(): void
    {
        $terminal  = new FakeTerminal();
        $presenter = new ModalPresenter();

        $presenter->begin($terminal);
        $presenter->present($terminal, $this->dialog());
        $presenter->finish($terminal);

        $output = $terminal->output();

        self::assertStringContainsString("\e[?1049h", $output, 'onto its own surface');
        self::assertStringContainsString("\e[?1049l", $output, 'and off it again');
        self::assertStringContainsString("\e[?25h", $output, 'with the cursor visible');

        // Order matters, not just presence: leaving before drawing would
        // put the dialog on the screen it was meant to protect.
        self::assertLessThan(
            (int) strpos($output, "\e[?1049l"),
            (int) strpos($output, 'Overwrite'),
            'the dialog is drawn while the surface is still held',
        );
    }

    /** Entering twice would stack alternate screens and lose the original. */
    public function testTheSurfaceIsTakenOnlyOnce(): void
    {
        $terminal  = new FakeTerminal();
        $presenter = new ModalPresenter();

        $presenter->begin($terminal);
        $presenter->begin($terminal);
        $presenter->finish($terminal);
        $presenter->finish($terminal);

        self::assertSame(1, substr_count($terminal->output(), "\e[?1049h"));
        self::assertSame(1, substr_count($terminal->output(), "\e[?1049l"));
    }

    /**
     * The dialog is boxed and centred, which is what makes it a dialog.
     *
     * A prompt renders bare here — {@see ModalPresenter::wrapsContent()}
     * is true — so if this border were missing the question would float
     * on an empty screen with no frame at all.
     */
    public function testTheDialogIsBoxedAndCentred(): void
    {
        $terminal  = new FakeTerminal();
        $presenter = new ModalPresenter();

        $presenter->begin($terminal);
        $presenter->present($terminal, $this->dialog());

        $rows = $this->rows($terminal);
        $top  = null;

        foreach ($rows as $index => $row) {
            if (str_contains($row, '┌')) {
                $top = $index;

                break;
            }
        }

        self::assertNotNull($top, 'the dialog has a top border');
        self::assertGreaterThan(0, $top, 'and it is not against the top of the screen');

        $left = strpos($rows[$top], '┌');

        self::assertGreaterThan(0, $left, 'nor against the left edge');
        self::assertStringContainsString('Overwrite', $rows[$top + 1], 'the content is inside it');
        self::assertStringContainsString('└', $rows[$top + 2], 'and it closes');
    }

    public function testItDrawsItsOwnFrameSoPromptsRenderBare(): void
    {
        self::assertTrue((new ModalPresenter())->wrapsContent());
    }

    /** Narrow enough to centre in, wide enough to read, on any terminal. */
    public function testTheDialogNeverFillsTheScreenEdgeToEdge(): void
    {
        $presenter = new ModalPresenter();

        self::assertLessThan(200, $presenter->viewportWidth(new FakeTerminal(columns: 200)));
        self::assertGreaterThanOrEqual(1, $presenter->viewportWidth(new FakeTerminal(columns: 4)));
    }

    private function dialog(): Buffer
    {
        $buffer = new Buffer(20, 1);
        $buffer->put(0, 0, 'Overwrite it?');

        return $buffer;
    }

    /** @return list<string> */
    private function rows(FakeTerminal $terminal): array
    {
        $frames = explode("\e[H", $terminal->output());
        $frame  = end($frames);

        return explode("\n", trim(Str::stripAnsi((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $frame)), "\n"));
    }
}
