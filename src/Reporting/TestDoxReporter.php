<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Document\Blocks\ProblemList;
use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\ProblemEntry;
use LucianoPereira\Crucible\Reporting\Document\Renderers\TestDoxRenderer;
use LucianoPereira\Crucible\Reporting\ProgressView\{ProgressView, ProgressViewContract};

use function array_filter;
use function array_map;
use function array_values;
use function fwrite;
use function sprintf;

/**
 * The documentation view: prettified sentences under their class
 * section, one mark per outcome. Results are buffered and rendered at
 * run:finish, so the sections stay whole no matter how execution
 * interleaves — the same output with --parallel 8 as sequentially.
 *
 * The per-file, per-test listing stays hand-written (its whole
 * point is showing every test's mark, an audit trail — not an
 * aggregate); Problems builds a `Document` rendered by
 * `TestDoxRenderer` (D-091's addendum).
 */
#[ProgressView(key: 'testdox', description: 'Replace the progress output with the documentation view.')]
final class TestDoxReporter implements ProgressViewContract
{
    /** @var list<TestFinished> */
    private array $finished = [];

    /**
     * @param resource $stream
     */
    public function __construct(private $stream) {}

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $this->finished[] = $event;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->render($event);
        }
    }

    private function render(RunFinished $event): void
    {
        $model = new RunModel($this->finished);

        // Narrower than RunModel's canonical rule: TestDox counts only
        // Failed/Errored as Problems — Risky/Incomplete/blocked skips
        // get their own inline mark instead (D-075's ⊘ among them),
        // not a numbered entry.
        $problems = array_values(array_filter(
            $this->finished,
            static fn(TestFinished $test): bool => $test->outcome === Outcome::Failed || $test->outcome === Outcome::Errored,
        ));

        foreach ($model->sections as $file => $finished) {
            fwrite($this->stream, sprintf("%s (%s)\n", PrettyName::ofFile($file), $file));

            foreach ($finished as $test) {
                fwrite($this->stream, sprintf(
                    " %s %s\n",
                    // A blocked skip (D-075) is untested, not a deliberate
                    // skip — its own mark keeps the doc view honest.
                    $test->blocked ? '⊘' : $this->mark($test->outcome),
                    PrettyName::ofTest($test->test),
                ));
            }

            fwrite($this->stream, "\n");
        }

        if ($problems !== []) {
            $renderer = new TestDoxRenderer();
            $document = new Document([new ProblemList(array_map(
                static fn(TestFinished $problem): ProblemEntry => new ProblemEntry(
                    $problem->test->toString(),
                    $problem->outcome->value,
                    $problem->failure->message ?? $problem->reason,
                ),
                $problems,
            ))]);

            fwrite($this->stream, $renderer->render($document));
        }

        $summary = $event->summary;

        // Untested (D-075) is split from Skipped, appearing only when
        // there is any — the same partition the console draws.
        $untestedField = $summary->untested > 0 ? sprintf(', Untested: %d', $summary->untested) : '';

        fwrite($this->stream, sprintf(
            "Tests: %d. Passed: %d, Failed: %d, Errors: %d, Skipped: %d%s, Incomplete: %d, Risky: %d. Time: %.3fs\n",
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
            fwrite($this->stream, sprintf(
                "Deprecations: %d, Notices: %d, Warnings: %d.\n",
                $summary->deprecations,
                $summary->notices,
                $summary->warnings,
            ));
        }

        if ($problems === []) {
            fwrite($this->stream, $summary->hasIssues() ? "OK, but there were issues!\n" : "OK\n");
        }
    }

    /**
     * @return non-empty-string
     */
    private function mark(Outcome $outcome): string
    {
        return match ($outcome) {
            Outcome::Passed => '✔',
            Outcome::Failed,
            Outcome::Errored    => '✘',
            Outcome::Skipped    => '↩',
            Outcome::Incomplete => '∅',
            Outcome::Risky      => '☢',
        };
    }
}
