<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\CLI\Phpstan;
use LucianoPereira\Crucible\CLI\PhpstanMessage;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\CLI\PhpstanReport;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\SkippedTestError;

use function array_map;
use function dirname;
use function file_put_contents;
use function implode;
use function is_file;
use function is_string;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * One PHPStan analysis for a test that proves the extension (D-137):
 * through Phpstan::analyse(), the run lint-inline and type tests use, so
 * a test and the product read PHPStan the same way — output to files,
 * the report read once. At level max, in a fresh directory; skipped when
 * PHPStan is not installed.
 */
final class Analysis
{
    /** The repository root. */
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param list<string>       $paths    analysed
     * @param list<string>       $includes neon files, absolute
     * @param list<class-string> $rules    registered on their own
     * @param array<non-empty-string, string|list<string>> $parameters over level max: bootstrapFiles, a lower level
     */
    public static function run(array $paths, array $includes = [], array $rules = [], array $parameters = []): PhpstanReport
    {
        $root = self::root();

        if (!is_file($root . '/vendor/bin/phpstan')) {
            throw new SkippedTestError('phpstan is not installed (require-dev).');
        }

        $directory = sys_get_temp_dir() . '/crucible-phpstan-' . uniqid();
        mkdir($directory, 0o777, true);

        file_put_contents($directory . '/phpstan.neon', PhpstanNeon::analysis($includes, [
            'level' => 'max',
            ...$parameters,
            'paths'  => $paths,
            'tmpDir' => $directory . '/cache',
        ], $rules));

        $report = Phpstan::analyse(
            $root . '/vendor/bin/phpstan',
            ['--configuration=' . $directory . '/phpstan.neon', '--autoload-file=' . $root . '/vendor/autoload.php', '--memory-limit=1G'],
            new WorkingDirectory($root),
        );

        Assert::assertInstanceOf(PhpstanReport::class, $report, is_string($report) ? $report : '');
        Assert::assertSame([], $report->general, 'PHPStan reported errors in no file: ' . implode(' | ', $report->general));

        return $report;
    }

    /**
     * The report as `identifier: message` lines.
     *
     * @return list<string>
     */
    public static function rendered(PhpstanReport $report): array
    {
        return array_map(static fn(PhpstanMessage $message): string => ($message->identifier ?? '?') . ': ' . $message->message, $report->messages);
    }

    /**
     * Crucible's extension and its dialect rules, as the repository loads them.
     *
     * @return list<non-empty-string>
     */
    public static function extension(): array
    {
        return [self::root() . '/phpstan/extension.neon', self::root() . '/phpstan/crucible-dialect.neon'];
    }
}
