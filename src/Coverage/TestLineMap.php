<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The per-test line map (D-047): which lines of which project files
 * each test executed — the query a mutation tool asks ("which tests
 * cover the mutated line?") and the data source DeFlaker's
 * failure-outside-the-diff hint reads. Persisted in the cache
 * directory on every coverage run, project-relative so it survives
 * moves, versioned, corrupt = empty — never a failed run.
 */
final readonly class TestLineMap
{
    public const int VERSION = 1;

    /**
     * @param array<string, array<string, list<int>>> $tests test id => relative file => executed lines
     */
    public function __construct(
        public array $tests = [],
    ) {}

    /**
     * @param non-empty-string $root absolute project root
     */
    public static function fromData(CoverageData $data, string $root): self
    {
        $prefix = $root . '/';
        $tests  = [];

        foreach ($data->tests as $testId => $files) {
            $perFile = [];

            foreach ($files as $file => $executedLines) {
                $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

                if ($relative !== '') {
                    $perFile[$relative] = $executedLines;
                }
            }

            $tests[$testId] = $perFile;
        }

        ksort($tests);

        return new self($tests);
    }

    /**
     * The mutation-facing query: every test that executed the given
     * line, in stable (map) order — timing-aware ordering is the
     * mutation tier's job, joining the result cache's durations.
     *
     * @param non-empty-string $relativeFile project-relative path
     *
     * @return list<string> test ids
     */
    public function testsCovering(string $relativeFile, int $line): array
    {
        $covering = [];

        foreach ($this->tests as $testId => $files) {
            if (in_array($line, $files[$relativeFile] ?? [], true)) {
                $covering[] = $testId;
            }
        }

        return $covering;
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            return new self();
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return new self();
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            return new self();
        }

        $tests = [];

        foreach (is_array($decoded['tests'] ?? null) ? $decoded['tests'] : [] as $testId => $files) {
            if (!is_string($testId) || !is_array($files)) {
                continue;
            }

            $perFile = [];

            foreach ($files as $relativeFile => $lines) {
                if (!is_string($relativeFile) || $relativeFile === '' || !is_array($lines)) {
                    continue;
                }

                $executed = [];

                foreach ($lines as $line) {
                    if (is_int($line)) {
                        $executed[] = $line;
                    }
                }

                $perFile[$relativeFile] = $executed;
            }

            $tests[$testId] = $perFile;
        }

        return new self($tests);
    }

    public function save(string $file): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($file, json_encode(
            ['version' => self::VERSION, 'tests' => $this->tests],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }
}
