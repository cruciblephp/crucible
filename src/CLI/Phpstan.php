<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\Process\NullDevice;

use function array_find;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function proc_close;
use function proc_open;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const PHP_BINARY;

/**
 * Running PHPStan, once, for everything that does (D-137): `lint-inline`
 * (D-052), type tests (D-130), and the tests and probes that prove the
 * extension. Each used to locate the binary, decide the extension
 * include, spawn the process and read the JSON on its own — four copies,
 * one of which read stdout then stderr from two pipes, the order that
 * blocks forever once a child fills the pipe nobody is reading.
 *
 * Output goes to files, not pipes; the report is read defensively, once.
 */
final readonly class Phpstan
{
    private const array EXTENSION = ['vendor/cruciblephp/crucible/phpstan/extension.neon', 'phpstan/extension.neon'];

    /**
     * Crucible's extension.neon as the project sees it: installed under
     * vendor/, or this checkout's own. Null when neither exists.
     *
     * @return ?non-empty-string relative to the working directory
     */
    public static function extension(WorkingDirectory $workingDirectory): ?string
    {
        return array_find(self::EXTENSION, static fn(string $candidate): bool => is_file($workingDirectory->path . '/' . $candidate));
    }

    /**
     * The project's own PHPStan configuration, by PHPStan's own file names.
     *
     * @return ?non-empty-string relative to the working directory
     */
    public static function projectConfiguration(WorkingDirectory $workingDirectory): ?string
    {
        return array_find(['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'], static fn(string $candidate): bool => is_file($workingDirectory->path . '/' . $candidate));
    }

    /**
     * The include a written configuration needs for Crucible's extension:
     * none when phpstan/extension-installer already loads it (D-127) —
     * including it twice makes PHPStan refuse to run — or when it cannot
     * be found.
     *
     * @return list<non-empty-string> absolute: the extension, or nothing
     */
    public static function extensionIncludes(WorkingDirectory $workingDirectory): array
    {
        $extension = self::extension($workingDirectory);

        return $extension === null || PhpstanNeon::installerLoadsExtension($workingDirectory)
            ? []
            : [$workingDirectory->path . '/' . $extension];
    }

    /**
     * One analysis. The report, or why there is none: PHPStan missing,
     * not started, or writing no JSON (its stderr, trimmed, is the reason).
     *
     * @param non-empty-string       $binary
     * @param list<non-empty-string> $arguments after `analyse`: --configuration, paths, …
     *
     * @return PhpstanReport|non-empty-string
     */
    public static function analyse(string $binary, array $arguments, WorkingDirectory $workingDirectory): PhpstanReport|string
    {
        if (!is_file($binary)) {
            return 'PHPStan is not installed at ' . $binary . ': composer require --dev phpstan/phpstan';
        }

        $out = tempnam(sys_get_temp_dir(), 'crucible-phpstan-');
        $err = tempnam(sys_get_temp_dir(), 'crucible-phpstan-err-');

        if ($out === false || $err === false) {
            return 'Temporary files for the PHPStan report could not be created.';
        }

        $process = @proc_open(
            [PHP_BINARY, $binary, 'analyse', '--error-format=json', '--no-progress', '--no-interaction', ...$arguments],
            [['file', NullDevice::path(), 'r'], ['file', $out, 'w'], ['file', $err, 'w']],
            $pipes,
            $workingDirectory->path,
        );

        if (!is_resource($process)) {
            @unlink($out);
            @unlink($err);

            return 'PHPStan could not be started.';
        }

        proc_close($process);

        $decoded = json_decode((string) file_get_contents($out), true);
        $stderr  = trim((string) file_get_contents($err));

        @unlink($out);
        @unlink($err);

        if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
            return 'PHPStan wrote no report' . ($stderr !== '' ? ': ' . substr($stderr, 0, 500) : '.');
        }

        $messages = [];

        foreach ($decoded['files'] as $file => $entry) {
            $entries = is_array($entry) && is_array($entry['messages'] ?? null) ? $entry['messages'] : [];

            foreach ($entries as $message) {
                if (!is_array($message) || !is_string($message['message'] ?? null) || $message['message'] === '') {
                    continue;
                }

                $identifier = $message['identifier'] ?? null;

                $messages[] = new PhpstanMessage(
                    (string) $file,
                    is_int($message['line'] ?? null) && $message['line'] >= 0 ? $message['line'] : 0,
                    is_string($identifier) && $identifier !== '' ? $identifier : null,
                    $message['message'],
                );
            }
        }

        $general = [];

        foreach (is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [] as $error) {
            if (is_string($error) && $error !== '') {
                $general[] = $error;
            }
        }

        return new PhpstanReport($messages, $general);
    }
}
