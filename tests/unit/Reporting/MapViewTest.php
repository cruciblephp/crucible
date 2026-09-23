<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Runtime\ForkedAnimation;
use LucianoPereira\Crucible\Console\Runtime\FullscreenPresenter;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Event;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\MapView;
use LucianoPereira\Crucible\Test\TestId;
use ReflectionMethod;

use function array_slice;
use function count;
use function date;
use function end;
use function explode;
use function fopen;
use function function_exists;
use function implode;
use function max;
use function pcntl_alarm;
use function pcntl_signal;
use function preg_match_all;
use function preg_replace;
use function rewind;
use function str_repeat;
use function str_replace;
use function stream_get_contents;
use function substr_count;
use function trim;

use const PREG_SET_ORDER;
use const SIG_DFL;
use const SIGALRM;

/**
 * The single-screen map: a cell per test, reserved before the run starts.
 *
 * ⚠ Every assertion here reads the FIRST frame, which {@see \LucianoPereira\Crucible\Console\Screen\Reconciler}
 * writes in full; later frames are diffs and say nothing about the rows
 * they did not change. A test that read the tail of the output would be
 * asserting against whatever happened to move.
 */
#[CoversClass(MapView::class)]
#[CoversClass(FullscreenPresenter::class)]
final class MapViewTest extends TestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->sequence = 0;

        Capabilities::forceAnimation(true);
    }

    protected function tearDown(): void
    {
        // ⚠ A test that starts a frame without finishing it leaves the
        // view's one-second alarm armed, and the handler holds the view
        // alive: it would keep redrawing into a dead FakeTerminal for the
        // rest of the suite. A run that ends properly disarms itself.
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }

        Capabilities::reset();
        Runtime::reset();
    }

    /**
     * The whole population is on screen before a single test has run.
     *
     * This is the property the view exists for. A first frame that showed
     * an empty box, or one cell, would be a progress bar with extra
     * borders — the size of the job has to be legible from the start.
     */
    public function testTheFirstFrameReservesACellForEveryTest(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 40)));

        $frame = $this->firstFrame($terminal);

        // The map area only: the bar and the legend draw the same glyph,
        // so counting it across the whole frame would pass on a frame with
        // no map in it at all.
        self::assertSame(40, substr_count($this->mapArea($frame), '░'), 'a pending cell per test');
        self::assertStringContainsString('40 tests', $frame, 'and the count is stated on the bar');
        self::assertStringContainsString('Total:         40', $frame, 'and in the readout');
        self::assertStringContainsString('Current:          0', $frame, 'with nothing run yet');
    }

    /** A run of unknown size gets no map at all, rather than one that grows. */
    public function testAnUnknownTotalDrawsNothing(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 0)));

        self::assertSame('', $terminal->output());
    }

    /**
     * A pipe or a CI log gets the report, and no frame at all.
     *
     * ✓ The control for the whole view: `\r`-rewritten frames in a build
     * log are the failure mode this gate exists to prevent. Losing the
     * frame must not lose the run — the composed console report is what
     * says which tests failed, and it is the only thing written here.
     */
    public function testANonAnimatedTerminalGetsTheReportAndNoFrame(): void
    {
        Capabilities::forceAnimation(false);

        $terminal = $this->terminal();
        $stream   = $this->memory();
        $view     = new MapView($stream, fullscreen: true);

        $view->handle($this->envelope(new RunStarted(tests: 3)));
        $this->finish($view, Outcome::Passed, Outcome::Failed, Outcome::Passed);
        $view->handle($this->envelope(new RunFinished(new RunSummary(), 0.0, complete: true)));

        self::assertSame('', $terminal->output(), 'nothing was drawn');

        rewind($stream);
        $report = (string) stream_get_contents($stream);

        self::assertStringContainsString('[fail]', $report, 'the failing test is named');
        self::assertStringContainsString('Tests:', $report, 'and the tally is printed');
        self::assertStringNotContainsString("\r", $report, 'with nothing rewritten');
    }

    /**
     * The report is written after the frame is torn down, never onto it.
     *
     * On the alternate screen a report written first lands on a surface
     * about to be discarded; the whole run would exit having said nothing.
     */
    public function testTheReportFollowsTheFrame(): void
    {
        $terminal = $this->terminal();
        $stream   = $this->memory();
        $view     = new MapView($stream, fullscreen: true);

        $view->handle($this->envelope(new RunStarted(tests: 2)));
        $this->finish($view, Outcome::Passed, Outcome::Failed);

        rewind($stream);

        self::assertSame('', (string) stream_get_contents($stream), 'silent while the map is up');

        $view->handle($this->envelope(new RunFinished(new RunSummary(), 0.0, complete: true)));

        rewind($stream);

        self::assertStringContainsString('Tests:', (string) stream_get_contents($stream));
        self::assertStringContainsString("\e[?1049l", $terminal->output(), 'the screen was handed back first');
    }

    /** Each outcome has its own glyph, so a map is readable without colour. */
    public function testEveryOutcomeGetsItsOwnGlyph(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 6)));
        $this->finish(
            $view,
            Outcome::Passed,
            Outcome::Failed,
            Outcome::Errored,
            Outcome::Risky,
            Outcome::Incomplete,
            Outcome::Skipped,
        );

        $frame = $this->lastFrame($terminal);

        self::assertStringContainsString('█✕✕!?·', $frame, 'six tests, six distinct cells, in order');
    }

    /**
     * The frame is exactly as tall at the end as at the start.
     *
     * A view that added a row per suite, or grew with the failures, would
     * scroll the terminal and lose the thing above it. The height is fixed
     * at the first frame and never renegotiated.
     */
    public function testTheFrameNeverChangesHeight(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 200)));

        for ($i = 0; $i < 200; $i++) {
            $this->finish($view, Outcome::Passed);
        }

        $view->handle($this->envelope(new RunFinished(new RunSummary(), 0.0, complete: true)));

        // ⚠ Measured through the reconciler rather than by counting rows:
        // it redraws the whole region only when the buffer's size changed,
        // so exactly one full draw in the whole run IS the frame never
        // having been resized. Counting newlines in the last write would
        // only measure how many rows that write happened to touch.
        self::assertSame(1, substr_count($terminal->output(), "\r\e[J"), 'one full draw, at the start');
    }

    /**
     * Too many tests for one window, so the window says which tests it holds.
     *
     * ⚠ 24 lines and 80 columns leaves a 7-row map of 76 cells: 532 per
     * window. Without the range on the bar, a second window of the same
     * grid reads as the first one un-passing its tests.
     */
    public function testAPopulationLargerThanTheWindowIsPaged(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 1000)));

        self::assertStringContainsString('tests 1–532 of 1,000', $this->firstFrame($terminal));

        for ($i = 0; $i < 533; $i++) {
            $this->finish($view, Outcome::Passed);
        }

        self::assertStringContainsString('tests 533–1,000 of 1,000', $this->lastFrame($terminal), 'the window turned');
    }

    /**
     * A page turn is drawn immediately, not at the next throttle tick.
     *
     * The throttle exists so a fast suite does not redraw thousands of
     * times; a turn is the one frame it must not swallow, because until it
     * lands the screen is showing a window of the wrong tests.
     */
    public function testAPageTurnIsNotThrottled(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 1000)));

        // Fast enough that the throttle swallows every ordinary frame, so
        // the only redraw that can carry the new range is the turn itself.
        for ($i = 0; $i < 533; $i++) {
            $this->finish($view, Outcome::Passed);
        }

        self::assertStringContainsString('tests 533–1,000 of 1,000', $terminal->output());
    }

    /**
     * The one thing a map cannot say: which cell is being filled now.
     *
     * ⚠ Truncated, never wrapped. A name long enough to take a second
     * row would push every readout below it down, and a panel that moves
     * is a panel that has to be re-read on every frame.
     */
    public function testTheRunningTestIsNamedOnOneLine(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 4)));

        $test = TestId::fromString('tests/unit/' . str_repeat('Very', 40) . 'LongName.php::testIt');

        self::assertInstanceOf(TestId::class, $test);

        $view->handle($this->envelope(new TestStarted($test)));
        $view->handle($this->envelope(new TestFinished($test, Outcome::Passed, 0.0)));

        $frame = $this->lastFrame($terminal);
        $name  = $test->toString();

        self::assertStringContainsString('▸ tests/unit/Very', $frame, 'the name is on the row');
        self::assertStringNotContainsString($name, $frame, 'but not all of it');
        self::assertStringContainsString('…', $frame, 'it was cut, not wrapped');

        // ✓ The control: one full draw for the whole run means the frame
        // never changed size, so the long name cannot have added a row.
        self::assertSame(1, substr_count($terminal->output(), "\r\e[J"));
    }

    /** Small suites get a map their own size, not a screen of empty rows. */
    public function testASmallSuiteGetsAShortFrame(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 12)));

        $frame = $this->firstFrame($terminal);

        self::assertSame(14, substr_count($frame, "\n") + 1, 'one map row and the thirteen rows of chrome');
    }

    /** Elapsed and remaining are on screen from the first frame onward. */
    public function testTheReadoutsAreAlwaysPresent(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 10)));

        $frame = $this->firstFrame($terminal);

        self::assertStringContainsString('Elapsed:', $frame);
        self::assertStringContainsString('Remaining:', $frame);
        self::assertStringContainsString('Estimated:', $frame);
        self::assertStringContainsString('0%', $frame, 'and the one bar');
    }

    /**
     * A screen too narrow for the panel band gets no frame at all.
     *
     * ⚠ Found by running the suite under a PTY that reported no
     * columns: the three cells were computed from a width that could
     * not hold them, went negative, and `str_repeat()` threw — taking
     * down the run the view was supposed to be reporting on.
     */
    public function testATerminalTooNarrowForTheFrameDrawsNothing(): void
    {
        $terminal = new FakeTerminal([], 20, 24);

        Runtime::setTerminal($terminal);

        $view = new MapView($this->memory(), fullscreen: true);

        $view->handle($this->envelope(new RunStarted(tests: 40)));
        $this->finish($view, Outcome::Passed);
        $view->handle($this->envelope(new RunFinished(new RunSummary(), 0.0, complete: true)));

        self::assertSame('', $terminal->output(), 'nothing was drawn, and nothing threw');
    }

    /**
     * The time is on the frame, hard against the right border.
     *
     * A run that stops dead looks identical to a run that is merely
     * slow, until something on screen is still moving.
     */
    public function testTheFrameCarriesAWallClock(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 40)));

        $title = explode("\n", trim($this->firstFrame($terminal), "\n"))[1];

        // /u, because the border closing the row is multi-byte and a
        // byte-wise \S would match its last byte only.
        self::assertMatchesRegularExpression('/\d\d[: ]\d\d │$/u', $title, 'the clock ends the title row');
    }

    /**
     * The separators blink a second on, a second off.
     *
     * ⚠ Driven by an injected moment, not by `time()`. Read from the
     * wall clock this could only ever check the phase the suite
     * happened to run in — a separator wired permanently on would pass
     * every even second, which is half the runs and the half nobody
     * reruns.
     */
    public function testTheClockSeparatorsPulseWithTheSecond(): void
    {
        $this->terminal();

        $at   = new ReflectionMethod(MapView::class, 'wallClockAt');
        $view = $this->view();
        $even = 1_758_480_000;

        self::assertSame(0, $even % 2, 'the fixture is the phase it claims');
        self::assertSame(date('H:i', $even), $at->invoke($view, $even), 'on');
        self::assertSame(
            str_replace(':', ' ', date('H:i', $even + 1)),
            $at->invoke($view, $even + 1),
            'and off a second later',
        );
    }

    /**
     * The configuration is named on the frame, not above it.
     *
     * Printed above, it is covered by the frame a moment later and ends
     * the run sitting under the report as though it were output.
     */
    public function testTheConfigurationIsNamedOnTheFrame(): void
    {
        $terminal = $this->terminal();
        $view     = new MapView($this->memory(), fullscreen: true, configuration: 'crucible.php');

        $view->handle($this->envelope(new RunStarted(tests: 40)));

        $title = explode("\n", trim($this->firstFrame($terminal), "\n"))[1];

        self::assertStringContainsString('crucible.php', $title, 'on the title row');
        self::assertStringContainsString('cruciblephp.com', $title, 'beside the branding');
        self::assertStringNotContainsString('1.0.0', $title, 'and the release, not the build');
    }

    /**
     * The mark cycles through its four phases, one a second.
     *
     * ⚠ Driven by an injected moment for the same reason as the colon:
     * read from the wall clock, a mark frozen on one glyph would pass
     * whenever the suite happened to run on that phase.
     */
    public function testTheMarkCyclesThroughItsPhases(): void
    {
        $this->terminal();

        $at   = new ReflectionMethod(MapView::class, 'markAt');
        $view = $this->view();
        $seen = [];

        for ($second = 0; $second < 4; $second++) {
            $seen[] = $at->invoke($view, $second);
        }

        self::assertSame(['◆', '✦', '◇', '✦'], $seen, 'the diamond opening and closing');
        self::assertSame('◆', $at->invoke($view, 4), 'and back round');
    }

    /**
     * The frame opens onto discovery, not after it.
     *
     * ⚠ The whole point of the opening frame: the row the count will
     * appear on is already on screen saying what it is waiting for, so
     * nothing is drawn above a frame that then covers it.
     */
    public function testTheFrameOpensBeforeTheRunWithANote(): void
    {
        $terminal = $this->terminal();
        $view     = new MapView($this->memory(), fullscreen: true, configuration: 'crucible.php');

        $view->opening('discovering tests…');

        $frame = $this->firstFrame($terminal);

        self::assertStringContainsString('discovering tests…', $frame, 'the note is on the title row');
        self::assertStringNotContainsString('cruciblephp.com', $frame, 'in place of the branding, not beside it');
        // The map area, not the whole frame: the legend's key and the
        // empty bar draw the pending glyph too, so a frame-wide search
        // would find one whether or not any cell was reserved.
        self::assertStringNotContainsString('░', $this->mapArea($frame), 'and no population is claimed yet');

        // The run then fills the frame it is already in.
        $view->handle($this->envelope(new RunStarted(tests: 40)));

        $running = $this->lastFrame($terminal);

        self::assertStringContainsString('cruciblephp.com', $running, 'the branding comes back');
        self::assertSame(1, substr_count($terminal->output(), "\e[?1049h"), 'on the one surface, entered once');

        // ⚠ The property the opening frame exists for: the cells appear
        // IN it. A frame that opened short and grew when the count
        // arrived would move every readout below the map down the
        // screen at the exact moment someone started reading them.
        //
        // Where the sweep forks, the run's first draw is a full redraw of
        // the same height. Where it cannot (Windows, PHP without pcntl)
        // the process patches its own frame in place, touching only the
        // rows that changed — so the check there is that no row lands
        // below the frame the opening drew.
        if (ForkedAnimation::isSupported()) {
            self::assertSame(
                substr_count($frame, "\n"),
                substr_count($running, "\n"),
                'and nothing below the map moved',
            );
        } else {
            self::assertLessThanOrEqual(
                $this->deepestRow($this->firstWrite($terminal)),
                $this->deepestRow($this->lastWrite($terminal)),
                'and nothing below the map moved',
            );
        }
    }

    /**
     * An opening frame with no run behind it still hands the screen back.
     *
     * A discovery that finds nothing, or a terminal that cannot animate
     * by the time the run starts, would otherwise leave the alternate
     * screen held by a frame nothing will ever draw into.
     */
    public function testAnOpeningFrameIsClosedWhenNoRunFollows(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->opening('discovering tests…');
        $view->handle($this->envelope(new RunStarted(tests: 0)));

        self::assertStringContainsString("\e[?1049l", $terminal->output(), 'the screen came back');
        self::assertStringContainsString("\e[?25h", $terminal->output(), 'with the cursor');
    }

    /**
     * The cursor stays hidden from the frame opening to the frame closing.
     *
     * ⚠ The opening sweep is a forked child, and stopping it used to
     * show the cursor — which then sat blinking on the frame's top-left
     * corner for the whole run, reading as though something had jumped
     * there. It is shown once, on the way out, and nowhere else.
     */
    public function testTheCursorIsNeverShownWhileTheFrameIsUp(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->opening('discovering tests…');
        $view->handle($this->envelope(new RunStarted(tests: 40)));
        $this->finish($view, Outcome::Passed);
        $view->handle($this->envelope(new RunFinished(new RunSummary(), 0.0, complete: true)));

        $output = $terminal->output();

        self::assertSame(1, substr_count($output, "\e[?25h"), 'shown exactly once');
        self::assertStringContainsString(
            "\e[?25h\e[?1049l",
            $output,
            'and that once is on the way out, with the surface',
        );
    }

    /** The alternate screen is entered once and handed back at the end. */
    public function testTheAlternateScreenIsRestored(): void
    {
        $terminal = $this->terminal();
        $view     = $this->view();

        $view->handle($this->envelope(new RunStarted(tests: 4)));
        $this->finish($view, Outcome::Passed);

        self::assertSame(1, substr_count($terminal->output(), "\e[?1049h"), 'entered once');

        $view->handle($this->envelope(new RunFinished(new RunSummary(), 0.0, complete: true)));

        self::assertStringContainsString("\e[?1049l", $terminal->output(), 'and left');
        self::assertStringContainsString("\e[?25h", $terminal->output(), 'with the cursor back');
    }

    private function terminal(): FakeTerminal
    {
        $terminal = new FakeTerminal();

        Runtime::setTerminal($terminal);

        return $terminal;
    }

    private function view(): MapView
    {
        return new MapView($this->memory(), fullscreen: true);
    }

    /** @return resource */
    private function memory()
    {
        $stream = fopen('php://memory', 'wb+');

        self::assertNotFalse($stream);

        return $stream;
    }

    private function finish(MapView $view, Outcome ...$outcomes): void
    {
        $test = TestId::fromString('tests/unit/Example.php::testOne');

        self::assertInstanceOf(TestId::class, $test);

        foreach ($outcomes as $outcome) {
            $view->handle($this->envelope(new TestFinished($test, $outcome, 0.0)));
        }
    }

    private function envelope(Event $event): Envelope
    {
        $this->sequence++;

        return new Envelope(max(1, $this->sequence), new DateTimeImmutable(), $event);
    }

    /**
     * The opening full draw, with the escape sequences taken out.
     *
     * Entering the alternate screen homes the cursor too, so the split
     * leaves an empty segment between that and the frame's own home —
     * the first frame is the first segment with anything in it.
     */
    private function firstFrame(FakeTerminal $terminal): string
    {
        return $this->plain($this->firstWrite($terminal));
    }

    /** Whatever the last write was, which for a full redraw is the whole frame. */
    private function lastFrame(FakeTerminal $terminal): string
    {
        return $this->plain($this->lastWrite($terminal));
    }

    /** The opening full draw, escapes kept. */
    private function firstWrite(FakeTerminal $terminal): string
    {
        foreach (array_slice(explode("\e[H", $terminal->output()), 1) as $segment) {
            if (trim($this->plain($segment)) !== '') {
                return $segment;
            }
        }

        return '';
    }

    /** The last write from home, escapes kept. */
    private function lastWrite(FakeTerminal $terminal): string
    {
        $writes = explode("\e[H", $terminal->output());

        return end($writes);
    }

    /**
     * The lowest row, counted from home, a write puts anything on — in
     * newlines for a full redraw, in cursor moves for a patch.
     */
    private function deepestRow(string $write): int
    {
        preg_match_all('/\e\[(\d*)([AB])|\e\[[0-9;?]*[A-Za-z]|\n|[^\e\r\n]/u', $write, $tokens, PREG_SET_ORDER);

        $row     = 0;
        $deepest = 0;

        foreach ($tokens as $token) {
            $move = $token[2] ?? '';

            if ($token[0] === "\n") {
                ++$row;
            } elseif ($move !== '') {
                $row += ($move === 'B' ? 1 : -1) * max(1, (int) ($token[1] ?? 1));
            } elseif ($token[0][0] !== "\e") {
                $deepest = max($deepest, $row);
            }
        }

        return $deepest;
    }

    /**
     * The map rows of a frame: everything between the two blank rows.
     *
     * The layout is fixed, which is the point of it.
     */
    private function mapArea(string $frame): string
    {
        $lines = explode("\n", trim($frame, "\n"));

        // The frame's top border, the title strip, the map's own titled
        // rule, the map, then its divider, the running test, its bottom
        // border, the band's five rows, the bar, and the last border.
        return implode("\n", array_slice($lines, 3, max(0, count($lines) - 13)));
    }

    private function plain(string $text): string
    {
        return Str::stripAnsi((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text));
    }
}
