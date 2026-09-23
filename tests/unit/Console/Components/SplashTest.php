<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Console\Components;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Components\Splash;
use LucianoPereira\Crucible\Console\Style\Brand;
use LucianoPereira\Crucible\Console\Style\Color;
use LucianoPereira\Crucible\Console\Style\Palette;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Framework\TestCase;

use function str_contains;
use function str_replace;
use function substr_count;

use const PHP_EOL;

#[CoversClass(Splash::class)]
#[CoversClass(Brand::class)]
final class SplashTest extends TestCase
{
    /**
     * The environment is not allowed to decide what these assert.
     *
     * ⚠ `Capabilities::animation()` reads `CI` and `TERM`, so without the
     * override every assertion below about an interactive terminal would
     * invert the moment this suite ran in CI — which is the one place it
     * has to keep passing.
     */
    protected function setUp(): void
    {
        Capabilities::forceAnimation(true);
    }

    protected function tearDown(): void
    {
        Capabilities::reset();
        Theme::reset();
    }

    private function splash(FakeTerminal $terminal): Splash
    {
        $splash           = new Splash($terminal, 'cruciblephp');
        $splash->interval = 0;

        return $splash;
    }

    private function sgr(Color $color): string
    {
        return Style::none()->withForeground($color)->toAnsi();
    }

    /**
     * The control for every animation assertion below.
     *
     * A banner that animates into a CI log fills it with carriage
     * returns, which is the reason this gate exists rather than a
     * preference.
     */
    public function testANonInteractiveTerminalGetsOneStaticLine(): void
    {
        Capabilities::forceAnimation(false);

        $terminal = new FakeTerminal(interactive: false);

        self::assertSame(0, $this->splash($terminal)->render(), 'no frames were animated');

        $output = $terminal->output();

        // Each cell carries its own SGR prefix, so the wordmark is never
        // a contiguous substring — what a reader sees is what is left
        // once the colour is stripped, which is what this asserts.
        self::assertSame('cruciblephp' . PHP_EOL, Str::stripAnsi($output));
        // The line ending is the OS's own ("\r\n" on Windows); any other
        // "\r" would be a frame rewriting the line.
        self::assertStringNotContainsString("\r", str_replace(PHP_EOL, "\n", $output), 'nothing to overwrite in a log');
        self::assertStringNotContainsString('?25l', $output, 'the cursor is left alone');
        self::assertStringNotContainsString('?25h', $output);
        self::assertSame(1, substr_count($output, "\n"), 'exactly one line');
    }

    /**
     * The frame count is exact, because it is what encodes the bounce.
     *
     * ⚠ `crucible mutate` escaped past `assertGreaterThan(1, …)`: flip the
     * `||` in the bounce guard to `&&` and the head walks one way and
     * never turns, which is still "more than one frame". 11 cells over
     * two round trips is 40 frames; the one-way walk stops at the 45-frame
     * ceiling instead, so this number is what tells them apart.
     */
    public function testAnInteractiveTerminalSweepsAndSettles(): void
    {
        $terminal = new FakeTerminal();
        $frames   = $this->splash($terminal)->render();

        self::assertSame(40, $frames, '11 cells, two round trips, turning at each end');

        $output = $terminal->output();

        self::assertStringContainsString('?25l', $output, 'the cursor hides for the sweep');
        self::assertStringContainsString('?25h', $output, 'and comes back');
        self::assertGreaterThanOrEqual($frames, substr_count($output, "\r"), 'each frame rewrites the line');
    }

    /** One sweep is half of two, which a one-way walk cannot also satisfy. */
    public function testFewerSweepsIsProportionallyFewerFrames(): void
    {
        $terminal = new FakeTerminal();
        $splash   = $this->splash($terminal);

        $splash->sweeps = 1;

        self::assertSame(20, $splash->render());
    }

    /** The head, its one step of falloff, and the unlit rest all appear. */
    public function testAFrameCarriesAllThreeStates(): void
    {
        $terminal = new FakeTerminal();
        $this->splash($terminal)->render();

        $output = $terminal->output();

        self::assertStringContainsString($this->sgr(Brand::ember()), $output, 'the lit head');
        self::assertStringContainsString($this->sgr(Brand::flame()), $output, 'the falloff behind it');
        self::assertStringContainsString($this->sgr(Brand::slate()), $output, 'the unlit remainder');
    }

    /** The settled line is every cell lit, so the mark is left readable. */
    public function testItSettlesWithTheWholeWordmarkLit(): void
    {
        Capabilities::forceAnimation(false);

        $terminal = new FakeTerminal(interactive: false);
        $this->splash($terminal)->render();

        $output = $terminal->output();

        self::assertStringNotContainsString($this->sgr(Brand::slate()), $output, 'nothing stays unlit');
        self::assertSame(11, substr_count($output, $this->sgr(Brand::ember())), 'one per grapheme');
    }

    /**
     * Truecolor is not assumed anywhere.
     *
     * ✓ `38;2;r;g;b` is what a 24-bit terminal takes; a Classic16 one
     * must see none of it, or the brand renders as literal garbage on
     * the terminals least able to complain.
     */
    public function testAClassicTerminalGetsNoTruecolorSequences(): void
    {
        Theme::setPalette(Palette::Classic16);

        $terminal = new FakeTerminal();
        $this->splash($terminal)->render();

        self::assertStringNotContainsString('38;2;', $terminal->output());
        self::assertStringContainsString($this->sgr(Brand::ember()), $terminal->output());
    }

    /**
     * A one-cell wordmark has nowhere to bounce.
     *
     * Without the guard the head sits at both ends at once and the sweep
     * never reaches its turn count — the loop would not end.
     */
    public function testASingleCharacterWordmarkTerminates(): void
    {
        $terminal = new FakeTerminal();
        $splash   = new Splash($terminal, 'x');

        $splash->interval = 0;

        self::assertSame(1, $splash->render(), 'one cell is one frame');
        self::assertStringContainsString('?25h', $terminal->output(), 'and it still tidies up');
    }

    public function testAnEmptyWordmarkWritesNothing(): void
    {
        $terminal = new FakeTerminal();
        $splash   = new Splash($terminal, '');

        $splash->interval = 0;

        self::assertSame(0, $splash->render());
        self::assertSame('', $terminal->output(), 'no cursor codes for nothing');
    }

    /**
     * The suffix rides every frame, after the wordmark, unswept.
     *
     * It is what puts the version and the byline on the banner's own line
     * rather than a second one, so it has to survive the animation: drawn
     * once per frame plus the settled line, and never coloured by the
     * sweep.
     */
    public function testTheSuffixFollowsTheWordmarkOnEveryFrame(): void
    {
        $terminal = new FakeTerminal();
        $splash   = $this->splash($terminal);

        $splash->suffix = ' 1.0.0';
        $frames         = $splash->render();

        self::assertSame(
            $frames + 1,
            substr_count($terminal->output(), ' 1.0.0'),
            'every animated frame, and the settled one',
        );

        Capabilities::forceAnimation(false);

        $static = new FakeTerminal(interactive: false);
        $splash = $this->splash($static);

        $splash->suffix = ' 1.0.0';
        $splash->render();

        self::assertSame('cruciblephp 1.0.0' . PHP_EOL, Str::stripAnsi($static->output()), 'after the mark, not before');
    }

    /**
     * The sweep as arithmetic: tick N lights exactly one cell.
     *
     * ⚠ The asynchronous sweep has no frame count to assert — it runs
     * until the work it covers is done — so the bounce is checked here
     * instead, as a pure function. `frameAt()` is what the forked child
     * calls; if the triangle wave is wrong, this is where it shows,
     * without forking anything.
     *
     * Five cells bounce with a period of eight: 0 1 2 3 4 3 2 1, then
     * round again. A head that walked one way and wrapped would give
     * 0 1 2 3 4 0 1 2 — same first five ticks, different sixth.
     */
    public function testTheSweepBouncesOnASingleCellPerTick(): void
    {
        $splash = new Splash(new FakeTerminal(), 'abcde');

        $lit = [];

        for ($tick = 0; $tick < 10; $tick++) {
            $lit[] = $this->litCell($splash->frameAt($tick));
        }

        self::assertSame([0, 1, 2, 3, 4, 3, 2, 1, 0, 1], $lit, 'out to the end, back, and out again');
    }

    /** One cell has nowhere to bounce, and a period of zero would divide by it. */
    public function testASingleCellSweepStaysPut(): void
    {
        $splash = new Splash(new FakeTerminal(), 'x');

        self::assertSame(0, $this->litCell($splash->frameAt(0)));
        self::assertSame(0, $this->litCell($splash->frameAt(7)));
    }

    /** The suffix rides the asynchronous frames too, not only the settled line. */
    public function testEveryFrameCarriesTheSuffix(): void
    {
        $splash         = new Splash(new FakeTerminal(), 'abc');
        $splash->suffix = ' working';

        self::assertStringEndsWith(' working', $splash->frameAt(0));
        self::assertStringEndsWith(' working', $splash->frameAt(3));
    }

    /**
     * Which cell a frame lit, read back off the painted string.
     *
     * The head is the one cell in {@see Brand::ember()}; everything else
     * is the falloff or unlit, so counting the ember prefix positions
     * gives the position without the test knowing how paint() builds a
     * frame.
     */
    private function litCell(string $frame): int
    {
        $ember = $this->sgr(Brand::ember());
        $index = -1;

        foreach (Str::graphemes(Str::stripAnsi($frame)) as $position => $cell) {
            if (str_contains($frame, $ember . $cell)) {
                $index = $position;

                break;
            }
        }

        return $index;
    }

    /** Graphemes, not bytes: a wide mark stays one cell. */
    public function testAWideCharacterIsOneCell(): void
    {
        Capabilities::forceAnimation(false);

        $terminal = new FakeTerminal(interactive: false);
        $splash   = new Splash($terminal, '日本語');

        $splash->interval = 0;
        $splash->render();

        self::assertSame(
            3,
            substr_count($terminal->output(), $this->sgr(Brand::ember())),
            'three graphemes, not nine bytes',
        );
    }
}
