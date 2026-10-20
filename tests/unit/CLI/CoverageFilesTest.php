<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\PostRunReport;
use LucianoPereira\Crucible\Coverage\CoverageCollector;
use LucianoPereira\Crucible\Coverage\CoverageDriver;
use LucianoPereira\Crucible\Coverage\CoverageWindow;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_put_contents;
use function is_file;
use function is_string;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Where a coverage file goes (D-148). PHPUnit 13.4.3 creates the missing
 * directories of every coverage path; 1.1.5 wrote nothing there, printed
 * PHP's warning, and then said the file had been written.
 */
#[CoversClass(PostRunReport::class)]
final class CoverageFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . uniqid('crucible-coverage-files-', true);
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }

        rmdir($this->root);
    }

    public function testAMissingDirectoryIsCreated(): void
    {
        $printed = $this->report(['--coverage-clover', 'build/coverage/clover.xml']);

        $this->assertTrue(is_file($this->root . '/build/coverage/clover.xml'));
        $this->assertStringContainsString('Clover coverage written to build/coverage/clover.xml.', $printed);
    }

    public function testAFileThatCannotBeWrittenIsSaidAndNotClaimed(): void
    {
        // A regular file where a directory is needed: no user can create it.
        file_put_contents($this->root . '/blocker', '');

        $printed = $this->report(['--coverage-cobertura', 'blocker/cobertura.xml']);

        $this->assertStringContainsString('Cannot write Cobertura coverage to blocker/cobertura.xml: its directory could not be created.', $printed);
        $this->assertStringNotContainsString('written to', $printed);
    }

    /**
     * @param list<string> $argv
     */
    private function report(array $argv): string
    {
        $options = CliOptions::fromArgv(['crucible', ...$argv]);

        if (is_string($options)) {
            self::fail('Unexpected parse error: ' . $options);
        }

        $driver = new readonly class implements CoverageDriver {
            public function name(): string
            {
                return 'scripted';
            }

            public function start(): void {}

            public function stop(): CoverageWindow
            {
                return new CoverageWindow();
            }
        };

        ob_start();
        (new PostRunReport())->reportCoverage(new CoverageCollector($driver, []), $options, new WorkingDirectory($this->root), '.crucible.cache');

        return (string) ob_get_clean();
    }
}
