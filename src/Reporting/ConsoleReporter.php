<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Event\CheckFinished;
use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Heading;
use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\Inline\Text;
use LucianoPereira\Crucible\Reporting\Document\Renderers\ConsoleSummaryRenderer;
use LucianoPereira\Crucible\Reporting\ProgressView\{ProgressView, ProgressViewContract};

use function array_map;
use function array_reverse;
use function count;
use function explode;
use function fwrite;
use function in_array;
use function sprintf;

/**
 * Walking-skeleton console output: progress characters, failure
 * details, summary line. Replaced by the real reporter set in
 * Phase 8; consumes only the event stream, like every reporter.
 */
#[ProgressView(
    key: 'console',
    description: 'The default dot-per-test progress view.',
    params: [
        ['name' => 'colors', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Enable ANSI color output'],
        ['name' => 'columns', 'type' => 'int', 'required' => false, 'default' => 64, 'description' => 'Progress characters per line'],
        ['name' => 'progress', 'type' => 'bool', 'required' => false, 'default' => true, 'description' => 'Print the per-test progress characters'],
        ['name' => 'results', 'type' => 'bool', 'required' => false, 'default' => true, 'description' => 'Print the problem list and the passed tree'],
        ['name' => 'reverseList', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'List the problems last-first'],
        ['name' => 'compact', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Progress and tally only, nothing between them'],
        ['name' => 'issues', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'Issue kinds to list before the tally, comma-separated: deprecation, notice, warning'],
    ],
)]
final class ConsoleReporter implements ProgressViewContract
{
    /** @var list<TestFinished> */
    private array $finished = [];

    private int $emitted = 0;

    private int $flaky = 0;

    /** Run-scoped checks that did not pass: the run failed, so it is not OK (D-126). */
    private int $failedChecks = 0;

    private readonly Style $style;

    /**
     * @param resource     $stream
     * @param positive-int $columns how many progress characters per line
     * @param bool         $progress  print the per-test progress characters
     * @param bool         $results   print the problem list and the passed tree
     * @param bool         $reverseList list the problems last-first
     * @param bool         $compact   one line of progress and one line of tally, nothing else
     * @param string       $issues    the issue kinds listed before the tally, each with the tests that triggered it (D-154)
     */
    public function __construct(
        private $stream,
        bool $colors = false,
        private readonly int $columns = 64,
        private readonly bool $progress = true,
        private readonly bool $results = true,
        private readonly bool $reverseList = false,
        private readonly bool $compact = false,
        private readonly string $issues = '',
    ) {
        $this->style = new Style($colors);
    }

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $this->progress($event);
            $this->finished[] = $event;

            if ($event->flaky()) {
                $this->flaky++;
            }

            return;
        }

        if ($event instanceof CheckFinished && $event->outcome !== Outcome::Passed) {
            $this->failedChecks++;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->summary($event);
        }
    }

    private function progress(TestFinished $event): void
    {
        $character = match (true) {
            // A blocked skip is untested, not a caution — gray, not yellow.
            $event->blocked                         => $this->style->gray('S'),
            $event->outcome === Outcome::Passed     => $this->issueCharacter($event),
            $event->outcome === Outcome::Failed     => $this->style->red('F'),
            $event->outcome === Outcome::Errored    => $this->style->red('E'),
            $event->outcome === Outcome::Skipped    => $this->style->yellow('S'),
            $event->outcome === Outcome::Incomplete => $this->style->yellow('I'),
            default                                 => $this->style->yellow('R'),
        };

        if (!$this->progress) {
            return;
        }

        fwrite($this->stream, $character);

        if (++$this->emitted % $this->columns === 0) {
            fwrite($this->stream, "\n");
        }
    }

    private function summary(RunFinished $event): void
    {
        $model = new RunModel($this->finished);

        fwrite($this->stream, "\n");

        // --compact keeps the progress line and the tally and drops
        // everything between, which is the spec's compact format: enough
        // to see the run went green, nothing to scroll past when it did.
        $problems = $this->results && !$this->compact ? $model->problems : [];

        if ($this->reverseList) {
            $problems = array_reverse($problems);
        }

        foreach ($problems as $index => $problem) {
            fwrite($this->stream, sprintf(
                "\n%d) %s [%s]%s\n",
                $index + 1,
                $problem->test->toString(),
                $this->style->red($problem->outcome->value),
                $problem->quarantined ? ' ' . $this->style->yellow('[quarantined]') : '',
            ));

            if ($problem->reason !== null) {
                fwrite($this->stream, $problem->reason . "\n");
            }

            // The message already carries the rendered diff when the
            // failure is a comparison; the structured expected/actual
            // live on the event for machine consumers. An incomplete
            // test carries both a reason and a failure saying the same
            // thing, and printing it twice helps nobody.
            if ($problem->failure !== null && $problem->failure->message !== $problem->reason) {
                fwrite($this->stream, $problem->failure->message . "\n");
            }
        }

        if ($this->results && !$this->compact) {
            $this->untestedSection($model);
        }

        // The passed tree comes BEFORE the tally, not after: on a suite
        // of any size it is hundreds of rows, and a verdict printed
        // above them has scrolled off by the time the run ends.
        // A tree of tests none of which passed would be all zeros (D-153).
        if ($this->results && !$this->compact && $model->sections !== [] && $event->summary->passed > 0) {
            $renderer = new ConsoleSummaryRenderer();
            $document = new Document([
                new Heading(1, [new Text('Passed')]),
                FoldingTree::build($model->sections, $event->duration),
            ]);

            fwrite($this->stream, "\n" . $renderer->render($document));
        }

        if ($this->results && !$this->compact) {
            $this->issueSection();
        }

        $summary = $event->summary;

        // The two skip figures partition: Skipped is the deliberate
        // skips, Untested the blocked ones (D-075), so the run's "N
        // skipped" no longer reads as "N did not pass — something is
        // wrong". Untested appears only when there is any.
        $untestedField = $summary->untested > 0 ? sprintf(', Untested: %d', $summary->untested) : '';

        fwrite($this->stream, sprintf(
            "\nTests: %d. Passed: %d, Failed: %d, Errors: %d, Skipped: %d%s, Incomplete: %d, Risky: %d. Time: %.3fs\n",
            $summary->total(),
            $summary->passed,
            $summary->failed,
            $summary->errored,
            $summary->skipped - $summary->untested,
            $untestedField,
            $summary->incomplete,
            $summary->risky,
            $event->duration,
        ));

        if ($summary->hasIssues()) {
            fwrite($this->stream, $this->style->yellow(sprintf(
                "Deprecations: %d, Notices: %d, Warnings: %d.\n",
                $summary->deprecations,
                $summary->notices,
                $summary->warnings,
            )));
        }

        if ($this->flaky > 0) {
            fwrite($this->stream, $this->style->yellow(sprintf(
                "Flaky: %d test(s) passed only on retry.\n",
                $this->flaky,
            )));
        }

        if ($this->results && !$this->compact) {
            fwrite($this->stream, $this->verdict($model, $summary) . "\n");
        }

    }

    /**
     * ✓ PHPUnit 13.4.3: a passing test that triggered an issue shows it in
     * the progress line, a warning over a deprecation over a notice (D-154).
     */
    private function issueCharacter(TestFinished $event): string
    {
        $kinds = array_map(static fn(Issue $issue): IssueKind => $issue->kind, $event->issues);

        return match (true) {
            in_array(IssueKind::Warning, $kinds, true)     => $this->style->yellow('W'),
            in_array(IssueKind::Deprecation, $kinds, true) => $this->style->yellow('D'),
            in_array(IssueKind::Notice, $kinds, true)      => $this->style->yellow('N'),
            default                                        => '.',
        };
    }

    /**
     * The issues the run was asked to list, before the tally so the verdict
     * stays last (D-154): each distinct one once, with every test that
     * triggered it, as the spec lists them.
     */
    private function issueSection(): void
    {
        foreach (IssueKind::cases() as $kind) {
            if (!in_array($kind->value, explode(',', $this->issues), true)) {
                continue;
            }

            /** @var array<string, array{Issue, list<string>}> $distinct */
            $distinct = [];

            foreach ($this->finished as $event) {
                foreach ($event->issues as $issue) {
                    if ($issue->kind !== $kind) {
                        continue;
                    }

                    $key = $issue->file . ':' . $issue->line . ':' . $issue->message;
                    $distinct[$key] ??= [$issue, []];

                    if (!in_array($event->test->toString(), $distinct[$key][1], true)) {
                        $distinct[$key][1][] = $event->test->toString();
                    }
                }
            }

            if ($distinct === []) {
                continue;
            }

            fwrite($this->stream, sprintf("\n%d %s(s):\n", count($distinct), $kind->value));

            foreach ($distinct as [$issue, $tests]) {
                fwrite($this->stream, sprintf(
                    "  %s:%d  %s%s\n",
                    $issue->file,
                    $issue->line,
                    $issue->message,
                    $issue->scope instanceof DeprecationScope ? ' [' . $issue->scope->value . ']' : '',
                ));

                foreach ($tests as $test) {
                    fwrite($this->stream, '    triggered by ' . $test . "\n");
                }
            }
        }
    }

    /**
     * The last line, in the spec's words and its precedence (D-153): ✓
     * PHPUnit 13.4.3 writes ERRORS! over FAILURES! over "OK, but there were
     * issues!" (incomplete, risky, deprecations, notices, warnings) over
     * "OK, but some tests were skipped!" over OK. A quarantined failure
     * does not fail the run, so it is an issue.
     */
    private function verdict(RunModel $model, RunSummary $summary): string
    {
        $errored = $failed = $issues = false;

        foreach ($model->problems as $problem) {
            $defect = $problem->outcome === Outcome::Errored || $problem->outcome === Outcome::Failed;

            match (true) {
                $defect && $problem->quarantined       => $issues  = true,
                $problem->outcome === Outcome::Errored => $errored = true,
                $problem->outcome === Outcome::Failed  => $failed  = true,
                $problem->outcome !== Outcome::Skipped => $issues  = true,
                default                                => null,
            };
        }

        return match (true) {
            $errored                           => $this->style->red('ERRORS!'),
            $failed || $this->failedChecks > 0 => $this->style->red('FAILURES!'),
            $issues || $summary->hasIssues()   => $this->style->yellow('OK, but there were issues!'),
            $summary->skipped > 0              => $this->style->yellow('OK, but some tests were skipped!'),
            default                            => $this->style->green('OK'),
        };
    }

    /**
     * The untested block (D-075): the blocked skips, grouped by their
     * reason so a capability that stops several tests is stated once,
     * with the tests it stopped listed beneath. Quiet gray — these are
     * environmental, not defects — and placed after Problems so the
     * real failures lead.
     */
    private function untestedSection(RunModel $model): void
    {
        if ($model->untested === []) {
            return;
        }

        fwrite($this->stream, "\n" . $this->style->gray('Untested') . "\n");

        foreach ($model->untestedByReason as $reason => $events) {
            fwrite($this->stream, $this->style->gray('    ' . $reason) . "\n");

            foreach ($events as $event) {
                fwrite($this->stream, $this->style->gray(
                    '      • ' . PrettyName::ofTest($event->test),
                ) . "\n");
            }
        }
    }
}
