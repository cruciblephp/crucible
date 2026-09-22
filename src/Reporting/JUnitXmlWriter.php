<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Coverage\SourceAnalysis;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;

use function count;
use function fwrite;
use function htmlspecialchars;
use function is_file;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function substr;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * The opt-in JUnit XML emitter — the one place XML survives in Crucible,
 * because CI systems consume it (ROADMAP: "XML output survives only
 * as an opt-in JUnit emitter for CI interop"). Plain string building;
 * no DOM dependency for a write-only format.
 *
 * Granularity matches what the spec's own JUnit report shows (D-018):
 * risky reports as a plain pass, incomplete as skipped. Full fidelity
 * lives on the NDJSON stream; this file exists for CI dashboards.
 */
final class JUnitXmlWriter implements Listener
{
    /** @var list<TestFinished> */
    private array $finished = [];

    /** @var array<string, array{0: string, 1: array<string, int>}> */
    private array $declarations = [];

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
            $this->render();
        }
    }

    private function render(): void
    {
        $model = new RunModel($this->finished);

        $suites     = '';
        $runCounts  = ['failures' => 0, 'errors' => 0, 'skipped' => 0];
        $runTime    = 0.0;
        $runTests   = 0;
        $runAsserts = 0;

        foreach ($model->sections as $file => $tests) {
            $counts     = ['failures' => 0, 'errors' => 0, 'skipped' => 0];
            $time       = 0.0;
            $assertions = 0;
            $body       = '';

            foreach ($tests as $test) {
                $time += $test->duration;
                $assertions += $test->assertions;
                $body .= $this->testcase($test, $file, $counts);
            }

            $suites .= sprintf(
                "    <testsuite name=\"%s\" file=\"%s\" tests=\"%d\" assertions=\"%d\" failures=\"%d\" errors=\"%d\" skipped=\"%d\" time=\"%.6f\">\n%s    </testsuite>\n",
                $this->escape($this->declaration($file, '')[0]),
                $this->escape($file),
                count($tests),
                $assertions,
                $counts['failures'],
                $counts['errors'],
                $counts['skipped'],
                $time,
                $body,
            );

            $runTests += count($tests);
            $runAsserts += $assertions;
            $runTime += $time;

            foreach ($counts as $key => $value) {
                $runCounts[$key] += $value;
            }
        }

        // The run's own suite wraps the per-file ones: a reader that
        // shows "the suite" wants one node to hang the totals off, and
        // the per-file suites are what it drills into.
        fwrite($this->stream, sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n  <testsuite name=\"%s\" tests=\"%d\" assertions=\"%d\" failures=\"%d\" errors=\"%d\" skipped=\"%d\" time=\"%.6f\">\n%s  </testsuite>\n</testsuites>\n",
            'Crucible',
            $runTests,
            $runAsserts,
            $runCounts['failures'],
            $runCounts['errors'],
            $runCounts['skipped'],
            $runTime,
            $suites,
        ));
    }

    /**
     * @param array<string, int> $counts
     */
    private function testcase(TestFinished $test, string $file, array &$counts): string
    {
        $name = $test->test->name
            . ($test->test->dataset !== null ? PrettyName::dataset($test->test->dataset) : '');

        // The declaring class and its method's line come from the same
        // source analysis the coverage reports parse, so a reader can
        // jump to the test rather than only name it. A file that
        // declares no class still reports, keyed by its path.
        [$class, $line] = $this->declaration($file, $test->test->name);

        $open = sprintf(
            '      <testcase name="%s" file="%s" line="%d" class="%s" classname="%s" assertions="%d" time="%.6f"',
            $this->escape($name),
            $this->escape($file),
            $line,
            $this->escape($class),
            $this->escape(str_replace('\\', '.', $class)),
            $test->assertions,
            $test->duration,
        );

        $message = $test->failure->message ?? $test->reason ?? '';

        switch ($test->outcome) {
            case Outcome::Failed:
                $counts['failures']++;

                return $open . sprintf(
                    ">\n        <failure type=\"%s\">%s</failure>\n%s      </testcase>\n",
                    $this->escape($test->failure->throwableClass ?? 'AssertionFailedError'),
                    $this->escape($message),
                    $this->reruns($test, 'rerunFailure'),
                );

            case Outcome::Errored:
                $counts['errors']++;

                return $open . sprintf(
                    ">\n        <error type=\"%s\">%s</error>\n%s      </testcase>\n",
                    $this->escape($test->failure->throwableClass ?? 'Error'),
                    $this->escape($message),
                    $this->reruns($test, 'rerunError'),
                );

            case Outcome::Skipped:
            case Outcome::Incomplete:
                $counts['skipped']++;

                // The reason rides as text, not an attribute: the
                // incumbent's <skipped/> carries no attributes at all,
                // and text content keeps the reason without inventing
                // a shape its readers do not expect.
                return $open . sprintf(
                    ">\n        <skipped>%s</skipped>\n      </testcase>\n",
                    $this->escape($message),
                );

            case Outcome::Passed:
            case Outcome::Risky:
                // A pass that needed retries is FLAKY (G4): the earlier
                // attempts' failures ride along in the Surefire flaky
                // markup, so CI dashboards can track flakiness (D-043).
                if ($test->retried !== []) {
                    return $open . sprintf(
                        ">\n%s    </testcase>\n",
                        $this->reruns($test, 'flakyFailure'),
                    );
                }

                return $open . "/>\n";
        }
    }

    /**
     * One Surefire rerun element per earlier failed attempt, in
     * attempt order: flakyFailure under a pass, rerunFailure or
     * rerunError under a final failure.
     *
     * @param non-empty-string $element
     */
    private function reruns(TestFinished $test, string $element): string
    {
        $markup = '';

        foreach ($test->retried as $failure) {
            $markup .= sprintf(
                "      <%s type=\"%s\" message=\"%s\"/>\n",
                $element,
                $this->escape($failure->throwableClass ?? 'AssertionFailedError'),
                $this->escape($failure->message),
            );
        }

        return $markup;
    }

    /**
     * tests/unit/Runner/SchedulerTest.php → tests.unit.Runner.SchedulerTest
     */
    /**
     * The class a test file declares and the line its method starts on,
     * from the analysis the coverage reports already build and cache.
     * Falls back to the path-derived name when the file declares no
     * class — a Pest file, or a test defined outside one.
     *
     * @return array{0: string, 1: int}
     */
    private function declaration(string $file, string $method): array
    {
        if (!isset($this->declarations[$file])) {
            $classes = $file !== '' && is_file($file) ? SourceAnalysis::of($file)->classes : [];
            $lines   = [];
            $name    = '';

            foreach ($classes as $qualified => $class) {
                $name = $name === '' ? $qualified : $name;

                foreach ($class->methods as $candidate) {
                    $lines[$candidate->name] ??= $candidate->startLine;
                }
            }

            $this->declarations[$file] = [$name === '' ? $this->classname($file) : $name, $lines];
        }

        [$class, $lines] = $this->declarations[$file];

        return [$class, $lines[$method] ?? 0];
    }

    private function classname(string $file): string
    {
        $classname = str_replace('/', '.', $file);

        return str_ends_with($classname, '.php') ? substr($classname, 0, -4) : $classname;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
