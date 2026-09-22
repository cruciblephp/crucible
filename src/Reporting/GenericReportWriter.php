<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use DateTimeImmutable;
use LucianoPereira\Crucible\Clock\SourceDateEpoch;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Reporting\Document\RunReportDocument;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormatRegistry};
use LucianoPereira\Crucible\Runner\TimingOverhead;
use LucianoPereira\Crucible\Version;

use function fclose;
use function fopen;
use function fwrite;
use function is_string;
use function sprintf;

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

    /**
     * @param  list<string>  $keys  which registered format keys to render this run
     * @param  ?non-empty-string  $title  the configured report title; null = "Test report"
     */
    public function __construct(
        private readonly ReportFormatRegistry $registry,
        private readonly array $keys,
        private readonly ?string $title = null,
        /**
         * Two things this settles that freezing the clock cannot.
         *
         * Durations are deliberately NOT the Clock's business — they are
         * monotonic, taken at the call site — so they are zeroed here.
         *
         * And the generation time is *omitted* rather than faked when the
         * environment names no `SOURCE_DATE_EPOCH`. Both formats mark the
         * field optional, and a report that says nothing about when it was
         * made is honest where one claiming 1970 is not: a placeholder a
         * consumer cannot recognise as a placeholder is just wrong data.
         */
        private readonly bool $reproducible = false,
    ) {}

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
        $model = new RunModel($this->finished);
        // A reproducible report is byte-stable across machines, so it
        // carries no note about this machine's profiler (D-041 kin).
        $document = RunReportDocument::build(
            $model,
            $event,
            $this->flaky,
            $title,
            $this->reproducible ? null : TimingOverhead::notice(),
        );

        $context = new ReportContext(
            title: $title,
            author: Version::AUTHOR,
            producer: sprintf('Crucible %s', Version::NUMBER),
            createdAt: $this->reproducible ? SourceDateEpoch::read() : $this->started,
            runtime: $this->reproducible ? 0.0 : $event->duration,
        );

        foreach ($this->keys as $key) {
            $resolved = $this->registry->resolve($key);
            $rendered = $resolved['instance']->render($document, $context, $resolved['params']);

            $output = $resolved['params']['output'] ?? null;

            if (!is_string($output)) {
                throw new ConfigurationException(sprintf('Report format "%s" has no "output" param — nowhere to write its output.', $key));
            }

            $stream = fopen($output, 'w');

            if ($stream === false) {
                throw new ConfigurationException(sprintf('Cannot open "%s" for the "%s" report.', $output, $key));
            }

            fwrite($stream, $rendered);
            fclose($stream);
        }
    }
}
