<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Reporting;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\{Emitter, RunFinished, RunStarted, RunSummary};
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\OutputFile;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\GenericReportWriter;
use LucianoPereira\Crucible\Reporting\ReportFormat\Formats\MarkdownReportFormat;
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormatRegistry;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

/**
 * Where a report goes (D-148). 1.1.5 opened the file only at
 * `run:finish` and threw out of the event stream when it could not: a
 * missing directory ended the run in an uncaught fatal, exit 255, after
 * every test had run.
 */
#[CoversClass(GenericReportWriter::class)]
#[CoversClass(OutputFile::class)]
final class GenericReportWriterTest extends TestCase
{
    public function testAMissingDirectoryIsCreatedBeforeTheRun(): void
    {
        $root = sys_get_temp_dir() . '/' . uniqid('crucible-report-', true);

        new GenericReportWriter($this->registry($root . '/reports/run.md'), ['markdown']);

        $this->assertTrue(is_dir($root . '/reports'));

        rmdir($root . '/reports');
        rmdir($root);
    }

    public function testADirectoryThatCannotBeCreatedIsAConfigurationErrorBeforeTheRun(): void
    {
        // A regular file where a directory is needed: no user can create it.
        $blocker = tempnam(sys_get_temp_dir(), 'crucible-blocker-');
        self::assertNotFalse($blocker);
        file_put_contents($blocker, '');

        try {
            new GenericReportWriter($this->registry($blocker . '/run.md'), ['markdown']);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            $this->assertSame('Cannot create the directory of "' . $blocker . '/run.md" for the "markdown" report.', $e->getMessage());
        } finally {
            unlink($blocker);
        }
    }

    public function testAReportThatCannotBeOpenedIsSaidAfterTheRunAndNotThrown(): void
    {
        // A directory where the file should be: it exists, and cannot be opened for writing.
        $directory = sys_get_temp_dir() . '/' . uniqid('crucible-report-', true);
        mkdir($directory);

        $writer  = new GenericReportWriter($this->registry($directory), ['markdown']);
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-10-19T12:00:00+00:00')));
        $emitter->subscribe($writer);

        $emitter->emit(new RunStarted());
        $emitter->emit(new RunFinished(new RunSummary(), 0.0));

        $this->assertSame(['Cannot open "' . $directory . '" for the "markdown" report.'], $writer->unwritten());
        $this->assertFalse(is_file($directory));

        rmdir($directory);
    }

    private function registry(string $output): ReportFormatRegistry
    {
        return new ReportFormatRegistry(['markdown' => ['class' => MarkdownReportFormat::class, 'params' => ['output' => $output]]]);
    }
}
