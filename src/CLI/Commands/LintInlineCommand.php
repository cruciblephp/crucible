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
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Dialect\Inline\DoctestShadow;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\TestDiscoverer;

use function count;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function mkdir;
use function printf;
use function proc_close;
use function proc_open;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function unlink;

use const PHP_BINARY;
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

        $include = null;

        foreach (['vendor/cruciblephp/crucible/phpstan/extension.neon', 'phpstan/extension.neon'] as $candidate) {
            if (is_file($workingDirectory->path . '/' . $candidate)) {
                $include = $workingDirectory->path . '/' . $candidate;

                break;
            }
        }

        $neon = $include !== null ? "includes:\n    - " . $include . "\n\n" : '';
        $neon .= "parameters:\n    level: max\n    paths:\n        - " . $shadowRoot
            . "\n    tmpDir: " . $shadowRoot . '/.cache' . "\n";

        // The origin sources are scanned, not analyzed: the doctests
        // reference their own file's symbols, and scanning keeps them
        // resolvable even where no autoloader covers them.
        $scanDirectories = [];

        foreach ($loaded->configuration->source->includeDirectories as $directory) {
            $absolute = $workingDirectory->absolute($directory);

            if (is_dir($absolute)) {
                $scanDirectories[] = $absolute;
            }
        }

        if ($scanDirectories !== []) {
            $neon .= "    scanDirectories:\n";

            foreach ($scanDirectories as $directory) {
                $neon .= '        - ' . $directory . "\n";
            }
        }

        $scanFiles = [];

        foreach ($loaded->configuration->source->includeFiles as $file) {
            $absolute = $workingDirectory->absolute($file);

            if (is_file($absolute)) {
                $scanFiles[] = $absolute;
            }
        }

        if ($scanFiles !== []) {
            $neon .= "    scanFiles:\n";

            foreach ($scanFiles as $file) {
                $neon .= '        - ' . $file . "\n";
            }
        }

        file_put_contents($shadowRoot . '/phpstan.neon', $neon);

        $command = [
            PHP_BINARY,
            $workingDirectory->path . '/vendor/bin/phpstan',
            'analyse',
            '--configuration', $shadowRoot . '/phpstan.neon',
            '--error-format', 'json',
            '--no-progress',
        ];

        if (is_file($workingDirectory->path . '/vendor/autoload.php')) {
            $command[] = '--autoload-file';
            $command[] = $workingDirectory->path . '/vendor/autoload.php';
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory->path);

        if (!is_resource($process)) {
            print 'PHPStan could not be started.' . PHP_EOL;

            return 1;
        }

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($output === false ? '' : $output, true);

        if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
            print 'PHPStan produced no result.' . PHP_EOL . ($errors === false ? '' : $errors);

            return 1;
        }

        $findings = 0;

        foreach ($decoded['files'] as $reportedFile => $entry) {
            if (!is_string($reportedFile) || !is_array($entry) || !is_array($entry['messages'] ?? null)) {
                continue;
            }

            $mapping = $shadows[$reportedFile] ?? null;

            foreach ($entry['messages'] as $message) {
                if (!is_array($message) || !is_string($message['message'] ?? null)) {
                    continue;
                }

                $findings++;

                $line = is_int($message['line'] ?? null) ? $message['line'] : 0;

                if ($mapping !== null) {
                    printf('%s:%d %s' . PHP_EOL, $mapping['origin'], $mapping['lines'][$line] ?? $line, $message['message']);
                } else {
                    printf('%s:%d %s' . PHP_EOL, $reportedFile, $line, $message['message']);
                }
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
