<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Reporting;

use DateTimeImmutable;
use LucianoPereira\Crucible\Clock\SourceDateEpoch;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\OutputFile;
use LucianoPereira\Crucible\Reporting\Document\RunReportDocument;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormatRegistry};
use LucianoPereira\Crucible\Runner\TimingOverhead;
use LucianoPereira\Crucible\Version;

use function fclose;
use function fopen;
use function fwrite;
use function is_string;
use function sprintf;
use function strcmp;
use function usort;

/**
 * The one `Listener` behind every registered report format — replaces
 * the old `PdfWriter`/`MarkdownWriter` (each its own hardcoded
 * listener, one per format) with a single generic writer driven
 * entirely by {@see ReportFormatRegistry}. Accumulates finished tests
 * exactly as those writers did, builds one {@see RunReportDocument} at
 * `run:finish`, and renders it through every selected format — a
 * third party's own format goes through this identical path, no
 * separate wiring required.
 */
final class GenericReportWriter implements Listener
{
    /** @var list<TestFinished> */
    private array $finished = [];

    private bool $flaky = false;

    /** the first envelope's timestamp — the run date, off the stream so output stays deterministic */
    private ?DateTimeImmutable $started = null;

    /** @var list<string> the reports that could not be written, said after the run's summary */
    private array $unwritten = [];

    /**
     * Every format's output is checked, and its directory created (D-148), here rather
     * than at `run:finish`: a path that cannot be written is a configuration error
     * before the run, not an exception thrown out of the event stream after it.
     *
     * @param  list<string>  $keys  which registered format keys to render this run
     * @param  ?non-empty-string  $title  the configured report title; null = "Test report"
     *
     * @throws ConfigurationException when a format has no output, or its directory cannot be created
     */
    public function __construct(
        private readonly ReportFormatRegistry $registry,
        private readonly array $keys,
        private readonly ?string $title = null,
        /**
         * Two things this settles that freezing the clock cannot.
         *
         * Durations are deliberately NOT the Clock's business — they are
         * monotonic, taken at the call site — so the report omits them and all they decide (D-138).
         *
         * And the generation time is *omitted* rather than faked when the
         * environment names no `SOURCE_DATE_EPOCH`. Both formats mark the
         * field optional, and a report that says nothing about when it was
         * made is honest where one claiming 1970 is not: a placeholder a
         * consumer cannot recognise as a placeholder is just wrong data.
         */
        private readonly bool $reproducible = false,
    ) {
        foreach ($keys as $key) {
            $output = $this->outputOf($key);

            if (!OutputFile::prepareDirectory($output)) {
                throw new ConfigurationException(sprintf('Cannot create the directory of "%s" for the "%s" report.', $output, $key));
            }
        }
    }

    public function handle(Envelope $envelope): void
    {
        $this->started ??= $envelope->timestamp;

        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $this->finished[] = $event;
            $this->flaky      = $this->flaky || $event->flaky();

            return;
        }

        if ($event instanceof RunFinished) {
            $this->render($event);
        }
    }

    private function render(RunFinished $event): void
    {
        $title = $this->title ?? 'Test report';
        $model = new RunModel($this->reproducible ? $this->byFile($this->finished) : $this->finished);
        // A reproducible report is byte-stable across machines, so it
        // carries no note about this machine's profiler (D-041 kin).
        $document = RunReportDocument::build(
            $model,
            $event,
            $this->flaky,
            $title,
            $this->reproducible ? null : TimingOverhead::notice(),
            timed: !$this->reproducible,
        );

        $context = new ReportContext(
            title: $title,
            author: Version::AUTHOR,
            producer: sprintf('Crucible %s', Version::NUMBER),
            createdAt: $this->reproducible ? SourceDateEpoch::read() : $this->started,
            runtime: $event->duration,
        );

        foreach ($this->keys as $key) {
            $resolved = $this->registry->resolve($key);
            $rendered = $resolved['instance']->render($document, $context, $resolved['params']);
            $output   = $this->outputOf($key);
            $stream   = @fopen($output, 'w');

            // The run is over and its result stands; a report that cannot
            // be written is said after the summary, and the others are written.
            if ($stream === false) {
                $this->unwritten[] = sprintf('Cannot open "%s" for the "%s" report.', $output, $key);

                continue;
            }

            fwrite($stream, $rendered);
            fclose($stream);
        }
    }

    /** @return list<string> what could not be written, one sentence each */
    public function unwritten(): array
    {
        return $this->unwritten;
    }

    /** @throws ConfigurationException when the format has no "output" param */
    private function outputOf(string $key): string
    {
        $output = $this->registry->resolve($key)['params']['output'] ?? null;

        if (!is_string($output)) {
            throw new ConfigurationException(sprintf('Report format "%s" has no "output" param — nowhere to write its output.', $key));
        }

        return $output;
    }

    /**
     * Path order, stable: a file's tests keep the order its one worker ran them in.
     *
     * @param  list<TestFinished>  $finished
     * @return list<TestFinished>
     */
    private function byFile(array $finished): array
    {
        usort($finished, static fn(TestFinished $a, TestFinished $b): int => strcmp($a->test->file, $b->test->file));

        return $finished;
    }
}
