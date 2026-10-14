<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\PhpWriter;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Reporting\Document\RunReportDocument;
use LucianoPereira\Crucible\Reporting\GenericReportWriter;
use LucianoPereira\Crucible\Reporting\ReportFormat\Formats\{JsonReportFormat, MarkdownReportFormat, PdfReportFormat, SarifReportFormat};
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormatRegistry;
use LucianoPereira\Crucible\Test\TestId;

use function array_map;
use function file_get_contents;
use function getenv;
use function is_string;
use function json_decode;
use function putenv;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * `--reproducible` exists so `cmp` on two reports answers the only
 * question CI asks: did BEHAVIOUR change? Without it the answer is
 * always yes, because a report restates when it ran and how long it
 * took, and those move even when nothing else did.
 *
 * Two kinds of volatility, two mechanisms — which is why this is not
 * one switch in one place. Timestamps already ride the injectable
 * Clock, so freezing it settles them everywhere at once. Durations
 * deliberately do NOT come from that clock (they are monotonic, taken
 * at the call site), so the report boundary has to normalise them.
 */
#[CoversClass(CliOptions::class)]
#[CoversClass(GenericReportWriter::class)]
#[CoversClass(PhpWriter::class)]
#[CoversClass(RunReportDocument::class)]
#[CoversClass(FoldingTree::class)]
final class ReproducibleOutputTest extends TestCase
{
    public function testTheFlagIsOffUntilAskedFor(): void
    {
        self::assertFalse($this->parse([])->reproducible);
        self::assertTrue($this->parse(['--reproducible'])->reproducible);
    }

    public function testAFrozenClockSettlesTheTimestampAWriterWouldOtherwiseRead(): void
    {
        // PhpWriter used to construct its own DateTimeImmutable, which
        // no configuration could reach. Passing the instant in is what
        // makes it answerable to the run's clock like everything else.
        $data   = new CoverageData(lines: ['/app/src/Thing.php' => [1 => 1]]);
        $frozen = new FrozenClock(new DateTimeImmutable('@0'));

        $first  = (new PhpWriter())->write($data, 'xdebug', null, $frozen->now());
        $second = (new PhpWriter())->write($data, 'xdebug', null, $frozen->now());

        self::assertSame($first, $second);
        self::assertStringContainsString('1970', $first);
    }

    public function testAWallClockWriterStillReportsTheRealInstant(): void
    {
        // The normalisation must be opt-in: a default run still says
        // truthfully when it happened.
        $data     = new CoverageData(lines: ['/app/src/Thing.php' => [1 => 1]]);
        $rendered = (new PhpWriter())->write($data, 'xdebug', null, (new SystemClock())->now());

        self::assertStringNotContainsString('1970', $rendered);
    }

    /**
     * The report formats under the flag: the same outcomes reached with
     * different durations and in a different arrival order, as two
     * parallel runs reach them, write the same bytes.
     */
    public function testTwoRunsWithDifferentTimingWriteIdenticalReports(): void
    {
        foreach (['markdown' => MarkdownReportFormat::class, 'pdf' => PdfReportFormat::class, 'json' => JsonReportFormat::class, 'sarif' => SarifReportFormat::class] as $key => $format) {
            self::assertSame(
                $this->report($key, $format, true, $this->run(fast: true)),
                $this->report($key, $format, true, $this->byOtherArrival($this->run(fast: false))),
                $key . ' differs between two runs of the same code',
            );
        }
    }

    public function testTheMarkdownReportCarriesNoTimeUnderTheFlag(): void
    {
        $markdown = $this->report('markdown', MarkdownReportFormat::class, true, $this->run(fast: true));

        self::assertStringNotContainsString('Slowest tests', $markdown);
        self::assertDoesNotMatchRegularExpression('/\d\.\d{3}s/', $markdown);
        self::assertStringContainsString('8 tests · 6 passed · 2 flagged', $markdown);
    }

    public function testTheJsonReportOmitsTheDurationRatherThanZeroingIt(): void
    {
        $json = json_decode($this->report('json', JsonReportFormat::class, true, $this->run(fast: true)), true);

        self::assertIsArray($json);
        self::assertIsArray($json['summary']);
        self::assertArrayNotHasKey('duration', $json['summary']);
        self::assertSame(8, $json['summary']['total']);
    }

    public function testTheSarifReportOmitsTheEndTimeRatherThanClaimingAZeroLengthRun(): void
    {
        $previous = getenv('SOURCE_DATE_EPOCH');
        putenv('SOURCE_DATE_EPOCH=1700000000');

        try {
            $sarif = $this->report('sarif', SarifReportFormat::class, true, $this->run(fast: true));
        } finally {
            putenv($previous === false ? 'SOURCE_DATE_EPOCH' : 'SOURCE_DATE_EPOCH=' . $previous);
        }

        self::assertStringContainsString('"startTimeUtc": "2023-11-14T22:13:20.000Z"', $sarif);
        self::assertStringNotContainsString('endTimeUtc', $sarif);
    }

    public function testWithoutTheFlagEveryReportStillStatesItsTiming(): void
    {
        $markdown = $this->report('markdown', MarkdownReportFormat::class, false, $this->run(fast: true));
        $json     = json_decode($this->report('json', JsonReportFormat::class, false, $this->run(fast: true)), true);
        $sarif    = $this->report('sarif', SarifReportFormat::class, false, $this->run(fast: true));

        self::assertStringContainsString('Slowest tests', $markdown);
        self::assertMatchesRegularExpression('/· \d\.\d{3}s\n/', $markdown);
        self::assertIsArray($json);
        self::assertIsArray($json['summary']);
        self::assertIsFloat($json['summary']['duration']);
        self::assertStringContainsString('endTimeUtc', $sarif);
    }

    /**
     * One run's finished tests: two top-level directories, nested ones,
     * a failure and a skip — enough that time would decide the order
     * and the fold if anything were allowed to.
     *
     * @return list<TestFinished>
     */
    private function run(bool $fast): array
    {
        $time = static fn(float $slow, float $quick): float => $fast ? $quick : $slow;

        return [
            new TestFinished(new TestId('tests/unit/Alpha/OneTest.php', 'testA'), Outcome::Passed, $time(0.120, 0.002)),
            new TestFinished(new TestId('tests/unit/Alpha/OneTest.php', 'testB'), Outcome::Failed, $time(0.030, 0.400), new Failure('nope')),
            new TestFinished(new TestId('tests/unit/Beta/TwoTest.php', 'testC'), Outcome::Passed, $time(0.450, 0.0001)),
            new TestFinished(new TestId('tests/feature/ThreeTest.php', 'testD'), Outcome::Skipped, 0.0, reason: 'later'),
            new TestFinished(new TestId('tests/feature/ThreeTest.php', 'testE'), Outcome::Passed, $time(0.010, 0.300)),
            new TestFinished(new TestId('spec/a/FourTest.php', 'testF'), Outcome::Passed, $time(0.0001, 0.250)),
            new TestFinished(new TestId('spec/b/FiveTest.php', 'testG'), Outcome::Passed, $time(0.0001, 0.003)),
            new TestFinished(new TestId('zz/SixTest.php', 'testH'), Outcome::Passed, $time(0.0001, 0.150)),
        ];
    }

    /**
     * Files arriving in another order, each file's tests still in its own order, as workers deliver them.
     *
     * @param list<TestFinished> $finished
     *
     * @return list<TestFinished>
     */
    private function byOtherArrival(array $finished): array
    {
        return array_map(static fn(int $index): TestFinished => $finished[$index], [7, 5, 3, 4, 6, 2, 0, 1]);
    }

    /**
     * @param class-string       $format
     * @param list<TestFinished> $finished
     */
    private function report(string $key, string $format, bool $reproducible, array $finished): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-reproducible-');

        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $registry = new ReportFormatRegistry([$key => ['class' => $format, 'params' => ['output' => $path]]]);
        $emitter  = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe(new GenericReportWriter($registry, [$key], reproducible: $reproducible));

        $emitter->emit(new RunStarted());

        foreach ($finished as $event) {
            $emitter->emit($event);
        }

        $total = 0.0;

        foreach ($finished as $event) {
            $total += $event->duration;
        }

        $emitter->emit(new RunFinished(new RunSummary(passed: 6, failed: 1, skipped: 1), $total));

        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): CliOptions
    {
        $options = CliOptions::fromArgv(['crucible', ...$argv]);

        if (is_string($options)) {
            self::fail('Unexpected parse error: ' . $options);
        }

        return $options;
    }
}
