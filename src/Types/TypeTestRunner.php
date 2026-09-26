<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Types;

use FilesystemIterator;
use LucianoPereira\Crucible\CLI\Phpstan;
use LucianoPereira\Crucible\CLI\PhpstanMessage;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\CLI\PhpstanReport;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\FoldIn;
use LucianoPereira\Crucible\Runner\NameFilter;
use LucianoPereira\Crucible\Test\TestId;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_any;
use function array_find;
use function array_key_exists;
use function count;
use function file_get_contents;
use function file_put_contents;
use function hrtime;
use function implode;
use function is_dir;
use function ltrim;
use function max;
use function mkdir;
use function preg_match;
use function sort;
use function sprintf;
use function str_ends_with;
use function strrchr;
use function substr;
use function sys_get_temp_dir;

use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_STRING;

/**
 * Runs a type-test suite (D-130): one PHPStan analysis over its
 * `*.types.php` files, every assertion a test with its own verdict.
 *
 *   - PHPStan's assertion functions — `assertType()`, `assertNativeType()`,
 *     `assertSuperType()`, `assertVariableCertainty()` — pass when PHPStan
 *     reports nothing on their line, fail on the identifier the function
 *     reports a mismatch with (`phpstan.type`, …), and error on anything
 *     else there: the line did not analyse.
 *   - A line ending `// crucible-type-error <identifier>` passes when
 *     PHPStan reports that identifier on it, and fails when the line
 *     analyses clean or reports something else (both named).
 *   - Errors on no assertion's line are the file's, one errored entry;
 *     so is a file with no assertion at all, which tests nothing — the
 *     rule PHPStan's own TypeInferenceTestCase keeps.
 *
 * Never a silent pass: PHPStan that cannot start, writes no report, or
 * reports an error it places in no file, is one errored test with the
 * reason.
 */
final readonly class TypeTestRunner
{
    private const string MARKER = 'crucible-type-error';

    /** Each assertion function, and the identifier PHPStan reports its mismatch with. */
    private const array ASSERTIONS = [
        'assertType'              => 'phpstan.type',
        'assertNativeType'        => 'phpstan.nativeType',
        'assertSuperType'         => 'phpstan.superType',
        'assertVariableCertainty' => 'phpstan.variable',
    ];

    private FoldIn $fold;

    /**
     * @param ?non-empty-string $cacheDirectory where a written configuration and PHPStan's result cache live, so a
     *                                          second run reads the cache instead of analysing everything again
     */
    public function __construct(Emitter $emitter, private ?string $cacheDirectory = null)
    {
        $this->fold = new FoldIn($emitter);
    }

    /**
     * @param list<TypeTestSuite> $suites
     */
    public function run(array $suites, WorkingDirectory $workingDirectory): RunSummary
    {
        $summary = new RunSummary();

        foreach ($suites as $suite) {
            $summary = $summary->plus($this->runSuite($suite, $workingDirectory));
        }

        return $summary;
    }

    private function runSuite(TypeTestSuite $suite, WorkingDirectory $workingDirectory): RunSummary
    {
        $files = $this->files($workingDirectory->absolute($suite->directory));

        if ($files === []) {
            return new RunSummary();
        }

        $started = hrtime(true);
        $report  = $this->analyse($suite, $files, $workingDirectory);
        $elapsed = (hrtime(true) - $started) / 1e9;

        if ($report instanceof PhpstanReport && $report->general !== []) {
            $report = 'PHPStan reported an error in no file: ' . $report->general[0];
        }

        if (!$report instanceof PhpstanReport) {
            return $this->fold->couldNotRun(new TestId($suite->directory, 'types'), $report);
        }

        return $this->fold->emit($this->translate($suite, $files, $report, $elapsed, $workingDirectory));
    }

    /**
     * The report as finished tests: one per assertion the filter selects,
     * plus a file's own entry when something on it is no assertion's.
     *
     * @param list<non-empty-string> $files
     *
     * @return list<TestFinished>
     */
    private function translate(TypeTestSuite $suite, array $files, PhpstanReport $report, float $elapsed, WorkingDirectory $workingDirectory): array
    {
        $points = [];
        $total  = 0;

        foreach ($files as $file) {
            $points[$file] = $this->points($file);
            $total += count($points[$file]);
        }

        // One analysis answered every assertion: its time is shared, so the
        // tree's totals add up to the time the run actually spent.
        $each    = $elapsed / max(1, $total);
        $filter  = $suite->filter === null ? null : new NameFilter($suite->filter);
        $results = [];

        foreach ($files as $file) {
            $relative = $workingDirectory->relative($file);
            $relative = $relative !== '' ? $relative : $file;

            /** @var array<int, list<PhpstanMessage>> $errors line => what PHPStan reported there */
            $errors = [];

            foreach ($report->forFile($file) as $message) {
                $errors[$message->line][] = $message;
            }

            foreach ($points[$file] as $point) {
                if (!$filter instanceof NameFilter || $filter->matchesName($point['name'])) {
                    [$outcome, $failure] = $this->verdict($point, $errors[$point['line']] ?? []);
                    $results[]           = new TestFinished(new TestId($relative, $point['name']), $outcome, $each, $failure);
                }

                unset($errors[$point['line']]);
            }

            if ($filter instanceof NameFilter) {
                continue;
            }

            $unclaimed = [];

            foreach ($errors as $line => $messages) {
                foreach ($messages as $message) {
                    $unclaimed[] = sprintf('line %d: %s', $line, $message->message);
                }
            }

            $why = match (true) {
                $unclaimed !== []     => 'PHPStan reported errors outside any type assertion:' . "\n" . implode("\n", $unclaimed),
                $points[$file] === [] => 'No type assertion in this file: it tests nothing. Call PHPStan\Testing\assertType(), or mark a line // ' . self::MARKER . ' <identifier>.',
                default               => null,
            };

            if ($why !== null) {
                $results[] = new TestFinished(new TestId($relative, 'the file analyses'), Outcome::Errored, 0.0, new Failure($why));
            }
        }

        return $results;
    }

    /**
     * @param array{line: int, name: non-empty-string, identifier: ?string, mismatch: ?string} $point
     * @param list<PhpstanMessage>                                                               $errors on its line
     *
     * @return array{Outcome, ?Failure}
     */
    private function verdict(array $point, array $errors): array
    {
        if ($point['identifier'] !== null) {
            if (array_any($errors, static fn(PhpstanMessage $error): bool => $error->identifier === $point['identifier'])) {
                return [Outcome::Passed, null];
            }

            return [Outcome::Failed, new Failure($errors === []
                ? sprintf('Expected a %s error on this line; it analyses clean.', $point['identifier'])
                : sprintf('Expected a %s error on this line; PHPStan reported: %s', $point['identifier'], $this->describe($errors)))];
        }

        if ($errors === []) {
            return [Outcome::Passed, null];
        }

        $mismatch = array_find($errors, static fn(PhpstanMessage $error): bool => $error->identifier === $point['mismatch']);

        return $mismatch instanceof PhpstanMessage
            ? [Outcome::Failed, new Failure($mismatch->message)]
            : [Outcome::Errored, new Failure('The line does not analyse: ' . $this->describe($errors))];
    }

    /**
     * @param list<PhpstanMessage> $errors
     */
    private function describe(array $errors): string
    {
        $parts = [];

        foreach ($errors as $error) {
            $parts[] = $error->message . ($error->identifier !== null ? ' [' . $error->identifier . ']' : '');
        }

        return implode('; ', $parts);
    }

    /**
     * The *.types.php files under a directory, in path order.
     *
     * @return list<non-empty-string>
     */
    private function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.types.php') ? $file->getRealPath() : false;

            if ($path !== false && $path !== '') {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The assertions in a file, from its tokens: every call to one of
     * PHPStan's assertion functions, and every line marked
     * crucible-type-error.
     *
     * @return list<array{line: int, name: non-empty-string, identifier: ?string, mismatch: ?string}>
     */
    private function points(string $file): array
    {
        $tokens = PhpToken::tokenize((string) file_get_contents($file));
        $points = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(T_COMMENT) && preg_match('/' . self::MARKER . '\s+([A-Za-z0-9_.]+)/', $token->text, $match) === 1) {
                $points[] = ['line' => $token->line, 'name' => sprintf('line %d: type error %s', $token->line, $match[1]), 'identifier' => $match[1], 'mismatch' => null];

                continue;
            }

            if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $function = ltrim((string) strrchr('\\' . $token->text, '\\'), '\\');
            $next     = $this->significant($tokens, $i + 1);

            if (!array_key_exists($function, self::ASSERTIONS) || $next === null || $tokens[$next]->text !== '(') {
                continue;
            }

            $argument = $this->significant($tokens, $next + 1);
            $expected = $argument !== null && $tokens[$argument]->is(T_CONSTANT_ENCAPSED_STRING)
                ? substr($tokens[$argument]->text, 1, -1)
                : '…';
            $name = match ($function) {
                'assertNativeType'        => 'native ' . $expected,
                'assertSuperType'         => 'subtype of ' . $expected,
                'assertVariableCertainty' => 'variable certainty',
                default                   => $expected,
            };

            $points[] = ['line' => $token->line, 'name' => sprintf('line %d: %s', $token->line, $name), 'identifier' => null, 'mismatch' => self::ASSERTIONS[$function]];
        }

        return $points;
    }

    /**
     * @param array<PhpToken> $tokens as PhpToken::tokenize() returns them
     */
    private function significant(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /**
     * One analysis over every file.
     *
     * @param list<non-empty-string> $files
     *
     * @return PhpstanReport|non-empty-string
     */
    private function analyse(TypeTestSuite $suite, array $files, WorkingDirectory $workingDirectory): PhpstanReport|string
    {
        $arguments = ['--memory-limit=1G'];
        $neon      = $this->configuration($suite, $workingDirectory);

        if ($neon !== null) {
            $arguments[] = '--configuration=' . $neon;
        }

        return Phpstan::analyse($workingDirectory->absolute($suite->phpstan ?? 'vendor/bin/phpstan'), [...$arguments, ...$files], $workingDirectory);
    }

    /**
     * The configuration the analysis reads: the suite's, the project's
     * phpstan.neon(.dist), or — with neither — a written one that loads
     * Crucible's extension at level max (unless the extension installer
     * already does, D-127).
     *
     * @return ?non-empty-string
     */
    private function configuration(TypeTestSuite $suite, WorkingDirectory $workingDirectory): ?string
    {
        $configuration = $suite->configuration ?? Phpstan::projectConfiguration($workingDirectory);

        if ($configuration !== null) {
            return $workingDirectory->absolute($configuration);
        }

        $home = ($this->cacheDirectory ?? sys_get_temp_dir() . '/crucible-types') . '/types';

        if (!is_dir($home) && !@mkdir($home, 0o777, true) && !is_dir($home)) {
            return null;
        }

        // A stable path and tmpDir: PHPStan's result cache is keyed on
        // them, and a written file at a new path every run analysed
        // everything every run.
        file_put_contents($home . '/phpstan.neon', PhpstanNeon::analysis(Phpstan::extensionIncludes($workingDirectory), [
            'level'  => 'max',
            'tmpDir' => $home . '/phpstan',
        ]));

        return $home . '/phpstan.neon';
    }
}
