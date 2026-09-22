<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * The documentation view as a *document* rather than a live printer:
 * the same per-file, pretty-named listing {@see TestDoxReporter} draws
 * on the terminal, rendered for a file in the three shapes the spec
 * writes — plain text, HTML, and the summary alone.
 *
 * One collector behind all three, so the file targets cannot disagree
 * with each other or with the terminal about what happened.
 */
final class TestDoxDocument
{
    /** @var list<TestFinished> */
    private array $finished = [];

    public function record(TestFinished $event): void
    {
        $this->finished[] = $event;
    }

    public function text(RunFinished $event): string
    {
        $model  = new RunModel($this->finished);
        $output = '';

        foreach ($model->sections as $file => $finished) {
            $output .= sprintf("%s (%s)\n", PrettyName::ofFile($file), $file);

            foreach ($finished as $test) {
                $output .= sprintf(" %s %s\n", $this->mark($test), PrettyName::ofTest($test->test));
            }

            $output .= "\n";
        }

        return $output . $this->summary($event);
    }

    public function html(RunFinished $event): string
    {
        $model = new RunModel($this->finished);
        $body  = '';

        foreach ($model->sections as $file => $finished) {
            $body .= sprintf(
                "<h2>%s <small>%s</small></h2>\n<ul>\n",
                htmlspecialchars(PrettyName::ofFile($file), ENT_QUOTES),
                htmlspecialchars($file, ENT_QUOTES),
            );

            foreach ($finished as $test) {
                $body .= sprintf(
                    "  <li class=\"%s\">%s %s</li>\n",
                    $this->cssClass($test),
                    $this->mark($test),
                    htmlspecialchars(PrettyName::ofTest($test->test), ENT_QUOTES),
                );
            }

            $body .= "</ul>\n";
        }

        return sprintf(
            "<!DOCTYPE html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><title>Crucible test documentation</title><style>%s</style></head>\n<body>\n<h1>Test documentation</h1>\n%s<pre>%s</pre>\n</body>\n</html>\n",
            self::STYLE,
            $body,
            htmlspecialchars($this->summary($event), ENT_QUOTES),
        );
    }

    /**
     * The tally alone: the same numbers the other two end with, for a
     * consumer that wants the verdict without the listing.
     */
    public function summary(RunFinished $event): string
    {
        $summary = $event->summary;

        // Untested (D-075) is split from Skipped, appearing only when
        // there is any — the same partition every other view draws.
        $untested = $summary->untested > 0 ? sprintf(', Untested: %d', $summary->untested) : '';

        $output = sprintf(
            "Tests: %d. Passed: %d, Failed: %d, Errors: %d, Skipped: %d%s, Incomplete: %d, Risky: %d. Time: %.3fs\n",
            $summary->total(),
            $summary->passed,
            $summary->failed,
            $summary->errored,
            $summary->skipped - $summary->untested,
            $untested,
            $summary->incomplete,
            $summary->risky,
            $event->duration,
        );

        if ($summary->hasIssues()) {
            $output .= sprintf(
                "Deprecations: %d, Notices: %d, Warnings: %d.\n",
                $summary->deprecations,
                $summary->notices,
                $summary->warnings,
            );
        }

        return $output;
    }

    private const string STYLE = <<<'CSS'
        body { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; margin: 2rem; color: #1a1a1a; background: #fff; }
        h1 { font-size: 1.2rem; } h2 { font-size: 1rem; margin-bottom: .25rem; }
        h2 small { font-weight: 400; color: #777; }
        ul { list-style: none; padding-left: 1rem; margin-top: 0; }
        li { padding: .1rem 0; font-size: .9rem; }
        li.passed { color: #2f6f31; } li.failed { color: #a12a2a; }
        li.untested, li.skipped { color: #8a6d1f; }
        pre { background: #f6f6f6; padding: .75rem; font-size: .85rem; }
        CSS;

    /**
     * @return non-empty-string
     */
    private function mark(TestFinished $test): string
    {
        // A blocked skip (D-075) is untested, not a deliberate skip —
        // its own mark keeps the doc view honest.
        if ($test->blocked) {
            return '⊘';
        }

        return match ($test->outcome) {
            Outcome::Passed => '✔',
            Outcome::Failed,
            Outcome::Errored    => '✘',
            Outcome::Skipped    => '↩',
            Outcome::Incomplete => '∅',
            Outcome::Risky      => '☢',
        };
    }

    private function cssClass(TestFinished $test): string
    {
        if ($test->blocked) {
            return 'untested';
        }

        return match ($test->outcome) {
            Outcome::Passed => 'passed',
            Outcome::Failed,
            Outcome::Errored => 'failed',
            default          => 'skipped',
        };
    }
}
