<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use FilesystemIterator;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\PostRunReport;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Console\Components\Spinner;
use LucianoPereira\Crucible\Coverage\SourceAnalysis;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_dir;
use function printf;
use function str_ends_with;

use const PHP_EOL;

/**
 * The spec's --warm-coverage-cache: parse the coverage scope's source
 * now, so the run that needs its method structure — Crap4J, the XML
 * report, the per-method HTML view — does not pay for it.
 *
 * Crucible's analysis cache lives in the process rather than on disk,
 * so warming it is only worth anything inside a longer-lived one; the
 * command reports what it parsed rather than implying otherwise.
 */
final class WarmCoverageCacheCommand
{
    public function execute(CliOptions $options, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $scope = (new PostRunReport())->coverageScope($loaded->configuration, $workingDirectory, $options->coverageFilter);

        if ($scope === []) {
            print 'Coverage: configure ->source(include: [...]) — the source scope is the coverage scope.' . PHP_EOL;

            return 1;
        }

        $files = [];

        foreach ($scope as $entry) {
            foreach ($this->phpFilesIn($entry) as $file) {
                $files[] = $file;
            }
        }

        // The one blocking phase this command has, and it is the command.
        // Parsing a large scope is tens of seconds of silence otherwise.
        $warmed = (new Spinner('Analysing the coverage scope'))->spin(
            static fn(): int => SourceAnalysis::warm($files),
        );

        printf('Analysed %d source file(s) for coverage reporting.' . PHP_EOL, $warmed);

        return 0;
    }

    /**
     * @param non-empty-string $entry a scope entry: a directory (with a trailing slash) or a single file
     *
     * @return list<non-empty-string>
     */
    private function phpFilesIn(string $entry): array
    {
        if (!is_dir($entry)) {
            return str_ends_with($entry, '.php') ? [$entry] : [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entry, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
