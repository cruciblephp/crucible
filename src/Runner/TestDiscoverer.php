<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Configuration\TestSuite;
use LucianoPereira\Crucible\Dialect\Inline\InlineBuilder;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Dialect\Pest\PestFileSniffer;
use LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator;
use LucianoPereira\Crucible\Dialect\PhpUnit\TestBuilder;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function array_any;
use function array_unique;
use function array_values;
use function class_exists;
use function file_get_contents;
use function fwrite;
use function in_array;
use function is_dir;
use function is_file;
use function is_subclass_of;
use function register_shutdown_function;
use function rtrim;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * Turns the configuration into executable TestGroups: test suites
 * routed per file to the phpunit/pest/crucible frontends (D-008), plus
 * the inline dialect's scan of the application source itself (G2c).
 */
/**
 * The file discovery is currently loading, kept outside the readonly
 * discoverer because it is per-process state rather than per-instance.
 */
final class DiscoveryProgress
{
    public static ?string $loadingFile = null;

    public static bool $watching = false;
}

final readonly class TestDiscoverer
{
    public function __construct(
        private ClassLocator $locator = new ClassLocator(),
        private TestBuilder $builder = new TestBuilder(),
        private PestBuilder $pestBuilder = new PestBuilder(),
        private InlineBuilder $inlineBuilder = new InlineBuilder(),
        private PestFileSniffer $pestSniffer = new PestFileSniffer(),
    ) {}

    /**
     * @param ?list<non-empty-string> $onlySuites   the spec's --testsuite selection; null discovers all
     * @param list<non-empty-string>  $exceptSuites the spec's --exclude-testsuite; exclusion wins over selection
     * @param list<non-empty-string>  $suffixes     the spec's --test-suffix; empty keeps each suite's own
     * @param list<non-empty-string>  $paths        the spec's bare arguments (D-152): files and directories run
     *                                              instead of the configured suites, whatever --testsuite says
     *
     * @return list<\LucianoPereira\Crucible\Test\TestGroup>
     */
    public function discover(Configuration $configuration, WorkingDirectory $workingDirectory, ?array $onlySuites = null, array $exceptSuites = [], array $suffixes = [], array $paths = []): array
    {
        $groups = [];
        $suites = $configuration->testSuites;

        if ($paths !== []) {
            $suites       = [$this->pathSuite($paths, $workingDirectory)];
            $onlySuites   = null;
            $exceptSuites = [];
        }

        foreach ($suites as $suite) {
            if ($onlySuites !== null && !in_array($suite->name, $onlySuites, true)) {
                continue;
            }

            if (in_array($suite->name, $exceptSuites, true)) {
                continue;
            }

            foreach ($this->filesOf($suite->directories, $suite->files, $suffixes === [] ? [$suite->suffix] : $suffixes, $workingDirectory) as $file) {
                $group = $this->groupFrom($file, $workingDirectory);

                if ($group instanceof \LucianoPereira\Crucible\Test\TestGroup && $group->tests !== []) {
                    $groups[] = $group;
                }
            }
        }

        // The inline dialect (G2c): tests declared inside application
        // source, discovered from the configured source(include:)
        // directories — config-driven, never content sniffing beyond
        // the marker pre-filter. --testsuite selects named suites and
        // inline tests belong to none, so any selection skips them, and
        // so do paths: they name what runs.
        if ($onlySuites === null && $paths === []) {
            foreach ($this->inlineFiles($configuration->source, $workingDirectory) as $file) {
                $group = $this->inlineBuilder->build($file, $this->relative($file, $workingDirectory));

                if ($group instanceof \LucianoPereira\Crucible\Test\TestGroup && $group->tests !== []) {
                    $groups[] = $group;
                }
            }
        }

        return $groups;
    }

    /**
     * Discover only the named files, routed exactly as the full pass
     * routes them: the per-file rules in {@see groupFrom()} decide the
     * dialect, never the name alone, and a source file that carries
     * inline tests builds through the inline frontend the way the
     * source scan would have found it - the one route a positional
     * path never takes (D-152).
     *
     * The worker path. A unit names one file, and re-discovering the
     * whole configured suite made every worker load every test file
     * and execute every data provider before filtering to its slice —
     * the plan's cost multiplied by the worker count, with the
     * side effects of N-1 other files along for the ride. Routing the
     * unit files directly answers identically — same per-file rules,
     * same ids — while every file no unit names stays unloaded.
     *
     * A file that no longer exists produces no group; the accounting
     * tier reports its missing results rather than discovery guessing.
     *
     * @param list<non-empty-string> $files       absolute, native paths, in the caller's order
     * @param bool                   $inlineScan  whether the run's own pass included the source scan; a
     *                                          positional-paths run never did (D-152), and neither may its workers
     *
     * @return list<\LucianoPereira\Crucible\Test\TestGroup>
     */
    public function discoverUnits(Configuration $configuration, WorkingDirectory $workingDirectory, array $files, bool $inlineScan = true): array
    {
        $groups = [];

        foreach ($files as $given) {
            // Native throughout: the full pass meets the filesystem's
            // own pathnames, and require_once dedupes on the string -
            // a file reached once as `C:/x` and once as `C:\x` is two
            // files to it, and the second load redeclares every class
            // the first one declared.
            $file = WorkingDirectory::native($given);

            if (!is_file($file)) {
                continue;
            }

            $group = $this->groupFrom($file, $workingDirectory);

            if ($group instanceof \LucianoPereira\Crucible\Test\TestGroup && $group->tests !== []) {
                $groups[] = $group;
            }

            // A source file with inline markers is the inline scan's
            // exclusive territory when nothing else claims it: a
            // positional path never reaches it (D-152), and a suite
            // that also enumerates it gets both groups, in the order
            // the full pass produces — suites first, inline after.
            if ($inlineScan && $this->inlineCarrier($file, $configuration->source, $workingDirectory)) {
                $inline = $this->inlineBuilder->build($file, $this->relative($file, $workingDirectory));

                if ($inline instanceof \LucianoPereira\Crucible\Test\TestGroup && $inline->tests !== []) {
                    $groups[] = $inline;
                }
            }
        }

        return $groups;
    }

    /**
     * Whether the full pass would find $file through the inline scan:
     * inside the configured source includes (and outside the
     * excludes), with the same marker pre-filter the scan applies —
     * the scan's predicate, per file, in the same precedence.
     *
     * @param non-empty-string $file absolute, native
     */
    private function inlineCarrier(string $file, Source $source, WorkingDirectory $workingDirectory): bool
    {
        $contents = file_get_contents($file);

        if ($contents === false || !InlineBuilder::hasMarkers($contents)) {
            return false;
        }

        foreach ($source->excludeFiles as $excluded) {
            if ($this->absolute($excluded, $workingDirectory) === $file) {
                return false;
            }
        }

        foreach ($source->excludeDirectories as $directory) {
            if (str_starts_with($file, rtrim($this->absolute($directory, $workingDirectory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return false;
            }
        }

        foreach ($source->includeFiles as $included) {
            if ($this->absolute($included, $workingDirectory) === $file) {
                return true;
            }
        }

        foreach ($source->includeDirectories as $directory) {
            if (str_starts_with($file, rtrim($this->absolute($directory, $workingDirectory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Source files that can declare inline tests: every .php file
     * under the source includes, minus the excludes, that passes the
     * InlineBuilder marker pre-filter — only those get loaded.
     * Public because `crucible lint-inline` (D-052) walks the exact
     * same set.
     *
     *
     * @return list<non-empty-string>
     */
    public function inlineFiles(Source $source, WorkingDirectory $workingDirectory): array
    {
        if (!$source->notEmpty()) {
            return [];
        }

        $candidates = [];

        foreach ($source->includeDirectories as $directory) {
            $absolute = $this->absolute($directory, $workingDirectory);

            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getPathname(), '.php')) {
                    $candidates[] = $entry->getPathname();
                }
            }
        }

        foreach ($source->includeFiles as $file) {
            $absolute = $this->absolute($file, $workingDirectory);

            if (is_file($absolute)) {
                $candidates[] = $absolute;
            }
        }

        $excludeDirectories = [];

        foreach ($source->excludeDirectories as $directory) {
            $excludeDirectories[] = rtrim($this->absolute($directory, $workingDirectory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        $excludeFiles = [];

        foreach ($source->excludeFiles as $file) {
            $excludeFiles[] = $this->absolute($file, $workingDirectory);
        }

        $found = [];

        foreach (array_values(array_unique($candidates)) as $file) {
            if (in_array($file, $excludeFiles, true)) {
                continue;
            }

            foreach ($excludeDirectories as $prefix) {
                if (str_starts_with($file, $prefix)) {
                    continue 2;
                }
            }

            $contents = file_get_contents($file);

            if ($contents === false || !InlineBuilder::hasMarkers($contents)) {
                continue;
            }

            $found[] = $file;
        }

        sort($found);

        return $found;
    }

    /**
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $files
     * @param list<non-empty-string> $suffixes
     *
     * @return list<non-empty-string>
     */
    private function filesOf(array $directories, array $files, array $suffixes, WorkingDirectory $workingDirectory): array
    {
        $found = [];

        foreach ($directories as $directory) {
            $absolute = $this->absolute($directory, $workingDirectory);

            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                // *.pest.php and *.crucible.php files are always collected:
                // the dialect is chosen per file by suffix (D-008), not
                // per suite.
                $pathname = $entry->getPathname();

                if ($pathname !== '' && $entry->isFile() && (
                    array_any($suffixes, static fn(string $suffix): bool => str_ends_with($pathname, $suffix))
                    || str_ends_with($pathname, '.pest.php')
                    || str_ends_with($pathname, '.crucible.php')
                )) {
                    $found[] = $pathname;
                }
            }
        }

        foreach ($files as $file) {
            $absolute = $this->absolute($file, $workingDirectory);

            if (is_file($absolute)) {
                $found[] = $absolute;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @param non-empty-string $file
     */
    private function groupFrom(string $file, WorkingDirectory $workingDirectory): ?\LucianoPereira\Crucible\Test\TestGroup
    {
        // The crucible dialect (G2b) is the pest vocabulary plus the
        // check()/table() extras — one builder serves both suffixes.
        // Explicit and unambiguous: nothing below ever overrides it.
        if (str_ends_with($file, '.pest.php') || str_ends_with($file, '.crucible.php')) {
            return $this->pestBuilder->build($file, $this->relative($file, $workingDirectory));
        }

        // Every other .php file is classified by content, never by
        // name — real Pest suites use *Test.php, identically to
        // PHPUnit ones (confirmed against a real project: Monolog's
        // benchmark and spatie/laravel-data both name files this way).
        // A top-level Pest call always wins over a top-level class: a
        // real Pest file may legitimately declare an unrelated
        // top-level fixture/support class alongside its it() calls
        // (confirmed against spatie/laravel-data's
        // InjectPropertyValuesTest.php, which declares a top-level
        // #[Attribute] class right next to real it() calls) — that
        // class simply isn't a TestCase candidate, not a sign the
        // file's dialect is unclear. Both checks are pure token
        // scans; nothing executes yet.
        if ($this->pestSniffer->hasTopLevelCalls($file)) {
            $group = $this->pestBuilder->build($file, $this->relative($file, $workingDirectory));

            // A pest-shaped file that declares no test is not a pest test
            // file: `uses(Base::class)` binds a base class without
            // declaring anything, and the tests it was binding for may be
            // a PHPUnit TestCase in that very file. The incumbent
            // collects that class -- it runs PHPUnit underneath and does
            // not treat the two as exclusive -- so stopping here reported
            // OK having run almost nothing.
            //
            // Measured against spatie/schema-org on Pest 5.1.1:
            // tests/AnalysisTest.php declares a TestCase whose data
            // provider yields 1,862 cases and ends with
            // `uses(AnalysisTest::class);`. The incumbent ran 1,926
            // tests; Crucible ran 64 and exited 0. A false green at the
            // level of DISCOVERY, which no verdict-level parity check can
            // see.
            //
            // Falling through is safe and cheap: build() has already
            // required the file, so the class is loaded and the guard
            // below finds it without executing anything twice.
            if ($group->tests !== []) {
                return $group;
            }
        }

        $candidates = $this->locator->classesIn($file);

        if ($candidates === []) {
            return null;
        }

        // Armed before class_exists, not just around the require: a
        // project that maps its tests in autoload-dev has the class
        // loaded by Composer inside that check, so a file that ends the
        // process does it there.
        $this->loading($file);

        if (!class_exists($candidates[0])) {
            require_once $file;
        }

        $this->loading(null);

        foreach ($candidates as $className) {
            if (!class_exists($className) || !is_subclass_of($className, TestCase::class)) {
                continue;
            }

            if ((new ReflectionClass($className))->isAbstract()) {
                continue;
            }

            return $this->builder->build($className, $this->relative($file, $workingDirectory));
        }

        return null;
    }

    /**
     * Require files another unit's code uses at runtime, before this
     * worker's own discovery: a suite file can declare a function or
     * class a different selected file's code calls, and the worker
     * that runs the caller no longer loads every discovered file to
     * find out. Under the same load watcher discovery uses, so a file
     * that stops the process while being preloaded reports the same
     * cause a discovery load would.
     *
     * @param list<string> $files absolute, native paths
     */
    public function preload(array $files): void
    {
        foreach ($files as $file) {
            $file = WorkingDirectory::native($file);

            if ($file === '' || !is_file($file)) {
                continue;
            }

            $this->loading($file);
            require_once $file;
            $this->loading(null);
        }
    }

    /**
     * Names the file about to be loaded, and arms a shutdown check the
     * first time.
     *
     * Discovery has to execute a test file to reflect over its class,
     * and a file is free to end the process while being loaded — a
     * fatal, or an `exit()` in a guard that recognises only the runners
     * it was written for. That kills the run before any test reports,
     * so the suite prints nothing and exits non-zero with no verdict and
     * no cause. Observed on a real project whose every test file
     * includes a guard checking `argv[0]` against "phpunit" and "pest".
     * Crucible cannot stop the exit; it can refuse to be silent about it.
     */
    private function loading(?string $file): void
    {
        DiscoveryProgress::$loadingFile = $file;

        if (DiscoveryProgress::$watching) {
            return;
        }

        DiscoveryProgress::$watching = true;

        register_shutdown_function(static function (): void {
            if (DiscoveryProgress::$loadingFile === null) {
                return;
            }

            fwrite(STDERR, sprintf(
                "\nThe run ended while loading %s.\n"
                . "That file stopped the process as it was being read for discovery — a fatal\n"
                . "error, or an exit() in a guard that only recognises certain test runners.\n"
                . "No test reported, so there is no verdict: this is not a failing suite.\n",
                DiscoveryProgress::$loadingFile,
            ));
        });
    }

    /**
     * The paths as one suite, a directory taking the spec's default suffix.
     *
     * @param list<non-empty-string> $paths
     */
    private function pathSuite(array $paths, WorkingDirectory $workingDirectory): TestSuite
    {
        $directories = [];
        $files       = [];

        foreach ($paths as $path) {
            if (is_dir($this->absolute($path, $workingDirectory))) {
                $directories[] = $path;
            } else {
                $files[] = $path;
            }
        }

        return new TestSuite('paths', $directories, $files);
    }

    /**
     * @param non-empty-string $path
     *
     * @return non-empty-string
     */
    private function absolute(string $path, WorkingDirectory $workingDirectory): string
    {
        // Native, because every candidate below comes from the
        // filesystem and is compared against these prefixes.
        return WorkingDirectory::native($workingDirectory->absolute($path));
    }

    /**
     * @param non-empty-string $file
     *
     * @return non-empty-string
     */
    private function relative(string $file, WorkingDirectory $workingDirectory): string
    {
        $relative = $workingDirectory->relative($file);

        return $relative === '' ? $file : $relative;
    }
}
