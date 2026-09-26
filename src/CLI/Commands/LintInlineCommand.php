<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\Phpstan;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Dialect\Inline\DoctestShadow;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\TestDiscoverer;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function printf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

use const PHP_EOL;

/**
 * `crucible lint-inline` (D-052): every `@crucible` doctest re-homed
 * into a shadow file in the cache directory, analyzed by the
 * real PHPStan through the extension, every reported line mapped
 * back to its origin. Exit 1 when the doctests have findings.
 */
final class LintInlineCommand
{
    public function execute(CliOptions $options, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        if (!is_file($workingDirectory->path . '/vendor/bin/phpstan')) {
            // An opt-in feature owes the user the way in (D-041's rule).
            print 'lint-inline needs PHPStan:' . PHP_EOL;
            print '  composer require --dev phpstan/phpstan' . PHP_EOL;

            return 1;
        }

        $cacheDirectory = $options->cacheDirectory ?? $loaded->configuration->cacheDirectory;
        $shadowRoot     = $workingDirectory->absolute($cacheDirectory) . '/lint-inline';

        if (!is_dir($shadowRoot)) {
            mkdir($shadowRoot, 0o777, true);
        }

        $stale = glob($shadowRoot . '/*.php');

        foreach ($stale === false ? [] : $stale as $leftover) {
            unlink($leftover);
        }

        /** @var array<string, array{origin: non-empty-string, lines: array<int, int>}> $shadows shadow path => mapping */
        $shadows = [];
        $count   = 0;

        foreach ((new TestDiscoverer())->inlineFiles($loaded->configuration->source, $workingDirectory) as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            $shadow = DoctestShadow::fromSource($source);

            if (!$shadow instanceof DoctestShadow) {
                continue;
            }

            $relative = str_starts_with($file, $workingDirectory->path . '/')
                ? substr($file, strlen($workingDirectory->path) + 1)
                : $file;
            $shadowFile = $shadowRoot . '/' . str_replace('/', '__', $relative);

            file_put_contents($shadowFile, $shadow->content);

            $shadows[$shadowFile] = ['origin' => $relative, 'lines' => $shadow->lines];
            $count += $shadow->count;
        }

        if ($shadows === []) {
            print 'No @crucible doctests found in the configured source.' . PHP_EOL;

            return 0;
        }

        // The origin sources are scanned, not analyzed: the doctests
        // reference their own file's symbols, and scanning keeps them
        // resolvable even where no autoloader covers them.
        $scanDirectories = array_values(array_filter(
            array_map($workingDirectory->absolute(...), $loaded->configuration->source->includeDirectories),
            is_dir(...),
        ));
        $scanFiles = array_values(array_filter(
            array_map($workingDirectory->absolute(...), $loaded->configuration->source->includeFiles),
            is_file(...),
        ));

        $neon = PhpstanNeon::analysis(Phpstan::extensionIncludes($workingDirectory), [
            'level'           => 'max',
            'paths'           => [$shadowRoot],
            'tmpDir'          => $shadowRoot . '/.cache',
            'scanDirectories' => $scanDirectories,
            'scanFiles'       => $scanFiles,
        ]);

        file_put_contents($shadowRoot . '/phpstan.neon', $neon);

        $arguments = ['--configuration=' . $shadowRoot . '/phpstan.neon'];

        if (is_file($workingDirectory->path . '/vendor/autoload.php')) {
            $arguments[] = '--autoload-file=' . $workingDirectory->path . '/vendor/autoload.php';
        }

        $report = Phpstan::analyse($workingDirectory->path . '/vendor/bin/phpstan', $arguments, $workingDirectory);

        if (is_string($report)) {
            print $report . PHP_EOL;

            return 1;
        }

        // Errors PHPStan places in no file — a broken include, an
        // unreadable path — are findings too: a doctest run that did not
        // analyse is not clean.
        foreach ($report->general as $error) {
            print $error . PHP_EOL;
        }

        $findings = count($report->general);

        foreach ($report->messages as $message) {
            $findings++;

            $mapping = $shadows[$message->file] ?? null;

            if ($mapping !== null) {
                printf('%s:%d %s' . PHP_EOL, $mapping['origin'], $mapping['lines'][$message->line] ?? $message->line, $message->message);
            } else {
                printf('%s:%d %s' . PHP_EOL, $message->file, $message->line, $message->message);
            }
        }

        if ($findings > 0) {
            printf(PHP_EOL . '%d finding(s) in %d doctest(s) across %d file(s).' . PHP_EOL, $findings, $count, count($shadows));

            return 1;
        }

        printf('Doctests analyse clean: %d expression(s) across %d file(s).' . PHP_EOL, $count, count($shadows));

        return 0;
    }
}
