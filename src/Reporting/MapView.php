<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use Closure;
use LucianoPereira\Crucible\Console\Runtime\ForkedAnimation;
use LucianoPereira\Crucible\Console\Runtime\FullscreenPresenter;
use LucianoPereira\Crucible\Console\Runtime\InlinePresenter;
use LucianoPereira\Crucible\Console\Runtime\ModalPresenter;
use LucianoPereira\Crucible\Console\Runtime\Presenter;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Screen\Buffer;
use LucianoPereira\Crucible\Console\Screen\Line;
use LucianoPereira\Crucible\Console\Style\Brand;
use LucianoPereira\Crucible\Console\Style\Color;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\Terminal;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunInterrupted;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Reporting\ProgressView\{ProgressView, ProgressViewContract};
use LucianoPereira\Crucible\Version;

use function ceil;
use function count;
use function date;
use function explode;
use function function_exists;
use function implode;
use function intdiv;
use function max;
use function microtime;
use function min;
use function number_format;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_signal;
use function round;
use function sprintf;
use function str_repeat;
use function time;

use const SIG_DFL;
use const SIGALRM;

/**
 * The suite as a map: one cell per test, filling as the run goes.
 *
 * Borrowed from the disk utilities of the late eighties, which solved this
 * on 80x25 and 4.77 MHz. Three things made those readable, and all three
 * are here:
 *
 * - **The space is reserved before the work starts.** Every cell in the
 *   window is drawn pending at the first frame, so the size of the job is
 *   visible immediately rather than inferred from a number that grows.
 * - **It never exceeds its part of the screen.** A population too big for
 *   the window is shown a window at a time — `tests 1–780 of 1450` — and
 *   the frame is rewritten when the run passes the end of one. A cell is
 *   always exactly one test: nothing is averaged away, nothing reflows,
 *   nothing scrolls.
 * - **Elapsed and remaining are always on screen**, beside one progress
 *   bar that is always on screen too.
 *
 * ⚠ Only where a screen can be redrawn. {@see Capabilities::animation()}
 * excludes pipes, CI and `TERM=dumb`; there this degrades to one summary
 * line, because a map is a thing you watch and a log is a thing you read.
 */
#[ProgressView(
    key: 'map',
    description: 'The suite as a map: a cell per test, a progress bar, elapsed time and a remaining estimate.',
    params: [
        ['name' => 'fullscreen', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Draw on the alternate screen instead of inline, and restore the scrollback on exit'],
        ['name' => 'colors', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Enable ANSI color output'],
        ['name' => 'columns', 'type' => 'int', 'required' => false, 'default' => 64, 'description' => 'Progress characters per line, for the report drawn after the map'],
        ['name' => 'results', 'type' => 'bool', 'required' => false, 'default' => true, 'description' => 'Print the problem list and the passed tree once the map is done'],
        ['name' => 'reverseList', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'List the problems last-first'],
        ['name' => 'compact', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Tally only, nothing between the map and it'],
        ['name' => 'configuration', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'The configuration file to name on the frame, instead of printing it above one that covers it'],
    ],
)]
final class MapView implements ProgressViewContract
{
    /** The key a default, unwatched-by-CI run selects. */
    public const string DEFAULT_KEY = 'map-fullscreen';

    /**
     * Frame rows that are not map.
     *
     * The title strip and the border above it, the map box's own three
     * rules and the row for the running test inside it, the panel band's
     * five, and the bar between that band and the frame's last border.
     */
    private const int CHROME = 13;

    /** Band rows: a titled rule, three readouts, the rule the bar hangs from. */
    private const int BOX_ROWS = 5;

    /** Columns the map box's border and padding take from each side. */
    private const int GUTTER = 2;

    /**
     * The mark's cycle: the diamond opening and closing.
     *
     * One phase a second, in step with the clock's colon, because
     * `pcntl_alarm()` counts in whole seconds and a mark advancing on
     * draws alone would race ahead through a fast suite and stall
     * through a slow one.
     *
     * @var list<string>
     */
    private const array MARK = ['◆', '✦', '◇', '✦'];

    /** The legend's preferred width, and the narrowest any box may be. */
    private const int LEGEND_WIDTH = 32;

    private const int MIN_BOX = 14;

    /**
     * Columns below which there is no map to draw.
     *
     * ⚠ The panel band is three named cells between four walls; under
     * this there is no honest way to fit them, and squeezing produced
     * negative cell widths that took `str_repeat()` — and the whole run
     * — down with them. A screen this narrow gets the plain report.
     */
    private const int MIN_WIDTH = 40;

    /** The column the readouts' colons line up on, so the values do too. */
    private const int LABEL_WIDTH = 9;

    /** Map rows an inline frame may take, so it stays a panel and not a takeover. */
    private const int INLINE_ROWS = 10;

    /**
     * The share of a full screen the map may occupy before it starts paging.
     *
     * Not the whole screen. A map pressed against the borders reads worse
     * than a smaller one with air around it, and the readouts beneath it
     * are what someone waiting actually looks at.
     */
    private const float SCREEN_SHARE = 0.66;

    /** Below this a map is a stripe rather than a map, so it never shrinks further. */
    private const int MIN_ROWS = 4;

    /** Redraw at most this often: a fast suite finishes tests quicker than an eye reads. */
    private const float REDRAW_INTERVAL = 0.08;

    /**
     * Milliseconds between frames while the frame is waiting on discovery.
     *
     * ⚠ Drawn from a forked child, because `pcntl_alarm()` counts whole
     * seconds and one frame a second does not read as motion — it reads
     * as a screen that redrew. Safe here and nowhere else: the parent
     * draws nothing at all while it is discovering, so for this one
     * window the child is the only writer.
     */
    private const int OPENING_INTERVAL = 120;

    private readonly Terminal $terminal;

    private readonly Presenter $presenter;

    /** Whether a frame is being drawn at all, as opposed to the one-line fallback. */
    private bool $live = false;

    private int $total = 0;

    private int $done = 0;

    private int $columns = 0;

    private int $rows = 0;

    /** Tests per window: the whole population when it fits, a page of it when not. */
    private int $window = 0;

    /**
     * The outcome of each test, by its position in the run.
     *
     * Keyed by absolute index rather than by window, so a rewritten window
     * is drawn from what actually happened and not from what was on screen.
     *
     * @var array<int, Outcome>
     */
    private array $cells = [];

    /** @var array<string, int> outcome value => count */
    private array $tally = [];

    private float $started = 0.0;

    private float $lastDraw = 0.0;

    /** The window on screen, so a page turn forces a rewrite rather than a diff. */
    private int $page = 0;

    /**
     * The test currently executing, on the row between the map and the panel.
     *
     * The one thing a map cannot show: which cell is being filled right
     * now. A run that stops dead is the case this answers — the cell it
     * stopped on is a square, and this is its name.
     */
    private string $current = '';

    /**
     * What the frame says instead of the branding, before a run exists.
     *
     * Discovery parses every test file before one of them runs, and it
     * is the longest silence in the whole session. The frame opens onto
     * it rather than after it, so the row the count will appear on is
     * already there saying what it is waiting for.
     */
    private string $status = '';

    /** The sweep drawing the opening frame, while the parent is busy discovering. */
    private ?ForkedAnimation $sweep = null;

    /** The mark's phase while the sweep owns it, which moves faster than a second. */
    private ?int $phase = null;

    /**
     * The problem list and the tally, which the map itself never draws.
     *
     * ⚠ Exactly one progress view is ever subscribed, so a map that did
     * not do this would be a run that says nothing about what failed.
     * Composed rather than reimplemented: `progress: false` makes the
     * console reporter silent while the map is on screen and leaves it
     * doing the part it already does — with the same formatting every
     * other view's report has.
     */
    private readonly ConsoleReporter $report;

    /**
     * @param resource $stream
     */
    public function __construct(
        $stream,
        private readonly bool $fullscreen = false,
        bool $colors = false,
        int $columns = 64,
        bool $results = true,
        bool $reverseList = false,
        bool $compact = false,
        private readonly string $configuration = '',
    ) {
        $this->terminal = Runtime::terminal();
        $decorated      = Capabilities::color($this->terminal);

        $this->presenter = $this->fullscreen
            ? new FullscreenPresenter($decorated)
            : new InlinePresenter($decorated);

        $this->report = new ConsoleReporter(
            $stream,
            colors: $colors,
            columns: max(1, $columns),
            progress: false,
            results: $results,
            reverseList: $reverseList,
            compact: $compact,
        );
    }

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof RunStarted) {
            $this->begin($event->tests);
        } elseif ($event instanceof TestStarted) {
            $this->current = $event->test->toString();
        } elseif ($event instanceof TestFinished) {
            $this->record($event->outcome);
        } elseif ($event instanceof RunFinished || $event instanceof RunInterrupted) {
            // The frame comes down FIRST. On the alternate screen the
            // report would otherwise be written onto a surface that is
            // about to be thrown away, and inline it would land inside
            // the region the presenter is still reconciling.
            $this->end();
        }

        $this->report->handle($envelope);
    }

    /**
     * Reserve the window, then draw it empty.
     *
     * ⚠ A run whose total is unknown (`tests: 0`) gets no map. A map that
     * grows while you watch it is the thing this exists to replace, so it
     * falls back rather than inventing a size.
     */
    /**
     * Open the frame before the run, with a note in place of the brand.
     *
     * ⚠ Only ever called when the same conditions {@see begin()} checks
     * already hold; a frame opened here that `begin()` then declines to
     * keep is torn down there rather than left on the screen.
     */
    public function opening(string $note): void
    {
        if (!Capabilities::animation($this->terminal) || $this->terminal->columns() < self::MIN_WIDTH) {
            return;
        }

        $this->status  = $note;
        $this->started = microtime(true);
        $this->columns = max(1, $this->terminal->columns() - 2 * self::GUTTER);
        $this->live    = true;

        // ⚠ The map's full height from the start, before its population
        // is known. Opening at nought rows and growing when the count
        // arrives moves every readout below it down the screen, and the
        // one thing this frame promises is that nothing moves.
        $this->rows = $this->fullscreen ? $this->screenRows() : self::INLINE_ROWS;

        if ($this->fullscreen) {
            Runtime::setPresenter(new ModalPresenter(Capabilities::color($this->terminal)));
        }

        $this->presenter->begin($this->terminal);
        $this->draw();

        $sweep = new ForkedAnimation(
            $this->terminal,
            function (int $tick): string {
                $this->phase = $tick;
                $this->draw();

                return '';
            },
            self::OPENING_INTERVAL,
            owns: true,
        );

        // No fork, no sweep: the clock's own tick still moves the frame
        // once a second, which is worse to watch and better than still.
        if ($sweep->start()) {
            $this->sweep = $sweep;

            return;
        }

        $this->startClock();
    }

    /**
     * Take the surface back from the opening sweep.
     *
     * ⚠ The reconciler is reset, not merely stopped. The child changed
     * cells this process's own model still believes it wrote, so the
     * next diff would skip exactly the cells the child left behind.
     */
    private function settleOpening(): void
    {
        if (!$this->sweep instanceof ForkedAnimation) {
            return;
        }

        $this->sweep->stop();
        $this->sweep = null;
        $this->phase = null;

        $this->presenter->interrupt($this->terminal, '');
    }

    private function begin(int $tests): void
    {
        $this->settleOpening();

        $this->started = $this->live ? $this->started : microtime(true);
        $this->status  = '';
        $this->total   = $tests;

        if ($tests <= 0 || !Capabilities::animation($this->terminal) || $this->terminal->columns() < self::MIN_WIDTH) {
            // An opening frame with no run behind it is closed here, not
            // left holding a screen nothing will ever draw into.
            $this->close();

            return;
        }

        $this->columns = max(1, $this->terminal->columns() - 2 * self::GUTTER);

        $budget = $this->fullscreen ? $this->screenRows() : self::INLINE_ROWS;

        // Only as tall as the population needs — unless a frame is
        // already open at the budget, in which case shrinking it to fit
        // would move everything below it, which is the same defect as
        // growing it.
        $this->rows   = $this->live ? $this->rows : max(1, min($budget, (int) ceil($tests / $this->columns)));
        $this->window = $this->rows * $this->columns;
        $this->live   = true;

        // ⚠ While the map owns the screen, a question asked by anything
        // else must not be drawn into it. Routing prompts through a modal
        // gives them a surface of their own and hands this one back
        // untouched; without it a prompt lands in the middle of the frame
        // and the frame never recovers.
        if ($this->fullscreen) {
            Runtime::setPresenter(new ModalPresenter(Capabilities::color($this->terminal)));
        }

        $this->presenter->begin($this->terminal);
        $this->startClock();
        $this->draw();
    }

    /**
     * Whether a run told only this much will draw its own framed banner.
     *
     * Asked by the CLI before the configuration is loaded, so it reads
     * the command line alone: the banner has to be decided before there
     * is anything else to decide it from. A crucible.php that selects
     * testdox for itself is the one case this cannot see, and the cost
     * there is the banner line, not a broken frame.
     */
    public static function isDefaultFor(?string $view, bool $testdox, bool $teamcity, Terminal $terminal): bool
    {
        if ($view !== null) {
            return $view === 'map' || $view === self::DEFAULT_KEY;
        }

        return !$testdox && !$teamcity && Capabilities::animation($terminal);
    }

    /** The map's row budget on a full screen: a reasonable part of it, not all. */
    private function screenRows(): int
    {
        $usable = max(1, $this->terminal->lines() - self::CHROME);

        return max(1, min($usable, max(self::MIN_ROWS, (int) ($usable * self::SCREEN_SHARE))));
    }

    private function record(Outcome $outcome): void
    {
        $this->tally[$outcome->value] = ($this->tally[$outcome->value] ?? 0) + 1;

        if (!$this->live) {
            $this->done++;

            return;
        }

        $this->cells[$this->done] = $outcome;
        $this->done++;

        $page = intdiv($this->done - 1, $this->window);
        $now  = microtime(true);

        // A page turn is not a frame worth throttling: the whole map has
        // changed, and holding it back leaves a window of the wrong tests
        // on screen.
        if ($page === $this->page && $now - $this->lastDraw < self::REDRAW_INTERVAL && $this->done < $this->total) {
            return;
        }

        $this->page     = $page;
        $this->lastDraw = $now;

        $this->draw();
    }

    private function end(): void
    {
        if (!$this->live) {
            return;
        }

        $this->draw();
        $this->close();
    }

    /** Hand the screen back, once, whether a run followed the frame or not. */
    private function close(): void
    {
        if (!$this->live) {
            return;
        }

        $this->settleOpening();
        $this->stopClock();
        $this->presenter->finish($this->terminal);
        $this->live = false;

        Runtime::setPresenter(null);
    }

    /**
     * The frame: a title strip, the map, and the instrument panel.
     *
     * Three boxes side by side with the bar bare underneath: the disk
     * utilities' Time and Sector readouts and their key, but without the
     * fourth box they put around the bar. Every row not spent on a
     * border is a row of map, and nothing moves between frames, so the
     * eye learns where each number lives once and stops looking for it.
     */
    private function draw(): void
    {
        $width = max(1, $this->terminal->columns());
        $inner = max(3, $width - 10);

        // Every cell keeps at least a column, whatever the legend asks
        // for: a negative width is not a narrow box, it is a crash.
        $legend = min($this->legendWidth($inner), max(1, $inner - 2));
        $time   = max(1, intdiv($inner - $legend, 2));
        $tests  = max(1, $inner - $legend - $time);

        $buffer = new Buffer($width, $this->rows + self::CHROME);
        $y      = 0;

        $this->boxCap($width)->drawInto($buffer, 0, $y++);
        $this->titleStrip($width)->drawInto($buffer, 0, $y++);

        // Joined to the strip by a titled rule rather than closed and
        // reopened: two adjacent borders spend a row saying nothing.
        $top = new Line();
        $this->boxTop($top, 'Suite', $width, '├', '┤');
        $top->drawInto($buffer, 0, $y++);

        for ($row = 0; $row < $this->rows; $row++) {
            $this->mapRow($row, $width)->drawInto($buffer, 0, $y++);
        }

        // The running test belongs to the map, under a rule rather than
        // in a box of its own: it names the cell being filled directly
        // above it, and a border between them would deny that.
        $this->boxDivider($width)->drawInto($buffer, 0, $y++);
        $this->currentRow($width)->drawInto($buffer, 0, $y++);

        $bottom = new Line();
        $this->boxBottom($bottom, $width);
        $bottom->drawInto($buffer, 0, $y++);

        for ($row = 0; $row < self::BOX_ROWS; $row++) {
            $this->panelRow($row, $time, $tests, $legend)->drawInto($buffer, 0, $y++);
        }

        $this->barRow($width)->drawInto($buffer, 0, $y++);

        $close = new Line();
        $this->boxBottom($close, $width);
        $close->drawInto($buffer, 0, $y);

        $this->presenter->present($this->terminal, $buffer);
    }

    /**
     * The test being run, on one line, truncated to the screen.
     *
     * ⚠ Truncated, never wrapped. A long test name that took a second
     * row would move every row below it, and the whole point of the
     * panel is that nothing under the map ever moves.
     */
    private function currentRow(int $width): Line
    {
        $line = new Line();

        $this->wrap($line, $width, function (Line $into) use ($width): Line {
            if ($this->current === '') {
                return $into;
            }

            return $into
                ->add('▸ ', $this->fg(Theme::accent()))
                ->add(Str::truncate($this->current, max(1, $width - 6)), Style::none()->dim());
        });

        return $line;
    }

    /** `├───┤`: a rule across a box without closing it. */
    private function boxDivider(int $width): Line
    {
        return (new Line())->add('├' . str_repeat('─', max(0, $width - 2)) . '┤', $this->edge());
    }

    /** Room for the widest legend entry, and never more than a third. */
    private function legendWidth(int $width): int
    {
        return max(self::MIN_BOX, min(self::LEGEND_WIDTH, (int) ($width * 0.40)));
    }

    /**
     * One row of the panel band, drawn across all three cells at once.
     *
     * One box with shared walls rather than three boxes side by side:
     * abutting boxes put `││` down both seams and spend two columns
     * saying what one says, and there is nothing under three separate
     * boxes for the bar's own border to hang from.
     */
    private function panelRow(int $row, int $time, int $tests, int $legend): Line
    {
        $line = new Line();

        if ($row === 0) {
            return $this->bandTop($time, $tests, $legend);
        }

        if ($row === self::BOX_ROWS - 1) {
            return $this->bandJoin($time, $tests, $legend);
        }

        $elapsed   = microtime(true) - $this->started;
        $remaining = $this->done === 0 ? 0.0 : ($elapsed / $this->done) * ($this->total - $this->done);

        [$timeLabel, $timeValue] = match ($row) {
            1       => ['Estimated', $this->clock($elapsed + $remaining)],
            2       => ['Elapsed', $this->clock($elapsed)],
            default => ['Remaining', $this->clock($remaining)],
        };

        [$testLabel, $testValue] = match ($row) {
            1       => ['Current', number_format($this->done)],
            2       => ['Total', number_format($this->total)],
            default => ['Failed', number_format($this->failures())],
        };

        $this->bandCell($line, $time, fn(Line $into): Line => $this->readout($into, $timeLabel, $timeValue, $time));
        $this->bandCell($line, $tests, fn(Line $into): Line => $this->readout($into, $testLabel, $testValue, $tests));
        $this->bandCell($line, $legend, fn(Line $into): Line => $this->legendCells($into, $row, $legend));

        return $line->add('│', $this->edge());
    }

    /** `┌─ Time ─┬─ Tests ─┬─ Legend ─┐`: the band opened, each cell named. */
    private function bandTop(int ...$cells): Line
    {
        $line  = new Line();
        $glyph = '┌';

        foreach (['Time', 'Tests', 'Legend'] as $index => $title) {
            $label = ' ' . $title . ' ';

            $line
                ->add($glyph . '─', $this->edge())
                ->add($label, Style::none()->bold())
                ->add(str_repeat('─', max(0, $cells[$index] + 1 - Str::width($label))), $this->edge());

            $glyph = '┬';
        }

        return $line->add('┐', $this->edge());
    }

    /** `├─┴─┴─┤`: the band closed and the bar's own box opened beneath it. */
    private function bandJoin(int ...$cells): Line
    {
        $line  = new Line();
        $glyph = '├';

        foreach ($cells as $cell) {
            $line->add($glyph . str_repeat('─', max(0, $cell + 2)), $this->edge());

            $glyph = '┴';
        }

        return $line->add('┤', $this->edge());
    }

    /**
     * One cell of the band: a wall, the content, and padding to its width.
     *
     * The closing wall is the next cell's opening one, so the row is
     * finished by the caller rather than here.
     *
     * @param Closure(Line): Line $content
     */
    private function bandCell(Line $line, int $cell, Closure $content): void
    {
        $line->add('│ ', $this->edge());

        $before = $line->width();
        $content($line);

        $line->add(str_repeat(' ', max(0, $cell - ($line->width() - $before))) . ' ');
    }

    /**
     * A labelled value, colon-aligned so the numbers share a column.
     *
     * Right-aligned on both sides: a readout that shifts as its value
     * gains a digit has to be re-found every time it changes, and these
     * change on every frame.
     */
    private function readout(Line $line, string $label, string $value, int $cell): Line
    {
        $room = max(1, $cell - self::LABEL_WIDTH - 2);

        return $line
            ->add(Str::padLeft($label, self::LABEL_WIDTH) . ': ', Style::none()->dim())
            ->add(Str::padLeft($value, $room), Style::none()->bold());
    }

    /**
     * The key, two glyphs to a row.
     *
     * Six rows of key to read a map is more chrome than map, and the
     * glyph is the part being looked up, not the word beside it.
     */
    private function legendCells(Line $line, int $row, int $box): Line
    {
        $entries = match ($row) {
            1       => [[Outcome::Passed, 'passed'], [Outcome::Failed, 'failed']],
            2       => [[Outcome::Risky, 'risky'], [Outcome::Incomplete, 'incomplete']],
            default => [[Outcome::Skipped, 'skipped'], [null, 'pending']],
        };

        // ⚠ The column is measured, not assumed. A fixed label width
        // overran a narrow box and the padding went to zero, which took
        // the box's own right border off the screen with it.
        $cell = max(3, intdiv(max(1, $box) - 1, 2));

        foreach ($entries as $index => [$outcome, $label]) {
            if ($index > 0) {
                $line->add(' ');
            }

            $line
                ->add($outcome instanceof Outcome ? $this->glyph($outcome) : '░', $outcome instanceof Outcome ? $this->fg($this->colour($outcome)) : Style::none()->dim())
                ->add(' ' . Str::padRight(Str::truncate($label, $cell - 2), $cell - 2), Style::none()->dim());
        }

        return $line;
    }

    /**
     * The one bar, with the window it belongs to and the percentage.
     *
     * ⚠ The bar takes what is left rather than the whole row. A bar the
     * width of the terminal is a bar whose ends nobody can compare, and
     * the columns it gives back say which tests are on screen.
     */
    private function barRow(int $width): Line
    {
        $line  = new Line();
        $inner = max(1, $width - 4);

        $this->wrap($line, $width, function (Line $into) use ($inner): Line {
            $ratio   = $this->total <= 0 ? 0.0 : min(1.0, $this->done / $this->total);
            $percent = sprintf('%3d%%', (int) round($ratio * 100));

            // Before a run there is no population to describe, and "0
            // tests" beside an empty bar reads as a finished run of
            // nothing rather than one that has not started.
            $window = match (true) {
                $this->total <= 0             => '',
                $this->window >= $this->total => sprintf('%s tests', number_format($this->total)),
                default                       => sprintf('tests %s–%s of %s', number_format($this->first() + 1), number_format($this->last()), number_format($this->total)),
            };

            $bar    = max(4, $inner - Str::width($window) - Str::width($percent) - 3);
            $filled = (int) round($ratio * $bar);

            return $into
                ->add($window, Style::none()->dim())
                ->add('  ')
                ->add(str_repeat('█', $filled), $this->fg(Theme::accent()))
                ->add(str_repeat('░', $bar - $filled), Style::none()->dim())
                ->add(' ' . $percent, Style::none()->bold());
        });

        return $line;
    }

    /** `┌─ Title ───────┐`, the title carried in the border the way a panel does it. */
    private function boxTop(Line $line, string $title, int $width, string $start = '┌', string $end = '┐'): void
    {
        $label = ' ' . $title . ' ';
        $rule  = max(0, $width - Str::width($label) - 3);

        $line
            ->add($start . '─', $this->edge())
            ->add($label, Style::none()->bold())
            ->add(str_repeat('─', $rule) . $end, $this->edge());
    }

    /** `┌──┐`: a box opened over a row that carries its own name. */
    private function boxCap(int $width): Line
    {
        return (new Line())->add('┌' . str_repeat('─', max(0, $width - 2)) . '┐', $this->edge());
    }

    private function boxBottom(Line $line, int $width): void
    {
        $line->add('└' . str_repeat('─', max(0, $width - 2)) . '┘', $this->edge());
    }

    /**
     * Put a box's side borders around whatever the callback appends.
     *
     * The callback writes into the same line rather than building its
     * own, because a {@see Line} cannot be concatenated into another.
     * The padding is measured from what it actually wrote, so a row that
     * overruns is clipped by the buffer instead of pushing the border
     * off the screen.
     *
     * @param Closure(Line): Line $content
     */
    private function wrap(Line $line, int $width, Closure $content): void
    {
        $inner = max(1, $width - 4);

        $line->add('│ ', $this->edge());

        $before = $line->width();
        $content($line);
        $used = $line->width() - $before;

        $line
            ->add(str_repeat(' ', max(0, $inner - $used)))
            ->add(' │', $this->edge());
    }

    /**
     * Tick once a second, so the clock moves when nothing else does.
     *
     * ⚠ A frame is otherwise only drawn when a test finishes, and the
     * runs worth watching are the ones where that stops happening — a
     * twelve-second browser test would freeze the clock exactly when
     * someone is looking at it to decide whether anything is alive.
     *
     * Safe against the runner's IPC: the supervisor polls with
     * `@stream_select(..., 0.05s)`, so a signal that interrupts it
     * returns false into a suppressed call and the next poll follows.
     */
    private function startClock(): void
    {
        if (!$this->canTick()) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, $this->tick(...));
        pcntl_alarm(1);
    }

    private function tick(): void
    {
        if (!$this->live) {
            return;
        }

        $this->lastDraw = microtime(true);
        $this->draw();

        pcntl_alarm(1);
    }

    /** ⚠ Disarmed before the surface goes, never after: a tick into a torn-down frame draws onto the restored screen. */
    private function stopClock(): void
    {
        if (!$this->canTick()) {
            return;
        }

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }

    /** Without pcntl the clock still shows the time; it just moves on events. */
    private function canTick(): bool
    {
        return function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals');
    }

    /**
     * `14:07`, its colon blinking a second on, a second off.
     *
     * Hours and minutes only: the seconds are the one field nobody
     * reads off a wall clock, and the blink already says the run is
     * alive — which is the whole job the digits were doing.
     *
     * The blink is derived from the clock rather than counted, so a
     * frame drawn by a test finishing and one drawn by the tick agree
     * on what the colon is doing.
     */
    private function wallClock(): string
    {
        return $this->wallClockAt(time());
    }

    /** ⚠ Takes its moment rather than reading it, so both phases of the blink are testable. */
    private function wallClockAt(int $now): string
    {
        return implode($now % 2 === 0 ? ':' : ' ', explode(':', date('H:i', $now)));
    }

    /** The wordmark and the byline, centred inside the frame's own border. */
    private function titleStrip(int $width): Line
    {
        $line  = new Line();
        $inner = max(1, $width - 4);
        $now   = time();
        $clock = $this->wallClock();

        // The configuration is named HERE rather than printed above the
        // frame, where the frame immediately covers it and the run ends
        // with it sitting under the report as though it were output.
        $file = $this->configuration === ''
            ? ''
            : ' ' . Str::truncate($this->configuration, max(4, intdiv($inner, 3)));

        $this->wrap($line, $width, function (Line $into) use ($inner, $now, $clock, $file): Line {
            $brand = $this->status === ''
                ? sprintf('v%s · %s', $this->release(), Version::DOMAIN)
                : $this->status;
            $room = max(0, $inner - 1 - Str::width($file) - Str::width($clock));

            if (Str::width($brand) > $room) {
                $brand = $this->status === '' ? Version::DOMAIN : Str::truncate($this->status, max(1, $room));
            }

            // Centred on the FRAME, not on what the file and the clock
            // leave over: centring the remainder puts the brand as far
            // right as the file is wide, which is visibly off.
            $lead = max(0, intdiv($inner - Str::width($brand), 2) - 1 - Str::width($file));

            // Centred on what the file and the clock leave, both of which
            // are pinned to an edge: a brand that drifted with either
            // would be a third thing moving on every frame.
            return $into
                ->add($this->markAt($this->phase ?? $now), $this->fg($this->markColour($this->phase ?? $now)))
                ->add($file, Style::none()->dim())
                ->add(str_repeat(' ', $lead))
                ->add($brand, Style::none()->bold())
                ->add(str_repeat(' ', max(0, $room - $lead - Str::width($brand))))
                ->add($clock, Style::none()->dim());
        });

        return $line;
    }

    /** ⚠ Takes its moment rather than reading it, so every phase of the cycle is testable. */
    private function markAt(int $now): string
    {
        return self::MARK[$now % count(self::MARK)];
    }

    /** Ember on the solid phases, flame on the open ones, as the mark's own artwork has it. */
    private function markColour(int $now): Color
    {
        return $now % 2 === 0 ? Brand::ember() : Brand::flame();
    }

    /**
     * `v1.0`: the release, not the build.
     *
     * The patch belongs to `--version`, which states it in full. A frame
     * that carried it would spend three columns on the digit least
     * likely to be the one someone is checking.
     */
    private function release(): string
    {
        $parts = explode('.', Version::NUMBER);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    private function mapRow(int $row, int $width): Line
    {
        $line  = new Line();
        $start = $this->first() + $row * $this->columns;

        $this->wrap($line, $width, function (Line $into) use ($start): Line {
            for ($column = 0; $column < $this->columns; $column++) {
                $index = $start + $column;

                if ($index >= $this->total) {
                    $into->add(' ');

                    continue;
                }

                $outcome = $this->cells[$index] ?? null;

                $into->add(
                    $outcome instanceof Outcome ? $this->glyph($outcome) : '░',
                    $outcome instanceof Outcome ? $this->fg($this->colour($outcome)) : Style::none()->dim(),
                );
            }

            return $into;
        });

        return $line;
    }

    /** The first test index the current window shows. */
    private function first(): int
    {
        return $this->page * $this->window;
    }

    /** The last test number the current window shows, 1-based and clamped. */
    private function last(): int
    {
        return min($this->total, $this->first() + $this->window);
    }

    /** Everything the run will report as a defect, which is what the panel counts. */
    private function failures(): int
    {
        return ($this->tally[Outcome::Failed->value] ?? 0) + ($this->tally[Outcome::Errored->value] ?? 0);
    }

    private function glyph(Outcome $outcome): string
    {
        return match ($outcome) {
            Outcome::Passed                   => '█',
            Outcome::Failed, Outcome::Errored => '✕',
            Outcome::Risky                    => '!',
            Outcome::Incomplete               => '?',
            Outcome::Skipped                  => '·',
        };
    }

    private function colour(Outcome $outcome): Color
    {
        return match ($outcome) {
            Outcome::Passed     => Color::green(),
            Outcome::Failed     => Color::red(),
            Outcome::Errored    => Brand::ember(),
            Outcome::Risky      => Color::yellow(),
            Outcome::Incomplete => Color::cyan(),
            Outcome::Skipped    => Color::gray(),
        };
    }

    /** Seconds as mm:ss, or h:mm:ss once a run has earned the hour. */
    private function clock(float $seconds): string
    {
        $whole   = max(0, (int) round($seconds));
        $hours   = intdiv($whole, 3600);
        $minutes = intdiv($whole % 3600, 60);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $whole % 60)
            : sprintf('%02d:%02d', $minutes, $whole % 60);
    }

    private function edge(): Style
    {
        return $this->fg(Theme::accent());
    }

    private function fg(Color $color): Style
    {
        return Style::none()->withForeground($color);
    }
}
