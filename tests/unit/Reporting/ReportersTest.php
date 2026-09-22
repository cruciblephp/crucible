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
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\ConsoleReporter;
use LucianoPereira\Crucible\Reporting\Document\RunReportDocument;
use LucianoPereira\Crucible\Reporting\GenericReportWriter;
use LucianoPereira\Crucible\Reporting\JUnitXmlWriter;
use LucianoPereira\Crucible\Reporting\PrettyName;
use LucianoPereira\Crucible\Reporting\ProfileReporter;
use LucianoPereira\Crucible\Reporting\ReportFormat\Formats\{MarkdownReportFormat, PdfReportFormat};
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportContext;
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormatRegistry;
use LucianoPereira\Crucible\Reporting\RunModel;
use LucianoPereira\Crucible\Reporting\Style;
use LucianoPereira\Crucible\Reporting\Subscriber\SubscriberRegistry;
use LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\JUnitSubscriber;
use LucianoPereira\Crucible\Reporting\TeamCityReporter;
use LucianoPereira\Crucible\Reporting\TestDoxReporter;
use LucianoPereira\Crucible\Test\TestId;
use LucianoPereira\Crucible\Version;

use function file_get_contents;
use function fopen;
use function preg_match;
use function preg_match_all;
use function rewind;
use function simplexml_load_string;
use function stream_get_contents;
use function strpos;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(ConsoleReporter::class)]
#[CoversClass(TestDoxReporter::class)]
#[CoversClass(TeamCityReporter::class)]
#[CoversClass(JUnitXmlWriter::class)]
#[CoversClass(JUnitSubscriber::class)]
#[CoversClass(GenericReportWriter::class)]
#[CoversClass(PdfReportFormat::class)]
#[CoversClass(MarkdownReportFormat::class)]
#[CoversClass(PrettyName::class)]
#[CoversClass(RunReportDocument::class)]
#[CoversClass(ProfileReporter::class)]
final class ReportersTest extends TestCase
{
    /**
     * Feeds a representative little run through the given listener
     * and returns everything it wrote.
     *
     * @param callable(resource): Listener $reporter
     */
    private function output(callable $reporter): string
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            self::fail('Cannot open the in-memory stream.');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe($reporter($stream));

        $passing = new TestId('tests/unit/SchedulerTest.php', 'testRunsFastestFirst');
        $row     = new TestId('tests/unit/SchedulerTest.php', 'testShuffles', 'seed one');
        $failing = new TestId('tests/unit/CacheTest.php', 'testRoundTrips');
        $skipped = new TestId('tests/unit/CacheTest.php', 'testNeedsRedis');

        $emitter->emit(new RunStarted());

        $emitter->emit(new TestStarted($passing));
        $emitter->emit(new TestFinished($passing, Outcome::Passed, 0.010));
        $emitter->emit(new TestStarted($row));
        $emitter->emit(new TestFinished($row, Outcome::Passed, 0.020));

        $emitter->emit(new TestStarted($failing));
        $emitter->emit(new TestFinished($failing, Outcome::Failed, 0.030, new Failure(
            "Values do not match | pipes ['and brackets'] included",
            \LucianoPereira\Crucible\Assert\AssertionFailedError::class,
            expected: "'calm'",
            actual: "'boom'",
        )));

        $emitter->emit(new TestStarted($skipped));
        $emitter->emit(new TestFinished($skipped, Outcome::Skipped, 0.0, reason: 'Redis is not available — see #7.'));

        $emitter->emit(new RunFinished(
            new RunSummary(passed: 2, failed: 1, skipped: 1),
            0.060,
        ));

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * A run with one passing test, one deliberate skip, and two blocked
     * skips (D-075) that share a single reason — the fixture for the
     * untested-classification assertions across the human reporters.
     *
     * @param callable(resource): Listener $reporter
     */
    private function untestedOutput(callable $reporter): string
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            self::fail('Cannot open the in-memory stream.');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-21T12:00:00+00:00')));
        $emitter->subscribe($reporter($stream));

        $passing    = new TestId('tests/unit/MathTest.php', 'testAdds');
        $deliberate = new TestId('tests/unit/MathTest.php', 'testNotReady');
        $blockedA   = new TestId('tests/unit/CoverageTest.php', 'testXdebugCollects');
        $blockedB   = new TestId('tests/unit/CoverageTest.php', 'testXdebugBranches');

        $emitter->emit(new RunStarted());
        $emitter->emit(new TestFinished($passing, Outcome::Passed, 0.01));
        $emitter->emit(new TestFinished($deliberate, Outcome::Skipped, 0.0, reason: 'not ready yet'));
        $emitter->emit(new TestFinished($blockedA, Outcome::Skipped, 0.0, reason: 'PHP extension xdebug is missing.', blocked: true));
        $emitter->emit(new TestFinished($blockedB, Outcome::Skipped, 0.0, reason: 'PHP extension xdebug is missing.', blocked: true));
        $emitter->emit(new RunFinished(new RunSummary(passed: 1, skipped: 3, untested: 2), 0.02));

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @param class-string $formatClass */
    private function genericUntestedOutput(string $key, string $formatClass): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-report-');
        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $registry = new ReportFormatRegistry([$key => ['class' => $formatClass, 'params' => ['output' => $path]]]);
        $writer   = new GenericReportWriter($registry, [$key]);

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-21T12:00:00+00:00')));
        $emitter->subscribe($writer);

        $passing    = new TestId('tests/unit/MathTest.php', 'testAdds');
        $deliberate = new TestId('tests/unit/MathTest.php', 'testNotReady');
        $blockedA   = new TestId('tests/unit/CoverageTest.php', 'testXdebugCollects');
        $blockedB   = new TestId('tests/unit/CoverageTest.php', 'testXdebugBranches');

        $emitter->emit(new RunStarted());
        $emitter->emit(new TestFinished($passing, Outcome::Passed, 0.01));
        $emitter->emit(new TestFinished($deliberate, Outcome::Skipped, 0.0, reason: 'not ready yet'));
        $emitter->emit(new TestFinished($blockedA, Outcome::Skipped, 0.0, reason: 'PHP extension xdebug is missing.', blocked: true));
        $emitter->emit(new TestFinished($blockedB, Outcome::Skipped, 0.0, reason: 'PHP extension xdebug is missing.', blocked: true));
        $emitter->emit(new RunFinished(new RunSummary(passed: 1, skipped: 3, untested: 2), 0.02));

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * Runs the representative fixture (same events as {@see output()})
     * through a real `GenericReportWriter`, resolving one format key
     * from a registry seeded with just that key — mirroring how a real
     * `crucible.php` + CLI selection resolves down to one writer, one
     * format. Writes to a real temp file (GenericReportWriter opens
     * its own stream per format, unlike the old per-format writers
     * that took an already-open stream), read back after the run.
     *
     * @param class-string $formatClass
     * @param ?non-empty-string $title
     */
    private function genericOutput(string $key, string $formatClass, ?string $title = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-report-');
        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $registry = new ReportFormatRegistry([$key => ['class' => $formatClass, 'params' => ['output' => $path]]]);
        $writer   = new GenericReportWriter($registry, [$key], $title);

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe($writer);

        $passing = new TestId('tests/unit/SchedulerTest.php', 'testRunsFastestFirst');
        $row     = new TestId('tests/unit/SchedulerTest.php', 'testShuffles', 'seed one');
        $failing = new TestId('tests/unit/CacheTest.php', 'testRoundTrips');
        $skipped = new TestId('tests/unit/CacheTest.php', 'testNeedsRedis');

        $emitter->emit(new RunStarted());

        $emitter->emit(new TestStarted($passing));
        $emitter->emit(new TestFinished($passing, Outcome::Passed, 0.010));
        $emitter->emit(new TestStarted($row));
        $emitter->emit(new TestFinished($row, Outcome::Passed, 0.020));

        $emitter->emit(new TestStarted($failing));
        $emitter->emit(new TestFinished($failing, Outcome::Failed, 0.030, new Failure(
            "Values do not match | pipes ['and brackets'] included",
            \LucianoPereira\Crucible\Assert\AssertionFailedError::class,
            expected: "'calm'",
            actual: "'boom'",
        )));

        $emitter->emit(new TestStarted($skipped));
        $emitter->emit(new TestFinished($skipped, Outcome::Skipped, 0.0, reason: 'Redis is not available — see #7.'));

        $emitter->emit(new RunFinished(
            new RunSummary(passed: 2, failed: 1, skipped: 1),
            0.060,
        ));

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * Runs the representative fixture (same events as {@see output()})
     * through a real `JUnitSubscriber`, resolved from a registry seeded
     * with just that key — mirroring how a real `crucible.php` + CLI
     * selection resolves down to one subscriber. Writes to a real temp
     * file (the subscriber opens and closes its own stream, unlike
     * `JUnitXmlWriter` which takes an already-open one), read back
     * after the run.
     *
     * @param class-string $subscriberClass
     */
    private function subscriberOutput(string $key, string $subscriberClass): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-report-');
        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $registry   = new SubscriberRegistry([$key => ['class' => $subscriberClass, 'params' => ['output' => $path]]]);
        $subscriber = $registry->resolve($key);

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe($subscriber);

        $passing = new TestId('tests/unit/SchedulerTest.php', 'testRunsFastestFirst');
        $row     = new TestId('tests/unit/SchedulerTest.php', 'testShuffles', 'seed one');
        $failing = new TestId('tests/unit/CacheTest.php', 'testRoundTrips');
        $skipped = new TestId('tests/unit/CacheTest.php', 'testNeedsRedis');

        $emitter->emit(new RunStarted());

        $emitter->emit(new TestStarted($passing));
        $emitter->emit(new TestFinished($passing, Outcome::Passed, 0.010));
        $emitter->emit(new TestStarted($row));
        $emitter->emit(new TestFinished($row, Outcome::Passed, 0.020));

        $emitter->emit(new TestStarted($failing));
        $emitter->emit(new TestFinished($failing, Outcome::Failed, 0.030, new Failure(
            "Values do not match | pipes ['and brackets'] included",
            \LucianoPereira\Crucible\Assert\AssertionFailedError::class,
            expected: "'calm'",
            actual: "'boom'",
        )));

        $emitter->emit(new TestStarted($skipped));
        $emitter->emit(new TestFinished($skipped, Outcome::Skipped, 0.0, reason: 'Redis is not available — see #7.'));

        $emitter->emit(new RunFinished(
            new RunSummary(passed: 2, failed: 1, skipped: 1),
            0.060,
        ));

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    public function testConsoleLiftsBlockedSkipsIntoAQuietUntestedSection(): void
    {
        $output = $this->untestedOutput(static fn($stream): ConsoleReporter => new ConsoleReporter($stream));

        // The blocked skips live under Untested, their shared reason
        // stated once, the two tests it stopped listed beneath.
        $this->assertStringContainsString("\nUntested\n", $output);
        $this->assertStringContainsString('• Xdebug collects', $output);
        $this->assertStringContainsString('• Xdebug branches', $output);
        $this->assertSame(1, substr_count($output, 'PHP extension xdebug is missing.'));

        // The strip splits the two skip figures — one deliberate skip,
        // two untested — so "N skipped" never reads as a defect.
        $this->assertStringContainsString('Skipped: 1, Untested: 2', $output);

        // The deliberate skip is the only numbered problem; the blocked
        // ones never enter that list.
        $this->assertStringContainsString('not ready yet', $output);
        $this->assertStringContainsString('1) tests/unit/MathTest.php::testNotReady', $output);
        $this->assertStringNotContainsString('2)', $output);
    }

    public function testConsoleColorsOutputOnlyWhenEnabled(): void
    {
        $plain   = $this->output(static fn($stream): ConsoleReporter => new ConsoleReporter($stream));
        $colored = $this->output(static fn($stream): ConsoleReporter => new ConsoleReporter($stream, true));

        // The failing test's 'F' marker, painted red — proves the
        // bool `colors` param (post-Progress-View-migration, replacing
        // a directly-passed Style object) still reaches Style the
        // same way.
        $this->assertStringNotContainsString("\033[31mF\033[0m", $plain);
        $this->assertStringContainsString("\033[31mF\033[0m", $colored);
    }

    public function testMarkdownSplitsUntestedFromSkippedAndProblems(): void
    {
        $output = $this->genericUntestedOutput('markdown', MarkdownReportFormat::class);

        // Its own section, after Problems, reason stated once.
        $this->assertStringContainsString('## Untested', $output);
        $this->assertStringContainsString('**PHP extension xdebug is missing.**', $output);
        $this->assertSame(1, substr_count($output, '**PHP extension xdebug is missing.**'));
        $this->assertStringContainsString('- Xdebug collects', $output);

        // The deliberate skip is a Problem; the blocked ones never are.
        $this->assertStringContainsString('## Problems', $output);
        $this->assertStringContainsString('not ready yet', $output);
        $this->assertStringNotContainsString('### `tests/unit/CoverageTest.php::testXdebugCollects`', $output);
    }

    public function testTestDoxMarksUntestedDistinctlyAndSplitsTheTally(): void
    {
        $output = $this->untestedOutput(static fn($stream): TestDoxReporter => new TestDoxReporter($stream));

        // The blocked skips carry the untested mark, not the skip mark.
        $this->assertStringContainsString('⊘ Xdebug collects', $output);
        $this->assertStringContainsString('↩ Not ready', $output);
        $this->assertStringContainsString('Skipped: 1, Untested: 2', $output);
    }

    public function testTestDoxRendersPrettifiedSectionsAndMarks(): void
    {
        $output = $this->output(static fn($stream): TestDoxReporter => new TestDoxReporter($stream));

        $this->assertStringContainsString("Scheduler (tests/unit/SchedulerTest.php)\n", $output);
        $this->assertStringContainsString(' ✔ Runs fastest first', $output);
        $this->assertStringContainsString(' ✔ Shuffles with data set "seed one"', $output);
        $this->assertStringContainsString(' ✘ Round trips', $output);
        $this->assertStringContainsString(' ↩ Needs redis', $output);
        $this->assertStringContainsString('Tests: 4. Passed: 2, Failed: 1', $output);
    }

    public function testTeamCityEscapesTheServiceMessageCharacters(): void
    {
        $output = $this->output(static fn($stream): TeamCityReporter => new TeamCityReporter($stream));

        $this->assertStringContainsString("##teamcity[testStarted name='testRunsFastestFirst'", $output);
        $this->assertStringContainsString('testFailed', $output);

        // The failure message's |, [, ] and ' all arrive escaped.
        $this->assertStringContainsString("Values do not match || pipes |[|'and brackets|'|] included", $output);
        $this->assertStringContainsString("type='comparisonFailure'", $output);
        $this->assertStringContainsString("expected='|'calm|''", $output);
        $this->assertStringContainsString("##teamcity[testIgnored name='testNeedsRedis' message='Redis is not available — see #7.'", $output);
        $this->assertStringContainsString("duration='30'", $output);

        // Tests are nested under the file's suite and every message
        // carries a flow id, which is what lets a reader group them and
        // keep two parallel runs apart (probe 24 compares the whole
        // vocabulary against the incumbent's).
        $this->assertStringContainsString("##teamcity[testSuiteStarted name='SchedulerTest'", $output);
        $this->assertStringContainsString("##teamcity[testSuiteFinished name='SchedulerTest'", $output);
        $this->assertStringContainsString("locationHint='php_qn://tests/unit/SchedulerTest.php", $output);
        $this->assertMatchesRegularExpression("/flowId='\\d+'/", $output);
    }

    public function testJUnitReportIsWellFormedWithSpecGranularity(): void
    {
        $output = $this->subscriberOutput('junit', JUnitSubscriber::class);

        $xml = simplexml_load_string($output);

        if ($xml === false) {
            self::fail('The JUnit report is not well-formed XML.');
        }

        // The run's own suite wraps one suite per file, which is the
        // shape the incumbent emits and CI readers drill through.
        $suites = $xml->xpath('//testsuite[@file]') ?? [];
        $this->assertCount(2, $suites);

        $this->assertSame('tests/unit/SchedulerTest.php', (string) $suites[0]['file']);
        $this->assertSame('2', (string) $suites[0]['tests']);
        $this->assertSame('0', (string) $suites[0]['failures']);

        $this->assertSame('1', (string) $suites[1]['failures']);
        $this->assertSame('1', (string) $suites[1]['skipped']);

        $run = $xml->xpath('//testsuites/testsuite') ?? [];
        $this->assertCount(1, $run);
        $this->assertSame('4', (string) $run[0]['tests']);

        $failing = $xml->xpath('//testcase[failure]') ?? [];
        $this->assertCount(1, $failing);
        $this->assertSame('tests.unit.CacheTest', (string) $failing[0]['classname']);

        $dataset = $xml->xpath('//testcase[@name=\'testShuffles with data set "seed one"\']') ?? [];
        $this->assertCount(1, $dataset);
    }

    public function testJUnitCarriesSurefireFlakyAndRerunMarkup(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-report-');
        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $registry = new SubscriberRegistry(['junit' => ['class' => JUnitSubscriber::class, 'params' => ['output' => $path]]]);

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-15T12:00:00+00:00')));
        $emitter->subscribe($registry->resolve('junit'));

        $flaky    = new TestId('tests/unit/FlakyTest.php', 'testSettles');
        $stubborn = new TestId('tests/unit/FlakyTest.php', 'testNeverSettles');

        $emitter->emit(new RunStarted());

        $emitter->emit(new TestStarted($flaky));
        $emitter->emit(new TestFinished($flaky, Outcome::Passed, 0.010, attempt: 2, retried: [
            new Failure('first attempt failed', \LucianoPereira\Crucible\Assert\AssertionFailedError::class),
        ]));

        $emitter->emit(new TestStarted($stubborn));
        $emitter->emit(new TestFinished($stubborn, Outcome::Failed, 0.020, new Failure('still failing'), attempt: 3, retried: [
            new Failure('attempt one'),
            new Failure('attempt two'),
        ]));

        $emitter->emit(new RunFinished(new RunSummary(passed: 1, failed: 1), 0.030));

        $content = (string) file_get_contents($path);
        @unlink($path);

        $xml = simplexml_load_string($content);

        if ($xml === false) {
            self::fail('The JUnit report is not well-formed XML.');
        }

        // A pass that needed retries: a plain pass to consumers that
        // only read outcomes, flakyFailure markup for the ones that
        // track flakiness.
        $flakyMarkup = $xml->xpath('//testcase[@name="testSettles"]/flakyFailure') ?? [];
        $this->assertCount(1, $flakyMarkup);
        $this->assertSame('first attempt failed', (string) $flakyMarkup[0]['message']);

        // A failure that was retried: the final <failure> plus one
        // rerunFailure per earlier attempt.
        $this->assertCount(1, $xml->xpath('//testcase[@name="testNeverSettles"]/failure') ?? []);
        $this->assertCount(2, $xml->xpath('//testcase[@name="testNeverSettles"]/rerunFailure') ?? []);
    }

    public function testMarkdownReportCarriesSummaryTableProblemsAndEscapedCells(): void
    {
        $output = $this->genericOutput('markdown', MarkdownReportFormat::class);

        $this->assertStringContainsString('# Test report', $output);
        // The verdict is 3-way (OK/FLAKY/FAILED), matching Console/Pdf — not
        // the 2-way OK/Not-OK Markdown alone used to show (D-091's addendum).
        $this->assertStringContainsString('**FAILED**', $output);

        // Markdown's own text equivalent of Pdf's colored proportion
        // strip — the one block type Markdown skipped in D-091's
        // addendum for lacking "a text equivalent that reads well" is
        // just a percentage line once it's actually written.
        $this->assertStringContainsString('Passed 50% · Skipped 25% · Failed 25%', $output);

        $this->assertStringContainsString('### `tests/unit/CacheTest.php::testRoundTrips` — fail', $output);

        // Pipes in the failure message live inside a code fence.
        $this->assertStringContainsString("```\nValues do not match | pipes ['and brackets'] included\n```", $output);

        // Slowest tests (also newly added): descending by duration,
        // the 0.0-duration skip excluded by RankedList::build()'s own
        // floor — the LIST is asserted as one contiguous block so the
        // skip couldn't be interleaved in. The heading is asserted
        // separately because the section also carries the timing notice
        // when the run was measured under a call-hooking xdebug, and a
        // report that reads the same under coverage and without it
        // would be claiming more than it measured.
        $this->assertStringContainsString('## Slowest tests', $output);
        $this->assertStringContainsString(
            "1. Round trips · Cache — 0.030s\n"
            . "2. Shuffles with data set \"seed one\" · Scheduler — 0.020s\n"
            . "3. Runs fastest first · Scheduler — 0.010s\n",
            $output,
        );

        // Results is the shared folded directory tree (D-091's
        // addendum) — organized by directory now, but every
        // individual test still gets its own row: Markdown lost
        // nothing it had before, it gained directory grouping on top.
        $this->assertStringContainsString('## Results', $output);
        $this->assertStringContainsString('#### tests/unit', $output);
        $this->assertStringContainsString('##### Scheduler', $output);
        $this->assertStringContainsString('| Runs fastest first | ✅ pass | 0.010s |', $output);
        $this->assertStringContainsString('| Shuffles with data set "seed one" | ✅ pass | 0.020s |', $output);
    }

    public function testTheSlowestSectionStatesWhenTheProfilerIsWhatItRanked(): void
    {
        $model = new RunModel([
            new TestFinished(new TestId('tests/unit/CacheTest.php', 'testRoundTrips'), Outcome::Passed, 0.030),
        ]);

        $event   = new RunFinished(new RunSummary(passed: 1), 0.030);
        $context = new ReportContext(
            title: 'Test report',
            author: Version::AUTHOR,
            producer: 'Crucible ' . Version::NUMBER,
            createdAt: null,
            runtime: 0.030,
        );

        $format = new MarkdownReportFormat();

        // Injected, never read from this process's ini: a persisted
        // report that changes with the renderer's environment is not a
        // report. The contiguous-block assertion above caught exactly
        // that when this was a global read inside the builder.
        $noticed = $format->render(
            RunReportDocument::build($model, $event, false, 'Test report', 'these durations are inflated'),
            $context,
            [],
        );

        $this->assertStringContainsString("## Slowest tests\n\nthese durations are inflated\n", $noticed);

        // With nothing to say it says nothing — the default, and what
        // --reproducible passes so the bytes stay stable across machines.
        $plain = $format->render(
            RunReportDocument::build($model, $event, false, 'Test report'),
            $context,
            [],
        );

        $this->assertStringNotContainsString('inflated', $plain);
    }

    public function testPdfReportIsWellFormedAndCarriesTheRunContent(): void
    {
        $output = $this->genericOutput('pdf', PdfReportFormat::class);

        // The file skeleton: header, trailer, and a cross-reference
        // table whose startxref offset actually lands on it.
        $this->assertStringStartsWith("%PDF-1.4\n", $output);
        $this->assertStringEndsWith("%%EOF\n", $output);
        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $output, $anchor));
        $this->assertSame('xref', substr($output, (int) $anchor[1], 4));

        // Every object the trailer announces has an xref entry whose
        // offset lands on that object's "N 0 obj" head.
        $this->assertSame(1, preg_match('/\/Size (\d+)/', $output, $size));
        $this->assertSame(1, preg_match_all('/^xref\n0 (\d+)\n/m', $output, $entries));
        $this->assertSame($size[1], $entries[1][0]);

        preg_match_all('/^(\d{10}) 00000 n \n/m', $output, $offsets);
        $this->assertCount((int) $size[1] - 1, $offsets[1]);

        foreach ($offsets[1] as $index => $offset) {
            $this->assertStringStartsWith(($index + 1) . ' 0 obj', substr($output, (int) $offset, 16));
        }

        // Streams are uncompressed, so the run's content is right
        // there. The verdict is a FAILED badge (red box, white bold
        // text), then title, byline, summary strip, and proportion
        // bar (D-091's addendum: Badge/ProportionBar/Problems/
        // Untested/Passed now build the same Document model
        // Console/Markdown/TestDox share).
        $this->assertStringContainsString('(Test report) Tj', $output);
        $this->assertStringContainsString('(FAILED) Tj', $output);
        $this->assertMatchesRegularExpression('/0\.78 0\.16 0\.16 rg [\d. ]+ re f\nBT 1\.00 1\.00 1\.00 rg \/F2 11\.00 Tf/', $output);
        $this->assertStringContainsString("(4 tests \xB7 2 passed \xB7 2 flagged \xB7 0.060s) Tj", $output);
        $this->assertStringContainsString('0.18 0.49 0.20 rg', $output); // the bar's pass segment, #2e7d32

        // Branding text in the body, plus the run date in the PDF's own
        // document metadata (/CreationDate) — off the event stream (the
        // frozen clock), never the wall clock, so bytes stay stable.
        $this->assertStringContainsString('(Crucible ' . Version::NUMBER . ' by ' . Version::AUTHOR . ') Tj', $output);
        $this->assertStringContainsString('/Producer (Crucible ' . Version::NUMBER . ')', $output);
        $this->assertStringContainsString('/Author (' . Version::AUTHOR . ')', $output);
        $this->assertStringContainsString("/CreationDate (D:20260714120000+00'00')", $output);
        $this->assertStringContainsString('/Title (Test report)', $output);

        // Problems group by severity with counts; empty groups are
        // absent. Entries carry the same plain `file::test [outcome]`
        // form Console/Markdown use (no per-format prettifying, D-091's
        // addendum); failure/reason text follows in gray Courier.
        $this->assertStringContainsString("(Failures \\(1\\)) Tj", $output);
        $this->assertStringContainsString("(Skipped \\(1\\)) Tj", $output);
        $this->assertStringNotContainsString('(Errors', $output);
        $this->assertStringNotContainsString('(Incomplete', $output);
        $this->assertStringContainsString('(tests/unit/CacheTest.php::testRoundTrips [fail]) Tj', $output);
        $this->assertStringContainsString("(Values do not match | pipes ['and brackets'] included) Tj", $output);
        $this->assertStringContainsString("(Redis is not available \x97 see #7.) Tj", $output);

        // The slowest list: one unit picked by the largest entry.
        $this->assertStringContainsString('(Slowest tests) Tj', $output);
        $this->assertStringContainsString("(Round trips \xB7 Cache) Tj", $output);
        $this->assertStringContainsString('(10.0ms) Tj', $output);

        // The passed section is the shared folded directory tree
        // (FoldingTree/TileGrid, D-091's addendum): the collapsed
        // directory row, and uniform file tiles — the flagged file is
        // just a tile with a red dot (the Bézier `c` ops), nothing more.
        $this->assertStringContainsString('(Results) Tj', $output);
        $this->assertStringContainsString('(tests/unit) Tj', $output);  // the tree row: no trailing slash
        $this->assertStringContainsString("(\xB7 2 tests \xB7 0.060s) Tj", $output); // the directory's inline meta: own passed count · time
        $this->assertStringContainsString('(Scheduler) Tj', $output);
        $this->assertStringContainsString('(Cache) Tj', $output);

        // Tiles carry name + passed count only — both files sit on
        // the slowest list, so the 2% "worth calling out" exception
        // must NOT fire (the spotlight suppression this test caught
        // failing to key match before the fix, D-091's addendum).
        $this->assertStringNotContainsString('0.030s) Tj', $output);
        $this->assertStringNotContainsString('(SchedulerTest.php) Tj', $output);
        $this->assertStringNotContainsString('(Runs fastest first) Tj', $output);
        $this->assertMatchesRegularExpression('/0\.78 0\.16 0\.16 rg [\d. ]+ m( [\d. ]+ c){4} f/', $output);

        // No page cross-references, and never an ellipsis.
        $this->assertStringNotContainsString('(p. ', $output);
        $this->assertSame(0, substr_count($output, "\x85"));

        // Base-14 fonts only — nothing embedded.
        $this->assertStringContainsString('/BaseFont /Helvetica', $output);
        $this->assertStringNotContainsString('/FontFile', $output);

        // Same events, byte-identical document.
        $this->assertSame($output, $this->genericOutput('pdf', PdfReportFormat::class));
    }

    public function testPdfReportTitleIsConfigurable(): void
    {
        $output = $this->genericOutput('pdf', PdfReportFormat::class, 'Example Project — release 4.2');

        $this->assertStringContainsString("(Example Project \x97 release 4.2) Tj", $output);
        $this->assertStringContainsString("/Title (Example Project \x97 release 4.2)", $output);
        $this->assertStringNotContainsString('(Test report) Tj', $output);
    }

    public function testPdfVerdictIsFlakyOnlyOnPassAfterRetry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-report-');
        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $registry = new ReportFormatRegistry(['pdf' => ['class' => PdfReportFormat::class, 'params' => ['output' => $path]]]);
        $emitter  = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-17T12:00:00+00:00')));
        $emitter->subscribe(new GenericReportWriter($registry, ['pdf']));

        $settles = new TestId('tests/unit/FlakyTest.php', 'testSettles');

        $emitter->emit(new RunStarted());
        $emitter->emit(new TestStarted($settles));
        $emitter->emit(new TestFinished($settles, Outcome::Passed, 0.010, attempt: 2, retried: [
            new Failure('first attempt failed'),
        ]));
        $emitter->emit(new RunFinished(new RunSummary(passed: 1), 0.010));

        $output = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringContainsString('(FLAKY) Tj', $output);
        $this->assertStringContainsString('0.70 0.42 0.00 rg', $output); // the amber badge, #b26a00
        $this->assertStringNotContainsString('(OK) Tj', $output);
    }

    public function testPdfGroupsDirectoriesOnceAndElidesFlagOnlyFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-report-');
        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $registry = new ReportFormatRegistry(['pdf' => ['class' => PdfReportFormat::class, 'params' => ['output' => $path]]]);
        $emitter  = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-17T12:00:00+00:00')));
        $emitter->subscribe(new GenericReportWriter($registry, ['pdf']));

        // Discovery interleaves a subdirectory between two files of
        // the parent directory.
        $quiet  = new TestId('suite/a/AlphaTest.php', 'testQuietNeighbor');
        $waits  = new TestId('suite/a/AlphaTest.php', 'testWaits');
        $deep   = new TestId('suite/a/deep/BetaTest.php', 'testDeep');
        $after  = new TestId('suite/a/GammaTest.php', 'testAfterTheSubdirectory');
        $breaks = new TestId('suite/a/DeltaTest.php', 'testBreaks');
        $twice1 = new TestId('suite/a/EpsilonTest.php', 'testWaitsToo');
        $twice2 = new TestId('suite/a/EpsilonTest.php', 'testAlsoWaits');
        $tiny   = new TestId('other/x/TinyTest.php', 'testTiny');
        $small  = new TestId('misc/y/SmallTest.php', 'testSmall');
        $many1  = new TestId('suite/a/OmegaTest.php', 'testManyOne');
        $many2  = new TestId('suite/a/OmegaTest.php', 'testManyTwo');
        $many3  = new TestId('suite/a/OmegaTest.php', 'testManyThree');

        $emitter->emit(new RunStarted());

        foreach ([[$quiet, Outcome::Passed, 0.020], [$waits, Outcome::Skipped, 0.0], [$deep, Outcome::Passed, 0.010], [$after, Outcome::Passed, 0.005], [$breaks, Outcome::Failed, 0.001], [$twice1, Outcome::Skipped, 0.0], [$twice2, Outcome::Skipped, 0.0], [$tiny, Outcome::Passed, 0.000001], [$small, Outcome::Passed, 0.000002], [$many1, Outcome::Passed, 0.0008], [$many2, Outcome::Passed, 0.0008], [$many3, Outcome::Passed, 0.0008]] as [$id, $outcome, $duration]) {
            $emitter->emit(new TestStarted($id));
            $emitter->emit(new TestFinished(
                $id,
                $outcome,
                $duration,
                $outcome === Outcome::Failed ? new Failure('broken') : null,
                reason: $outcome === Outcome::Skipped ? 'later' : null,
            ));
        }

        $emitter->emit(new RunFinished(new RunSummary(passed: 8, failed: 1, skipped: 3), 0.036));

        $output = (string) file_get_contents($path);
        @unlink($path);

        // The collapsed chain renders exactly ONE tree row (bold —
        // problem entries repeat the path in gray body text, which
        // is theirs to do), and the subdirectory nests as a child
        // with only its remainder — interleaved discovery order must
        // not split the parent.
        $this->assertSame(1, preg_match_all('/\/F2 10\.00 Tf [\d. ]+ [\d. ]+ Td \(suite\/a\) Tj/', $output));
        $this->assertSame(1, preg_match_all('/\/F2 10\.00 Tf [\d. ]+ [\d. ]+ Td \(deep\) Tj/', $output));
        $this->assertStringNotContainsString('(suite/a/deep) Tj', $output);

        // Problems order by severity: Failures before Skipped, the
        // counts on the headings summing to the strip's flagged.
        $this->assertStringContainsString("(Failures \\(1\\)) Tj", $output);
        $this->assertStringContainsString("(Skipped \\(3\\)) Tj", $output);
        $failures = (int) strpos($output, "(Failures \\(1\\)) Tj");
        $skips    = (int) strpos($output, "(Skipped \\(3\\)) Tj");
        $this->assertGreaterThan(0, $failures);
        $this->assertLessThan($skips, $failures);
        // Problems entries are the plain `file::test [outcome]` form
        // Console/Markdown use — no per-format prettifying (D-091's
        // addendum).
        $this->assertStringContainsString('(suite/a/AlphaTest.php::testWaits [skip]) Tj', $output);

        // The passed tree: tiles are name + passed count only — no
        // per-file times — and no per-test rows exist, not even for
        // the flagged files; their detail lives in Problems only.
        // The single-file subdirectory inlines to one line with its
        // time at the shared right edge. The one exception: Omega
        // exceeds 2% of the runtime yet none of its tests reached
        // the slowest list, so its tile alone appends the time.
        $this->assertStringContainsString("(\xB7 Beta) Tj", $output);
        $this->assertStringContainsString("(\xB7 1 test \xB7 0.010s) Tj", $output);   // deep's inline meta, singular
        $this->assertStringContainsString("(\xB7 5 tests \xB7 0.038s) Tj", $output);  // suite/a: OWN passed files only, subtree time
        $this->assertStringContainsString("(3 \xB7 0.002s) Tj", $output);             // the 2%-and-unlisted exception
        $this->assertStringNotContainsString('(1 \xB7 0.020s) Tj', $output); // Alpha's already spotlighted — no repeated time
        $this->assertStringNotContainsString('(Quiet neighbor) Tj', $output);
        $alpha = (int) strpos($output, '(Alpha) Tj');
        $gamma = (int) strpos($output, '(Gamma) Tj');
        $delta = (int) strpos($output, '(Delta) Tj');
        $this->assertGreaterThan(0, $alpha);
        $this->assertLessThan($gamma, $alpha);
        $this->assertLessThan($delta, $gamma);

        // The sub-threshold tail folds into one line; its counts keep
        // the passed total honest, and the folded files never render.
        // (Plain `%.3fs` formatting throughout, D-091's addendum —
        // this specific fixture's folded tail is fast enough that it
        // now rounds to 0.000s rather than the old adaptive "3µs";
        // an accepted precision trade for one consistent format.)
        $this->assertStringContainsString("(+ 2 more directories \xB7 2 tests \xB7 0.000s) Tj", $output);
        $this->assertStringNotContainsString('(Tiny) Tj', $output);
        $this->assertStringNotContainsString('(Small) Tj', $output);

        // No ellipsis anywhere — the elided form is gone with the
        // per-test rows.
        $this->assertSame(0, substr_count($output, "\x85"));
    }

    // -- --profile (D-087) ---------------------------------------------------

    public function testTheProfileListsTheSlowestTestsFirst(): void
    {
        $output = $this->output(static fn($stream): Listener => new ProfileReporter($stream, new Style(false)));

        self::assertStringContainsString('Slowest 4 of 4 test(s)', $output);

        // Slowest first is the whole point: 0.030 > 0.020 > 0.010 > 0.0.
        $slowest = strpos($output, 'testRoundTrips');
        $middle  = strpos($output, 'testShuffles');
        $fastest = strpos($output, 'testRunsFastestFirst');

        self::assertIsInt($slowest);
        self::assertIsInt($middle);
        self::assertIsInt($fastest);
        self::assertLessThan($middle, $slowest, 'The slowest test must be listed first.');
        self::assertLessThan($fastest, $middle);
    }

    public function testTheProfileShowsEachTestsShareOfTheRun(): void
    {
        $output = $this->output(static fn($stream): Listener => new ProfileReporter($stream, new Style(false)));

        // 0.030 of 0.060 total — the share is what makes a duration
        // actionable, so it is not left to the reader to divide.
        self::assertStringContainsString('50.0%', $output);
    }

    public function testTheProfileHonoursItsLimit(): void
    {
        $output = $this->output(static fn($stream): Listener => new ProfileReporter($stream, new Style(false), 2));

        self::assertStringContainsString('Slowest 2 of 4 test(s)', $output);
        self::assertStringContainsString('testRoundTrips', $output);
        self::assertStringNotContainsString('testRunsFastestFirst', $output, 'Beyond the limit must be omitted.');
    }

    public function testAProfileOfNothingPrintsNothing(): void
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            self::fail('Cannot open the in-memory stream.');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-22T12:00:00+00:00')));
        $emitter->subscribe(new ProfileReporter($stream, new Style(false)));

        $emitter->emit(new RunStarted());
        $emitter->emit(new RunFinished(new RunSummary(), 0.0));

        rewind($stream);

        self::assertSame('', (string) stream_get_contents($stream), 'An empty run has nothing to profile.');
    }
}
