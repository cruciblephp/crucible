<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use Closure;
use LucianoPereira\Crucible\Architecture\Architecture;
use LucianoPereira\Crucible\Assert\Differ;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\Large;
use LucianoPereira\Crucible\Attributes\Medium;
use LucianoPereira\Crucible\Attributes\RunClassInSeparateProcess;
use LucianoPereira\Crucible\Attributes\RunInSeparateProcess;
use LucianoPereira\Crucible\Attributes\RunTestsInSeparateProcesses;
use LucianoPereira\Crucible\Attributes\Small;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\BrowserEngine;
use LucianoPereira\Crucible\Browser\Browsing;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\PhpConfigurationCheck;
use LucianoPereira\Crucible\CLI\PostRunReport;
use LucianoPereira\Crucible\Clock\Clock;
use LucianoPereira\Crucible\Clock\SourceDateEpoch;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Compat\MockeryCompatibility;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Crucible;
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Configuration\LoadedConfiguration;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Console\Components\Splash;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Style\Style as ConsoleStyle;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Coverage\CoverageCollector;
use LucianoPereira\Crucible\Coverage\CoverageMap;
use LucianoPereira\Crucible\Coverage\DriverFactory;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Dialect\Pest\PestScopes;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\EventTextWriter;
use LucianoPereira\Crucible\Event\NdjsonWriter;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Extension\Check;
use LucianoPereira\Crucible\Extension\CheckRunner;
use LucianoPereira\Crucible\Extension\Extension;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Impact\ChangedFiles;
use LucianoPereira\Crucible\Impact\DependencyGraph;
use LucianoPereira\Crucible\Impact\DependencyIndex;
use LucianoPereira\Crucible\Impact\ImpactFingerprint;
use LucianoPereira\Crucible\Impact\ImpactSelection;
use LucianoPereira\Crucible\Impact\VitestImpact;
use LucianoPereira\Crucible\Isolation\GlobalStateExport;
use LucianoPereira\Crucible\Mutation\MutationAutoloader;
use LucianoPereira\Crucible\Reporting\GenericReportWriter;
use LucianoPereira\Crucible\Reporting\MapView;
use LucianoPereira\Crucible\Reporting\ProfileReporter;
use LucianoPereira\Crucible\Reporting\ProgressView\ProgressViewRegistry;
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormatRegistry;
use LucianoPereira\Crucible\Reporting\Style;
use LucianoPereira\Crucible\Reporting\Subscriber\SubscriberRegistry;
use LucianoPereira\Crucible\Runner\CheckLog;
use LucianoPereira\Crucible\Runner\DeprecationBaseline;
use LucianoPereira\Crucible\Runner\FlakinessLog;
use LucianoPereira\Crucible\Runner\IssueLog;
use LucianoPereira\Crucible\Runner\NameFilter;
use LucianoPereira\Crucible\Runner\OutcomeLog;
use LucianoPereira\Crucible\Runner\Process\DependencyValues;
use LucianoPereira\Crucible\Runner\Process\EventParser;
use LucianoPereira\Crucible\Runner\Process\Supervisor;
use LucianoPereira\Crucible\Runner\Process\WorkerManifest;
use LucianoPereira\Crucible\Runner\PropertyFailures;
use LucianoPereira\Crucible\Runner\PropertyFailureWriter;
use LucianoPereira\Crucible\Runner\Repetition;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Runner\ResultCacheWriter;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\Scheduler;
use LucianoPereira\Crucible\Runner\Shard;
use LucianoPereira\Crucible\Runner\TestDiscoverer;
use LucianoPereira\Crucible\Runner\TestRecordLog;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Runner\TestSelection;
use LucianoPereira\Crucible\Snapshot\InlineSnapshotWriter;
use LucianoPereira\Crucible\Snapshot\SnapshotLog;
use LucianoPereira\Crucible\Snapshot\SnapshotPruner;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Vitest\VitestRunner;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_pad;
use function array_unique;
use function array_values;
use function class_exists;
use function define;
use function defined;
use function explode;
use function file;
use function file_put_contents;
use function fopen;
use function fwrite;
use function get_include_path;
use function getcwd;
use function glob;
use function htmlspecialchars;
use function implode;
use function in_array;
use function ini_set;
use function is_dir;
use function is_file;
use function is_string;
use function json_encode;
use function max;
use function mkdir;
use function ob_end_clean;
use function ob_start;
use function printf;
use function putenv;
use function random_int;
use function realpath;
use function rtrim;
use function set_include_path;
use function sort;
use function sprintf;
use function str_starts_with;
use function stream_get_contents;
use function stream_isatty;
use function strlen;
use function substr;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * `crucible` with no subcommand: run the configured test suite. Owns
 * both the parent-process orchestration (`execute()`) and the child
 * worker protocol (`worker()`) — the two ends of the same process
 * split that process isolation and parallelism use throughout a run.
 */
final class RunTestsCommand
{
    /** Milliseconds per sweep frame while the suite is discovered. */
    private const int DISCOVERY_FRAME_MS = 70;

    /**
     * @param list<string>     $argv
     */
    public function execute(CliOptions $options, array $argv, WorkingDirectory $workingDirectory): int
    {
        $postRun       = new PostRunReport($this->clockFor($options));
        $eventStreamTo = $options->logEventsJson;

        try {
            // --no-configuration runs against no crucible.php at all: no
            // suites, so nothing to discover. It exists to prove the file is
            // not being read, which is the only thing it can prove here —
            // Crucible takes no positional paths to run instead.
            $loaded = $options->noConfiguration
                ? new LoadedConfiguration(Crucible::configure()->build(), '(none)')
                : (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $configuration = $loaded->configuration;

        try {
            $note = $this->applyCompatibilityPolicy($configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        if ($note !== null) {
            print $note . PHP_EOL . PHP_EOL;
        }

        // The php.ini advisory, opt-in: a machine misconfigured for
        // development is worth saying once, before the run, and is
        // never a verdict about the code.
        if ($options->warnWhenPhpIsNotConfiguredForDevelopment === true) {
            foreach (PhpConfigurationCheck::warnings() as $warning) {
                print $warning . PHP_EOL;
            }
        }

        $overrides = $this->overrides($options, $workingDirectory);

        $this->applyPhpSettings($configuration, $workingDirectory, $overrides, $options->includePaths);

        $emitter = new Emitter($this->clockFor($options));

        // The issue log feeds the threshold policy and the baseline
        // update; as a listener it sees worker results too.
        $issueLog = new IssueLog();
        $emitter->subscribe($issueLog);

        // Which tests ended skipped, incomplete, or errored, and why: the
        // tally counts them, the --display-* family names them.
        $outcomeLog = new OutcomeLog();
        $emitter->subscribe($outcomeLog);

        // How each test ended and how long it took, for the XML
        // coverage index's <tests>: coverage says which test touched a
        // line, not how that test finished.
        $testRecords = new TestRecordLog();
        $emitter->subscribe($testRecords);

        // Run-scoped checks (command gates) collected off the stream —
        // like IssueLog, so they are seen wherever they are emitted.
        $checkLog = new CheckLog();
        $emitter->subscribe($checkLog);

        // The G4 tallies: flaky passes and quarantined failures, for
        // the exit-code policy and the post-run notes.
        $flakiness = new FlakinessLog();
        $emitter->subscribe($flakiness);

        // What an --update-snapshots run recorded (D-066) — the
        // counts ride test:finish, so workers tally identically.
        $snapshotLog = new SnapshotLog();
        $emitter->subscribe($snapshotLog);

        if ($eventStreamTo !== null) {
            $stream = fopen($eventStreamTo, 'w');

            if ($stream === false) {
                printf('Cannot open %s for the event stream.' . PHP_EOL, $eventStreamTo);

                return 1;
            }

            $emitter->subscribe(new NdjsonWriter($stream));
        }

        // The same stream rendered for a person rather than a parser:
        // one line per event, the verbose form keeping the payload.
        // --no-logging has already cleared both (see CliOptions).
        foreach ([[$options->logEventsText, false], [$options->logEventsVerboseText, true]] as [$textLogTo, $verbose]) {
            if ($textLogTo === null) {
                continue;
            }

            $stream = fopen($textLogTo, 'w');

            if ($stream === false) {
                printf('Cannot open %s for the event stream.' . PHP_EOL, $textLogTo);

                return 1;
            }

            $emitter->subscribe(new EventTextWriter($stream, $verbose, $options->withTelemetry));
        }

        // Subscriber plugins (junit built in, any third party's own
        // subscriber registered the same way in crucible.php) —
        // --log-junit= is convenience sugar for the same underlying
        // "key:path" selection --subscriber= takes, so there's exactly
        // one dispatch mechanism under both spellings. A subscriber's
        // params reach its own constructor (not a call-time argument
        // like a report format's), so resolve() here both validates
        // and constructs, and the result subscribes straight to the
        // emitter — no wrapper listener, unlike GenericReportWriter.
        $selectedSubscribers = [];

        foreach ($options->subscriber as $entry) {
            [$key, $path] = array_pad(explode(':', $entry, 2), 2, null);

            if ($key === null || $path === null || $key === '' || $path === '') {
                printf('Malformed --subscriber value "%s": expected "key:path".' . PHP_EOL, $entry);

                return 1;
            }

            $selectedSubscribers[$key] = $path;
        }

        if ($options->logJunit !== null) {
            $selectedSubscribers['junit'] = $options->logJunit;
        }

        // The documentation view's file targets take the same path: one
        // dispatch mechanism under every spelling.
        foreach ([
            'otr'             => $options->logOtr,
            'testdox-text'    => $options->testdoxText,
            'testdox-html'    => $options->testdoxHtml,
            'testdox-summary' => $options->testdoxSummary,
        ] as $key => $path) {
            if ($path !== null) {
                $selectedSubscribers[$key] = $path;
            }
        }

        if ($selectedSubscribers !== []) {
            $subscribers = $configuration->subscribers;

            foreach ($selectedSubscribers as $key => $path) {
                if (!isset($subscribers[$key])) {
                    printf('Subscriber "%s" is not registered — add it via ->subscriber() in crucible.php.' . PHP_EOL, $key);

                    return 1;
                }

                $subscribers[$key]['params']['output'] = $path;
            }

            $subscriberRegistry = new SubscriberRegistry($subscribers);

            try {
                foreach (array_keys($selectedSubscribers) as $key) {
                    $emitter->subscribe($subscriberRegistry->resolve($key));
                }
            } catch (Exception $e) {
                print $e->getMessage() . PHP_EOL;

                return 1;
            }
        }

        // Report-format plugins (pdf/markdown built in, any third
        // party's own format registered the same way in crucible.php)
        // — --log-pdf=/--log-markdown= are convenience sugar for the
        // same underlying "key:path" selection --report= takes, so
        // there's exactly one dispatch mechanism under both spellings.
        $selectedReportFormats = [];

        foreach ($options->report as $entry) {
            [$key, $path] = array_pad(explode(':', $entry, 2), 2, null);

            if ($key === null || $path === null || $key === '' || $path === '') {
                printf('Malformed --report value "%s": expected "key:path".' . PHP_EOL, $entry);

                return 1;
            }

            $selectedReportFormats[$key] = $path;
        }

        if ($options->logPdf !== null) {
            $selectedReportFormats['pdf'] = $options->logPdf;
        }

        if ($options->logMarkdown !== null) {
            $selectedReportFormats['markdown'] = $options->logMarkdown;
        }

        if ($selectedReportFormats !== []) {
            $reportFormats = $configuration->reportFormats;

            foreach ($selectedReportFormats as $key => $path) {
                if (!isset($reportFormats[$key])) {
                    printf('Report format "%s" is not registered — add it via ->reportFormat() in crucible.php.' . PHP_EOL, $key);

                    return 1;
                }

                $reportFormats[$key]['params']['output'] = $path;
            }

            $registry = new ReportFormatRegistry($reportFormats);

            try {
                foreach (array_keys($selectedReportFormats) as $key) {
                    $registry->resolve($key);
                }
            } catch (Exception $e) {
                print $e->getMessage() . PHP_EOL;

                return 1;
            }

            $emitter->subscribe(new GenericReportWriter($registry, array_keys($selectedReportFormats), $configuration->reportTitle, $options->reproducible));
        }

        $order = $configuration->executionOrder;

        if ($options->orderBy !== null) {
            $order = ExecutionOrder::tryFrom($options->orderBy);

            if (!$order instanceof ExecutionOrder) {
                printf('Unknown --order-by value "%s": use default, defects, duration, random, reverse or size.' . PHP_EOL, $options->orderBy);

                return 1;
            }
        }

        $cacheDirectory = $options->cacheDirectory ?? $configuration->cacheDirectory;
        $cacheResult    = $options->cacheResult ?? $configuration->cacheResult;
        $cacheFile      = $workingDirectory->absolute($cacheDirectory) . '/results.json';
        $cache          = $cacheResult ? ResultCache::load($cacheFile) : new ResultCache();

        $propertyFile = $workingDirectory->absolute($cacheDirectory) . '/property-failures.json';

        if ($cacheResult) {
            $emitter->subscribe(new ResultCacheWriter($cache, $cacheFile));
            $emitter->subscribe(new PropertyFailureWriter($propertyFile));
        }

        $seed = 0;

        // Both history-aware orderings read the result cache, so asking
        // for one with recording off is asking for nothing to happen —
        // said out loud rather than silently ignored, as in the spec.
        if (!$cacheResult && ($order === ExecutionOrder::DefectsFirst || $order === ExecutionOrder::Duration)) {
            printf(
                'Tests cannot be ordered by %s because recording of the test run history is disabled.' . PHP_EOL . PHP_EOL,
                $order->value,
            );
        }

        if ($order === ExecutionOrder::Random) {
            $seed = $options->randomOrderSeed ?? random_int(0, 2147483647);

            printf('Random seed: %d' . PHP_EOL . PHP_EOL, $seed);
        }

        if ($options->testsuite !== null) {
            $known = array_map(static fn($suite): string => $suite->name, $configuration->testSuites);

            foreach ($options->testsuite as $requested) {
                if (!in_array($requested, $known, true)) {
                    printf('The requested test suite "%s" is not configured.' . PHP_EOL, $requested);

                    return 1;
                }
            }
        }

        // The longest silence in a run that has not started yet: every
        // test file is parsed before a single one executes. The grid
        // opens onto that silence rather than after it, so the frame is
        // already up, ticking, saying what it is waiting for — and the
        // cells appear in it rather than the whole thing arriving at
        // once over a line that said something else.
        $opened = $this->openGrid($options, $configuration, $workingDirectory->relative($loaded->path));

        $discover = static fn(): array => (new TestDiscoverer())->discover(
            $configuration,
            $workingDirectory,
            $options->testsuite,
            $options->excludeTestsuite,
            $options->testSuffixes,
        );

        try {
            $groups = $opened instanceof MapView ? $discover() : $this->discoveryMark()->sweepWhile($discover);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            // 2, not 1: discovery failing means no suite was built, and
            // HELP.md's table reserves 2 for "the run could not proceed
            // — a configuration error". 1 says "failures, errors",
            // which claims tests ran and disagreed. A CI job acts on
            // those differently: fix the tests, or fix the setup.
            return 2;
        }

        // The progress view, chosen AFTER discovery so a Pest.php
        // pest()->printer()->compact() declaration is known (D-066);
        // nothing is emitted before the run itself. --view=<key> is
        // the general selection mechanism (any registered progress
        // view, third-party or built-in) and wins outright, being the
        // most explicit; absent that, the existing precedence holds:
        // the CLI flag still beats the suite file (D-023), and
        // testdox/teamcity/console remain mutually exclusive — exactly
        // one view is ever subscribed.
        $compact       = PestScopes::printerPreference() === 'compact';
        $testdoxWanted = $options->testdox ?? ($compact ? false : $configuration->testdox);

        // The default is the map wherever a screen can be redrawn, and the
        // dot-per-test view wherever one cannot — a pipe, a CI log, a dumb
        // terminal. {@see Capabilities::animation()} draws that line, and
        // {@see MapView::isDefaultFor()} is the same question the banner
        // asked earlier, so the two cannot disagree about what is coming.
        $selectedView = match (true) {
            $options->view !== null                      => $options->view,
            $testdoxWanted                               => 'testdox',
            $options->teamcity === true                  => 'teamcity',
            Capabilities::animation(Runtime::terminal()) => MapView::DEFAULT_KEY,
            default                                      => 'console',
        };

        $progressViews = $configuration->progressViews;
        $viewRegistry  = new ProgressViewRegistry($progressViews);

        if (!$viewRegistry->has($selectedView)) {
            printf('Progress view "%s" is not registered — add it via ->progressView() in crucible.php.' . PHP_EOL, $selectedView);

            return 1;
        }

        // These are not user-configured params — they are engine-computed
        // from the command line the same run already resolves — so they are
        // injected here, mirroring how --log-junit's path is injected into
        // a subscriber's params. A view that does not declare one simply
        // does not receive it, which is how a third-party view stays free
        // of options it never agreed to.
        $computed = $this->viewParams($options, $configuration, $workingDirectory->relative($loaded->path));
        $declared = array_column($viewRegistry->describe($selectedView)->params, 'name');
        $injected = [];

        foreach ($computed as $name => $value) {
            if ($value !== null && in_array($name, $declared, true)) {
                $injected[$name] = $value;
            }
        }

        // A view that names the configuration itself is not told it twice:
        // printed here, the line would be covered by the frame that opens
        // a moment later and left under the report as though it were part
        // of the run's output.
        if (!$options->noOutput && !in_array('configuration', $declared, true)) {
            printf('Configuration: %s' . PHP_EOL . PHP_EOL, $loaded->path);
        }

        if ($injected !== []) {
            $progressViews[$selectedView] = [
                'class'  => $progressViews[$selectedView]['class'],
                'params' => [...$progressViews[$selectedView]['params'], ...$injected],
            ];
            $viewRegistry = new ProgressViewRegistry($progressViews);
        }

        // --no-output means no output: the view is never subscribed, so
        // nothing writes a character. Everything else about the run —
        // the exit code, the log targets, the reports — is unchanged.
        if (!$options->noOutput) {
            try {
                // The grid may already be on screen, holding the frame
                // discovery ran on. Resolving a second one would open a
                // second surface over the first and leave it behind.
                $emitter->subscribe($opened ?? $viewRegistry->resolve($selectedView, $options->stderr ? STDERR : STDOUT));
            } catch (Exception $e) {
                print $e->getMessage() . PHP_EOL;

                return 1;
            }
        }

        // --log-teamcity is the teamcity view sent to a file instead of the
        // terminal, resolved through the same registry entry --teamcity
        // uses. It does not replace the progress output, and --no-output
        // does not silence it: it is a log target, not output.
        if ($options->logTeamcity !== null) {
            $stream = fopen($options->logTeamcity, 'w');

            if ($stream === false) {
                printf('Cannot open %s for the TeamCity log.' . PHP_EOL, $options->logTeamcity);

                return 1;
            }

            try {
                $emitter->subscribe($viewRegistry->resolve('teamcity', $stream));
            } catch (Exception $e) {
                print $e->getMessage() . PHP_EOL;

                return 1;
            }
        }

        // Subscribed after the progress printer so its block lands below
        // the summary rather than interleaved with the dots.
        if ($options->profile) {
            $emitter->subscribe(new ProfileReporter(STDOUT, new Style($this->colorsEnabled($options, $configuration))));
        }

        // The Vitest suites this run folds in (D-079). Full by default;
        // narrowed to the change set under impact selection below, where
        // an affected JS suite also keeps the run alive when no PHP test
        // is (a changed .vue with no PHP dependents).
        $vitestSuites = $configuration->vitest;
        $jsAffected   = false;

        // Impact selection (G3): narrow to the tests whose dependency
        // closure intersects the change set. Runs before the name and
        // group filters, which then narrow further.
        if ($options->changed !== null || $options->related !== [] || $options->dirty) {
            // --dirty is --changed against HEAD: fromGit() already
            // reports staged, unstaged AND untracked files, which is
            // exactly what "uncommitted" means.
            $reference = $options->dirty && $options->changed === null ? 'HEAD' : $options->changed;

            $changed = $reference !== null
                ? ChangedFiles::fromGit($workingDirectory, $reference)
                : new ChangedFiles();

            if (is_string($changed)) {
                printf('Option %s: %s' . PHP_EOL, $options->dirty && $options->changed === null ? '--dirty' : '--changed', $changed);

                return 1;
            }

            $files = $changed->files;

            foreach ($options->related as $related) {
                $absolute = realpath($workingDirectory->absolute($related));

                if ($absolute === false) {
                    printf('Option --related: %s does not exist.' . PHP_EOL, $related);

                    return 1;
                }

                $files[] = $absolute;
            }

            $environment = [$loaded->path];

            $configuredBootstrap = $options->bootstrap ?? $configuration->bootstrap;

            if ($configuredBootstrap !== null) {
                $bootstrap = realpath($workingDirectory->absolute($configuredBootstrap));

                if ($bootstrap !== false) {
                    $environment[] = $bootstrap;
                }
            }

            // Coverage-observed edges (D-041), when a coverage run has
            // produced them: execution sees the dynamic dependencies
            // static analysis cannot.
            $observed = [];

            foreach (CoverageMap::load($workingDirectory->absolute($options->cacheDirectory ?? $configuration->cacheDirectory) . '/coverage-map.json') as $testFile => $sourceFiles) {
                $edges = [];

                foreach ($sourceFiles as $sourceFile) {
                    $absolute = realpath($workingDirectory->absolute($sourceFile));

                    if ($absolute !== false) {
                        $edges[] = $absolute;
                    }
                }

                $absoluteTest = realpath($workingDirectory->absolute($testFile));

                if ($absoluteTest !== false && $edges !== []) {
                    $observed[$absoluteTest] = $edges;
                }
            }

            // The Vitest suites answer for their own directories (D-080),
            // so the PHP tier must not report those changes as accounted
            // for by nothing — both tiers print, and they would disagree.
            $claimedPaths = [];

            foreach ($configuration->vitest as $suite) {
                $claimed = realpath(
                    str_starts_with($suite->directory, '/')
                        ? $suite->directory
                        : $workingDirectory->path . '/' . $suite->directory,
                );

                if ($claimed !== false) {
                    $claimedPaths[] = $claimed;
                }
            }

            // The graph memoizes only within one process, so without an
            // index every run re-reads and re-scans every source file to
            // find its references. The index replays a file whose bytes
            // have not changed, keyed on a fingerprint of everything
            // else the answer depends on — the autoload map, and the
            // Pest.php/Datasets above a file, which change what it
            // depends on without touching it.
            $graph = new DependencyGraph(
                $workingDirectory->path,
                observedEdges: $observed,
                index: new DependencyIndex(
                    $workingDirectory->path . '/' . $configuration->cacheDirectory,
                    ImpactFingerprint::of($workingDirectory->path, $groups),
                    $workingDirectory->path,
                ),
            );

            $impact = (new ImpactSelection(
                $graph,
                $workingDirectory->path,
                $environment,
                $configuration->impactRules,
                $claimedPaths,
            ))->select($groups, new ChangedFiles($files, $changed->deleted));

            // Written after the selection, so a run that resolved
            // nothing new writes nothing.
            $graph->persist();

            foreach ($impact->notes as $impactNote) {
                print $impactNote . PHP_EOL;
            }

            // The JS tier of the same change set (D-080): narrow the
            // configured Vitest suites the way ImpactSelection narrowed
            // the PHP groups. An affected suite makes the run non-empty
            // even when no PHP test is.
            if ($configuration->vitest !== []) {
                [$vitestSuites, $vitestNotes] = VitestImpact::select(
                    $configuration->vitest,
                    new ChangedFiles($files, $changed->deleted),
                    $workingDirectory,
                );
                $jsAffected = $vitestSuites !== [];

                foreach ($vitestNotes as $vitestNote) {
                    print $vitestNote . PHP_EOL;
                }
            }

            print PHP_EOL;

            if ($impact->groups !== null) {
                if ($impact->groups === [] && !$jsAffected) {
                    print 'No tests are affected by the given changes.' . PHP_EOL;

                    return 0;
                }

                $groups = $impact->groups;
            }
        }

        // Count the work-in-progress tests --no-wip drops, from the
        // discovered set, so the exclusion is never silent.
        $wipExcluded = 0;

        if ($options->noWip) {
            foreach ($groups as $group) {
                foreach ($group->tests as $test) {
                    $marker = $test->metadata->first(Todo::class);

                    if ($marker instanceof Todo && $marker->status === TodoStatus::Wip) {
                        ++$wipExcluded;
                    }
                }
            }
        }

        $testIds = $this->idSelection($options, $workingDirectory);

        if (is_string($testIds)) {
            print $testIds . PHP_EOL;

            return 1;
        }

        $testFiles = $this->fileSelection($options, $workingDirectory);

        if (is_string($testFiles)) {
            print $testFiles . PHP_EOL;

            return 1;
        }

        $groups = (new TestSelection(
            $options->filter !== null ? new NameFilter($options->filter) : null,
            $options->groups,
            $options->excludeGroups,
            $options->todos,
            $options->assignee,
            $options->issue,
            $options->noWip,
            $options->excludeFilter !== null ? new NameFilter($options->excludeFilter) : null,
            $options->covers,
            $options->uses,
            $options->requiresPhpExtension,
            $testIds,
            $testFiles,
        ))->apply($groups);

        // Listing answers a question about the suite rather than running
        // it, so it prints the selection as narrowed and stops — every
        // selecting option above still applies, which is what makes
        // `--list-tests --group slow` the useful form.
        if ($options->list !== null) {
            $this->printListing($options->list, $groups, $configuration);

            return 0;
        }

        if ($wipExcluded > 0) {
            printf(
                '%d work-in-progress test%s excluded (--no-wip).' . PHP_EOL,
                $wipExcluded,
                $wipExcluded === 1 ? '' : 's',
            );
        }

        if ($options->shard !== null) {
            $shard = Shard::fromString($options->shard);

            if (!$shard instanceof Shard) {
                printf('Option --shard expects M/N with 1 <= M <= N, got "%s".' . PHP_EOL, $options->shard);

                return 1;
            }

            $groups = $shard->apply($groups);
        }

        // A JS-only change (D-080) leaves no PHP group but an affected
        // Vitest suite: the run proceeds so the suite folds in through
        // the after-tests hook, bracketed by the normal run:start/finish.
        if ($groups === [] && !$jsAffected) {
            // An empty todo listing is a healthy answer, not a failed
            // selection — the D-036 distinction ('No tests are
            // affected' exits 0 where 'No tests found' exits 1).
            if ($options->todos || $options->assignee !== null || $options->issue !== null) {
                print 'No todos match.' . PHP_EOL;

                return 0;
            }

            print 'No tests found.' . PHP_EOL;

            // Failing on an empty suite is the default, here as in the
            // spec; --do-not-fail-on-empty-test-suite is what a repository
            // with an optional suite reaches for.
            return ($options->failOnEmptyTestSuite ?? $configuration->failOnEmptyTestSuite) ? 1 : 0;
        }

        // Repeat before scheduling, so the repetitions are ordinary
        // members of the plan: ordered, counted, and dispatched like
        // any other test.
        $groups = Repetition::apply($groups, $overrides->repeat);
        $groups = (new Scheduler($order, $seed, $cache, $overrides->resolveDependencies ?? true))->schedule($groups);

        // The D-071 completeness half the CLI owns: whether any option
        // narrowed the plan below the configured suite. The other half
        // — did every planned test finish — is the runner's, and only
        // both together mark run:finish complete (the pruning gate).
        $fullSuite = $options->filter === null
            && $options->groups === []
            && $options->excludeGroups === []
            && !$options->todos
            && $options->assignee === null
            && $options->issue === null
            && !$options->noWip
            && $options->testsuite === null
            && $options->changed === null
            && $options->related === []
            && $options->shard === null
            && $options->excludeFilter === null
            && $options->excludeTestsuite === []
            && $options->covers === []
            && $options->uses === []
            && $options->requiresPhpExtension === []
            && $options->runTestIds === []
            && $options->testIdFilterFile === null
            && $options->testFilesFile === null
            && !$options->flakes;

        // Obsolete-snapshot pruning (D-071): subscription-gated to
        // update runs — recording and pruning share D-042's one
        // explicit posture — and self-gated on the complete flag, so
        // a filtered -u run still updates only what it ran.
        if ($options->updateSnapshots) {
            $testFiles = [];

            foreach ($groups as $group) {
                foreach ($group->tests as $test) {
                    $testFiles[$test->id->file] = true;
                }
            }

            $emitter->subscribe(new SnapshotPruner($workingDirectory, array_keys($testFiles)));
        }

        $parallel = max(1, $options->parallel ?? 1);

        // Inline-snapshot recording (D-076) rewrites the test source, so
        // it is permitted only on a fully sequential run — never from a
        // worker, and never while parallel workers might touch the same
        // file. The same $runner backs the supervisor's in-process
        // portion, so the flag is false whenever the isolated path runs.
        $sequential = $parallel <= 1 && !$options->processIsolation && !$this->needsProcessIsolation($groups);

        $baselineFile = $overrides->baselineFile
            ?? $workingDirectory->absolute($cacheDirectory) . '/deprecations-baseline.json';

        $runnerOptions = RunnerOptions::fromConfiguration(
            $configuration,
            stopOnDefect: $options->stopOnDefect,
            stopOnError: $options->stopOnError,
            stopOnFailure: $options->stopOnFailure,
            stopOnRisky: $options->stopOnRisky,
            stopOnSkipped: $options->stopOnSkipped,
            stopOnIncomplete: $options->stopOnIncomplete,
            stopOnDeprecation: $options->stopOnDeprecation,
            stopOnNotice: $options->stopOnNotice,
            stopOnWarning: $options->stopOnWarning,
            projectDirectories: $this->projectDirectories($configuration, $workingDirectory),
            workingDirectory: $workingDirectory,
            deprecationBaseline: $options->updateDeprecationsBaseline || $overrides->ignoreBaseline === true
                ? []
                : DeprecationBaseline::load($baselineFile),
            retries: $options->retries,
            propertyFailures: $cacheResult ? PropertyFailures::load($propertyFile) : [],
            updateSnapshots: $options->updateSnapshots,
            strictCoverage: $options->strictCoverage ? true : null,
            fullSuite: $fullSuite,
            inlineSnapshotRewrite: $options->updateSnapshots && $sequential,
            overrides: $overrides,
        );

        // The browser tier's ambient runtime (default OFF; the gate
        // itself lives in Session::start). --browser/--debug override
        // the configured engine and headed posture for this run.
        $browserConfiguration = $configuration->browser;

        if ($options->browser !== null || $options->debug) {
            $engine = $options->browser === null
                ? $browserConfiguration->engine
                : BrowserEngine::tryFrom($options->browser);

            if ($engine === null) {
                print sprintf('Unknown --browser "%s" (chrome, firefox, safari).', $options->browser) . PHP_EOL;

                return 1;
            }

            $browserConfiguration = new BrowserConfiguration(
                $browserConfiguration->enabled,
                $engine,
                $browserConfiguration->timeoutMs,
                $browserConfiguration->playwrightRoot,
                $options->debug || $browserConfiguration->headed,
                $browserConfiguration->requestHandler,
            );
        }

        Browsing::configure($browserConfiguration, $workingDirectory);
        Architecture::configure($configuration->source, $workingDirectory);
        Expectation::configure($configuration->quirks);

        // Coverage (D-041) is strictly opt-in: a driver window per
        // test costs real time, and a driver is an install decision.
        $collector         = null;
        $coverageDirectory = null;

        if ($options->wantsCoverage()) {
            $driver = DriverFactory::detect($options->coverageBranch, $options->pathCoverage);

            if (is_string($driver)) {
                print 'Coverage: ' . $driver . PHP_EOL;

                return 1;
            }

            $scope = $postRun->coverageScope($configuration, $workingDirectory, $overrides->coverageFilter);

            if ($scope === []) {
                print 'Coverage: configure ->source(include: [...]) — the source scope is the coverage scope.' . PHP_EOL;

                return 1;
            }

            $collector         = new CoverageCollector($driver, $scope);
            $coverageDirectory = $workingDirectory->absolute($cacheDirectory) . '/coverage.tmp';

            if (!is_dir($coverageDirectory)) {
                mkdir($coverageDirectory, 0o777, true);
            }

            $stale = glob($coverageDirectory . '/*.json');

            foreach ($stale === false ? [] : $stale as $leftover) {
                unlink($leftover);
            }
        }

        // Shared with the supervisor: a prerequisite that runs here, in
        // the in-process remainder, still has to reach a dependent that
        // runs in a worker.
        $dependencyValues = new DependencyValues();
        $runner           = new TestRunner($emitter, $runnerOptions, coverage: $collector, dependencies: $dependencyValues);
        $summary          = null;

        // Run-scoped checks run inside the run bracket via the runner's
        // after-tests hook, so their CheckFinished events precede
        // run:finish. Null when none are configured.
        // Extensions are dispatched by role (D-078): a Check inspects the
        // project after the suite, exactly like a command gate but
        // in-process. Other roles are ignored here until they ship.
        // The spec's --extension registers a plugin by class name for one
        // run; ->extension() registers an instance for every run. Both end
        // up in the same role dispatch, so a CLI-registered Check is
        // indistinguishable from a configured one once it is here.
        $registered = $this->extensionsFromCli($options);

        if (is_string($registered)) {
            print $registered . PHP_EOL;

            return 1;
        }

        $checks = array_values(array_filter(
            $options->noExtensions ? [] : [...$configuration->extensions, ...$registered],
            static fn(Extension $extension): bool => $extension instanceof Check,
        ));
        $vitest = $vitestSuites;

        $afterTests = $configuration->commandGates === [] && $checks === [] && $vitest === []
            ? null
            : static function () use ($emitter, $configuration, $checks, $vitest, $workingDirectory): ?RunSummary {
                // JS tests are tests: their test:finish events lead, then
                // the run-scoped checks' check:finish. The Vitest delta
                // folds into the tally; checks vote the exit code instead.
                $delta = $vitest === [] ? null : (new VitestRunner($emitter))->run($vitest, $workingDirectory);

                (new CheckRunner($emitter))->run($configuration->commandGates, $checks, $workingDirectory);

                return $delta;
            };

        // A JS-only run (D-080) has no PHP group to parallelize or
        // isolate: the sequential runner opens the stream, the folded
        // Vitest suite fills it. Only PHP groups drive the supervisor.
        if ($groups !== [] && ($parallel > 1 || $options->processIsolation || $this->needsProcessIsolation($groups))) {
            $binary = realpath($argv[0] ?? '');

            if ($binary === false) {
                print 'Cannot resolve the crucible binary path for worker processes.' . PHP_EOL;

                return 1;
            }

            // Named throughout: the supervisor takes two dozen arguments
            // and a new one inserted mid-list would otherwise shift every
            // argument after it into the wrong parameter, silently.
            $summary = (new Supervisor(
                emitter: $emitter,
                inProcessRunner: $runner,
                history: $cache,
                parser: new EventParser(),
                crucibleBinary: $binary,
                configurationPath: $loaded->path,
                workingDirectory: $workingDirectory,
                order: $order,
                seed: $seed,
                parallel: $parallel,
                retries: $options->retries,
                coverageDirectory: $coverageDirectory,
                updateSnapshots: $options->updateSnapshots,
                coverageBranch: $options->coverageBranch,
                pathCoverage: $options->pathCoverage,
                strictCoverage: $options->strictCoverage ? true : null,
                browserOverride: $options->browser === null || $options->browser === '' ? null : $options->browser,
                debugOverride: $options->debug,
                fullSuite: $fullSuite,
                overrides: $overrides,
                includePaths: $options->includePaths,
                processIsolation: $options->processIsolation,
                dependencyValues: $dependencyValues,
            ))->run($groups, $afterTests);
        } else {
            $summary = $runner->run($groups, $afterTests);
        }

        // Apply buffered inline-snapshot rewrites (D-076) once the run
        // is over — the tests are done, so no source file is mid-read.
        // Only a sequential run ever records intents (the gate above).
        if (InlineSnapshotWriter::hasPending()) {
            $written = InlineSnapshotWriter::flush();

            if ($written > 0) {
                print sprintf('Inline snapshots: %d written.', $written) . PHP_EOL;
            }
        }

        $coverageFloorBreach = null;

        if ($collector instanceof CoverageCollector) {
            $coverageFloorBreach = $postRun->coverageFloor($postRun->reportCoverage(
                $collector,
                $options,
                $workingDirectory,
                $cacheDirectory,
                $this->sizedRecords($testRecords->records(), $groups),
            ), $options->minCoverage);
        }

        if ($options->updateDeprecationsBaseline) {
            // Merge, don't rewrite: workers suppress already-baselined
            // deprecations at capture, so a rewrite from visible ones
            // would silently drop acknowledged entries.
            DeprecationBaseline::save($baselineFile, $issueLog->issues(), DeprecationBaseline::load($baselineFile));

            printf('Deprecations baseline written to %s.' . PHP_EOL, $baselineFile);
        } elseif ($options->generateBaseline !== null) {
            // A generate is not a merge: the run read no baseline, so what
            // it saw is the whole truth and writing it fresh is correct.
            DeprecationBaseline::save($baselineFile, $issueLog->issues());

            printf('Deprecations baseline written to %s.' . PHP_EOL, $baselineFile);
        }

        // --no-output silences the post-run block without changing it: the
        // breaches still count, the checks still vote. An output switch
        // must not be able to change a verdict, so the work runs either
        // way and only what it wrote is discarded.
        if ($options->noOutput) {
            ob_start();
        }

        $postRun->printIssueDisplays($options, $issueLog, $outcomeLog);

        $breaches = $postRun->thresholdBreaches($configuration, $issueLog);

        if ($coverageFloorBreach !== null) {
            $breaches[] = $coverageFloorBreach;
        }

        foreach ($breaches as $breach) {
            print $breach . PHP_EOL;
        }

        if ($snapshotLog->created() > 0 || $snapshotLog->updated() > 0) {
            printf('Snapshots: %d created, %d updated.' . PHP_EOL, $snapshotLog->created(), $snapshotLog->updated());
        }

        $postRun->printFlakiness($flakiness);
        $postRun->printFailuresOutsideDiff($flakiness, $cache, $configuration, $options, $workingDirectory, $cacheDirectory);

        // Run-scoped checks (command gates) already ran inside the run
        // bracket and rode the stream; here we print their separate
        // tally and let a failing one vote the exit code with its reason.
        $checkFailed = $postRun->printChecks($checkLog);

        if ($options->noOutput) {
            ob_end_clean();
        }

        $exitCode = $postRun->exitCode($summary, $configuration, $options, $flakiness, $issueLog);

        return $breaches !== [] || $checkFailed ? max(1, $exitCode) : $exitCode;
    }


    /**
     * The run's clock. `--reproducible` freezes it at `SOURCE_DATE_EPOCH`
     * when the environment names one — a real date, the source's, which
     * is identical for everyone reproducing the same commit — and at the
     * epoch otherwise, for the formats whose timestamp attribute is
     * required and so cannot simply be left out.
     *
     * Everything that stamps a time already routes through this, so one
     * choice here settles the event stream, both report renderers and
     * all four coverage writers at once.
     */
    private function clockFor(CliOptions $options): Clock
    {
        return $options->reproducible ? SourceDateEpoch::clock() : new SystemClock();
    }

    /**
     * The directory prefixes that count as "own code" for deprecation
     * attribution: the configured test suites plus the source
     * include list.
     *
     *
     * @return list<non-empty-string>
     */
    private function projectDirectories(Configuration $configuration, WorkingDirectory $workingDirectory): array
    {
        $prefixes = [];

        foreach ($configuration->testSuites as $suite) {
            foreach ($suite->directories as $directory) {
                $prefixes[] = $workingDirectory->absolute($directory) . '/';
            }

            foreach ($suite->files as $file) {
                $prefixes[] = $workingDirectory->absolute($file);
            }
        }

        foreach ($configuration->source->includeDirectories as $directory) {
            $prefixes[] = $workingDirectory->absolute($directory) . '/';
        }

        foreach ($configuration->source->includeFiles as $file) {
            $prefixes[] = $workingDirectory->absolute($file);
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * The params a run computes for whichever view it selects.
     *
     * Not user configuration — engine-computed from the command line the
     * same run already resolved — so they are injected rather than
     * declared, the way a subscriber's log path is. A view that does not
     * declare one never receives it.
     *
     * @return array<string, bool|int|string|null>
     */
    private function viewParams(CliOptions $options, Configuration $configuration, string $configurationPath): array
    {
        return [
            'colors'        => $this->colorsEnabled($options, $configuration),
            'columns'       => $options->columns,
            'progress'      => !$options->noProgress,
            'results'       => !$options->noResults,
            'reverseList'   => $options->reverseList,
            'compact'       => $options->compact,
            'configuration' => $configurationPath,
        ];
    }

    /**
     * The grid, opened before discovery so discovery happens on it.
     *
     * ⚠ Selected here with the Pest printer preference still unknown,
     * because a `Pest.php` declares it while discovery loads it. That
     * preference can only turn testdox OFF — {@see MapView::isDefaultFor()}
     * is then reached by MORE runs, never fewer — so a provisional
     * answer of "grid" is never overturned by the real one, and a frame
     * opened on it is never opened in error.
     */
    private function openGrid(CliOptions $options, Configuration $configuration, string $configurationPath): ?MapView
    {
        if ($options->noOutput || $options->view !== null && !MapView::isDefaultFor($options->view, false, false, Runtime::terminal())) {
            return null;
        }

        if (!MapView::isDefaultFor($options->view, $options->testdox ?? $configuration->testdox, $options->teamcity === true, Runtime::terminal())) {
            return null;
        }

        $key      = $options->view ?? MapView::DEFAULT_KEY;
        $declared = ['fullscreen', 'colors', 'columns', 'results', 'reverseList', 'compact', 'configuration'];
        $params   = $configuration->progressViews[$key]['params'] ?? [];

        foreach ($this->viewParams($options, $configuration, $configurationPath) as $name => $value) {
            if ($value !== null && in_array($name, $declared, true)) {
                $params[$name] = $value;
            }
        }

        $view = new ProgressViewRegistry([$key => ['class' => MapView::class, 'params' => $params]])
            ->resolve($key, $options->stderr ? STDERR : STDOUT);

        if (!$view instanceof MapView) {
            return null;
        }

        $view->opening('discovering tests…');

        return $view;
    }

    private function colorsEnabled(CliOptions $options, Configuration $configuration): bool
    {
        $when = $options->colors ?? ($configuration->colors ? 'auto' : 'never');

        return match ($when) {
            'always' => true,
            'never'  => false,
            default  => stream_isatty(STDOUT),
        };
    }

    /**
     * @param list<TestGroup> $groups
     */
    private function needsProcessIsolation(array $groups): bool
    {
        foreach ($groups as $group) {
            foreach ($group->tests as $test) {
                if ($test->metadata->has(RunInSeparateProcess::class)
                    || $test->metadata->has(RunClassInSeparateProcess::class)
                    || $test->metadata->has(RunTestsInSeparateProcesses::class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The coexistence policy (DESIGN.md D-019): aliases load
     * automatically only when the real PHPUnit is absent; with PHPUnit
     * installed they require an explicit opt-in, which fails fast if
     * PHPUnit classes are already loaded. Runs before the bootstrap so
     * the aliases win the namespace. Returns the user-facing note
     * instead of printing it — worker processes must keep stdout for
     * the protocol.
     */
    private function applyCompatibilityPolicy(Configuration $configuration): ?string
    {
        // The Mockery-name aliases follow the same coexistence
        // principle independently: on exactly when the real package
        // is absent (D-060).
        if (MockeryCompatibility::shouldAutoEnable()) {
            MockeryCompatibility::load();
        }

        if ($configuration->phpunitCompatibility === true) {
            PhpUnitCompatibility::load();

            return null;
        }

        if ($configuration->phpunitCompatibility === false) {
            return null;
        }

        // Auto policy: drop-in when the real PHPUnit is absent.
        if (PhpUnitCompatibility::shouldAutoEnable()) {
            PhpUnitCompatibility::load();

            return null;
        }

        return 'Note: phpunit/phpunit is installed, so PHPUnit-namespace compatibility aliases are'
            . PHP_EOL
            . 'disabled. Tests must extend Crucible\'s TestCase, or opt in with ->phpunitCompatibility().';
    }

    /**
     * Worker mode: manifest on stdin, NDJSON events on stdout, and
     * nothing else — no banner, no reporter. The exit code does not
     * carry test results; the run:finish event is the completion
     * handshake the supervisor trusts.
     */
    /**
     * The wordmark, set up to sweep with a label beside it.
     *
     * Slower than a spinner on purpose: a sweep reads as one movement
     * across the mark, and at a spinner's rate it reads as a flicker.
     */
    private function discoveryMark(): Splash
    {
        $splash           = new Splash(Runtime::terminal(), '◆ crucible');
        $splash->interval = self::DISCOVERY_FRAME_MS;
        $splash->suffix   = ConsoleStyle::none()->dim()->toAnsi() . '  discovering tests…' . "\e[0m";

        return $splash;
    }

    public function worker(): int
    {
        $postRun  = new PostRunReport();
        $manifest = WorkerManifest::fromJson((string) stream_get_contents(STDIN));

        if (!$manifest instanceof WorkerManifest) {
            fwrite(STDERR, 'Invalid worker manifest.' . PHP_EOL);

            return 2;
        }

        $cwd = getcwd();

        if ($cwd === false) {
            fwrite(STDERR, 'Cannot determine the current working directory.' . PHP_EOL);

            return 2;
        }

        $workingDirectory = new WorkingDirectory($cwd);

        try {
            $loaded = (new Loader())->load($workingDirectory, $manifest->configuration);
            // The note, if any, was already shown by the supervisor.
            $this->applyCompatibilityPolicy($loaded->configuration);
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);

            return 2;
        }

        $configuration = $loaded->configuration;

        // The ParaTest-compatible parallel contract: tokens must be in
        // the environment before the bootstrap loads, because
        // frameworks read them while booting (Laravel's
        // ParallelTesting keys databases and caches by TEST_TOKEN).
        if ($manifest->testToken !== null) {
            $this->exportEnv('TEST_TOKEN', (string) $manifest->testToken);
        }

        if ($manifest->uniqueTestToken !== null) {
            $this->exportEnv('UNIQUE_TEST_TOKEN', $manifest->uniqueTestToken);
        }

        // #[PreserveGlobalState(true)]: the parent's constants and
        // globals, restored *before* the bootstrap, so a bootstrap that
        // sets the same name still wins — the spec's ordering, and the
        // one that keeps a worker's own setup authoritative.
        GlobalStateExport::fromJson($manifest->globalState)?->restore();

        $this->applyPhpSettings($configuration, $workingDirectory, $manifest->overrides, $manifest->includePaths);

        // Cold mutation (D-082): when the parent injected a mutant into the
        // environment, arm its autoloader before discovery so the mutated
        // class loads in place of the original. A no-op for ordinary
        // workers. This is the portable path — no fork, so it runs where
        // the warm path (pcntl/posix) cannot.
        MutationAutoloader::fromEnvironment();

        // A worker's own clock is never asked for, whatever the run was
        // configured with: its envelope timestamps reach the supervisor
        // as NDJSON and the supervisor stamps the report from its own
        // emitter. Verified by running --parallel --reproducible twice
        // and comparing the reports byte for byte.
        $emitter = new Emitter(new SystemClock());
        $emitter->subscribe(new NdjsonWriter(STDOUT));

        try {
            $groups = $manifest->filter((new TestDiscoverer())->discover($configuration, $workingDirectory));
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);

            return 2;
        }

        // Ordering reads the cache; persistence stays with the
        // supervisor, which sees every result on the merged stream.
        $cacheFile = $workingDirectory->absolute($configuration->cacheDirectory) . '/results.json';
        $cache     = $configuration->cacheResult ? ResultCache::load($cacheFile) : new ResultCache();

        $groups = Repetition::apply($groups, $manifest->overrides->repeat);
        $groups = (new Scheduler(
            $manifest->order,
            $manifest->seed,
            $cache,
            $manifest->overrides->resolveDependencies ?? true,
        ))->schedule($groups);

        $runnerOptions = RunnerOptions::fromConfiguration(
            $configuration,
            projectDirectories: $this->projectDirectories($configuration, $workingDirectory),
            workingDirectory: $workingDirectory,
            deprecationBaseline: $manifest->overrides->ignoreBaseline === true
                ? []
                : DeprecationBaseline::load(
                    $manifest->overrides->baselineFile
                        ?? $workingDirectory->absolute($configuration->cacheDirectory) . '/deprecations-baseline.json',
                ),
            retries: $manifest->retries,
            propertyFailures: $configuration->cacheResult
                ? PropertyFailures::load($workingDirectory->absolute($configuration->cacheDirectory) . '/property-failures.json')
                : [],
            updateSnapshots: $manifest->updateSnapshots,
            strictCoverage: $manifest->strictCoverage,
            overrides: $manifest->overrides,
        );

        // Workers read the browser tier from the configuration file;
        // the CLI --browser/--debug overrides ride the manifest so a
        // parallel run behaves like its sequential twin (D-064).
        $workerBrowser = $configuration->browser;

        if ($manifest->browser !== null || $manifest->debug) {
            $engine = $manifest->browser === null
                ? $workerBrowser->engine
                : BrowserEngine::tryFrom($manifest->browser);

            if ($engine instanceof BrowserEngine) {
                $workerBrowser = new BrowserConfiguration(
                    $workerBrowser->enabled,
                    $engine,
                    $workerBrowser->timeoutMs,
                    $workerBrowser->playwrightRoot,
                    $manifest->debug || $workerBrowser->headed,
                    $workerBrowser->requestHandler,
                );
            }
        }

        Browsing::configure($workerBrowser, $workingDirectory);
        Architecture::configure($configuration->source, $workingDirectory);
        Expectation::configure($configuration->quirks);

        // Coverage in a worker (D-041): collect exactly like the
        // in-process path and leave one artifact file where the
        // manifest points; the supervisor side merges.
        $collector = null;

        if ($manifest->coverageArtifact !== null) {
            $driver = DriverFactory::detect($manifest->coverageBranch, $manifest->pathCoverage);

            if (!is_string($driver)) {
                $scope = $postRun->coverageScope($configuration, $workingDirectory, $manifest->overrides->coverageFilter);

                if ($scope !== []) {
                    $collector = new CoverageCollector($driver, $scope);
                }
            }
        }

        // Buffer user echoes away from the protocol stream; the
        // NdjsonWriter writes to the STDOUT resource, which bypasses
        // PHP output buffering.
        ob_start();

        // #[Depends] across the boundary: what an earlier unit produced
        // arrives here, and what a later unit needs goes back out the
        // same way the coverage artifact does.
        $dependencyValues = new DependencyValues($manifest->dependencyValues, $manifest->provideValues);

        try {
            (new TestRunner($emitter, $runnerOptions, coverage: $collector, dependencies: $dependencyValues))->run($groups);
        } finally {
            ob_end_clean();
        }

        if ($collector instanceof CoverageCollector && $manifest->coverageArtifact !== null) {
            file_put_contents($manifest->coverageArtifact, $collector->data()->toJson());
        }

        if ($manifest->dependencyArtifact !== null) {
            file_put_contents($manifest->dependencyArtifact, json_encode($dependencyValues->provided(), JSON_THROW_ON_ERROR));
        }

        return 0;
    }

    /**
     * All three channels frameworks read env from.
     *
     * @param non-empty-string $name
     */
    private function exportEnv(string $name, string $value): void
    {
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * The test ids this run selects, from --run-test-id and from the file
     * --test-id-filter-file names. Null when neither was given, which is
     * how the selection tells "no id filter" from "an id filter that
     * matched nothing".
     *
     *
     * @return list<non-empty-string>|non-empty-string|null the ids, null for no selection, or an error message
     */
    private function idSelection(CliOptions $options, WorkingDirectory $workingDirectory): array|string|null
    {
        $ids = $options->runTestIds;

        if ($options->testIdFilterFile !== null) {
            $lines = $this->linesOf($options->testIdFilterFile, $workingDirectory);

            if (is_string($lines)) {
                return $lines;
            }

            $ids = [...$ids, ...$lines];
        }

        return $ids === [] ? null : $ids;
    }

    /**
     * The test files this run selects, from --test-files-file. Paths are
     * resolved against the working directory and compared as the ids
     * carry them, so a listing file can be written either way.
     *
     *
     * @return list<non-empty-string>|non-empty-string|null the files, null for no selection, or an error message
     */
    private function fileSelection(CliOptions $options, WorkingDirectory $workingDirectory): array|string|null
    {
        if ($options->testFilesFile === null) {
            return null;
        }

        $lines = $this->linesOf($options->testFilesFile, $workingDirectory);

        if (is_string($lines)) {
            return $lines;
        }

        // Ids carry the path as discovery found it, which is relative to
        // the working directory. A listing file written with absolute
        // paths is the same selection, so both spellings are accepted and
        // the absolute one is folded back to the relative form.
        $prefix = rtrim($workingDirectory->path, '/') . '/';
        $files  = [];

        foreach ($lines as $line) {
            $relative = str_starts_with($line, $prefix) ? substr($line, strlen($prefix)) : $line;

            if ($relative !== '') {
                $files[] = $relative;
            }
        }

        return $files === [] ? null : $files;
    }

    /**
     * The non-empty, non-comment lines of a listing file.
     *
     * @param non-empty-string $path
     *
     * @return list<non-empty-string>|non-empty-string the lines, or an error message
     */
    private function linesOf(string $path, WorkingDirectory $workingDirectory): array|string
    {
        $absolute = $workingDirectory->absolute($path);

        if (!is_file($absolute)) {
            return sprintf('The listing file "%s" does not exist.', $path);
        }

        $lines = file($absolute, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return sprintf('The listing file "%s" could not be read.', $path);
        }

        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '#')) {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * The run's records with each test's declared size attached, which
     * only the discovered metadata knows. A test declaring none says
     * "unknown", the word the incumbent uses for the same case.
     *
     * @param list<array{id: string, status: string, time: float}> $records
     * @param list<TestGroup>                                      $groups
     *
     * @return list<array{id: string, status: string, time: float, size: string}>
     */
    private function sizedRecords(array $records, array $groups): array
    {
        $sizes = [];

        foreach ($groups as $group) {
            foreach ($group->tests as $test) {
                $sizes[$test->id->toString()] = match (true) {
                    $test->metadata->has(Small::class)  => 'small',
                    $test->metadata->has(Medium::class) => 'medium',
                    $test->metadata->has(Large::class)  => 'large',
                    default                             => 'unknown',
                };
            }
        }

        $sized = [];

        foreach ($records as $record) {
            $sized[] = $record + ['size' => $sizes[$record['id']] ?? 'unknown'];
        }

        return $sized;
    }

    /**
     * The listing family: what the suite contains, after selection,
     * instead of what it does. The XML form is the spec's machine shape
     * for the same list, emitted directly rather than through the
     * reporting layer — it describes the plan, not a run.
     *
     * @param non-empty-string $what
     * @param list<TestGroup>  $groups
     */
    private function printListing(string $what, array $groups, Configuration $configuration): void
    {
        if ($what === 'suites') {
            foreach ($configuration->testSuites as $suite) {
                print ' - ' . $suite->name . PHP_EOL;
            }

            return;
        }

        if ($what === 'groups') {
            $names = [];

            foreach ($groups as $group) {
                foreach ($group->tests as $test) {
                    foreach ($test->metadata->ofType(Group::class) as $membership) {
                        $names[$membership->name] = true;
                    }
                }
            }

            $names = array_keys($names);
            sort($names);

            foreach ($names as $name) {
                print ' - ' . $name . PHP_EOL;
            }

            return;
        }

        if ($what === 'test-files') {
            $files = [];

            foreach ($groups as $group) {
                foreach ($group->tests as $test) {
                    $files[$test->id->file] = true;
                }
            }

            $files = array_keys($files);
            sort($files);

            foreach ($files as $file) {
                print ' - ' . $file . PHP_EOL;
            }

            return;
        }

        if ($what === 'tests-xml') {
            print '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
            print '<tests>' . PHP_EOL;

            foreach ($groups as $group) {
                foreach ($group->tests as $test) {
                    printf(
                        '  <test id="%s" file="%s" name="%s"/>' . PHP_EOL,
                        htmlspecialchars($test->id->toString(), ENT_XML1 | ENT_QUOTES),
                        htmlspecialchars($test->id->file, ENT_XML1 | ENT_QUOTES),
                        htmlspecialchars($test->id->name, ENT_XML1 | ENT_QUOTES),
                    );
                }
            }

            print '</tests>' . PHP_EOL;

            return;
        }

        // tests and test-ids: the same list, named for a human or for a
        // --run-test-id round trip.
        foreach ($groups as $group) {
            foreach ($group->tests as $test) {
                print $what === 'test-ids'
                    ? $test->id->toString() . PHP_EOL
                    : ' - ' . $group->name . '::' . $test->id->name . PHP_EOL;
            }
        }
    }

    /**
     * Instantiates the classes named by --extension. They take no
     * constructor arguments: a plugin that needs configuring is registered
     * with it in `crucible.php`, where the values have types.
     *
     * @return list<Extension>|non-empty-string the extensions, or an error message
     */
    private function extensionsFromCli(CliOptions $options): array|string
    {
        $extensions = [];

        foreach ($options->extensionClasses as $class) {
            if (!class_exists($class)) {
                return sprintf('Extension class "%s" does not exist.', $class);
            }

            $extension = new $class();

            if (!$extension instanceof Extension) {
                return sprintf('Extension class "%s" does not implement %s.', $class, Extension::class);
            }

            $extensions[] = $extension;
        }

        return $extensions;
    }

    /**
     * The configuration values this command line overrides, gathered once
     * so the parent, the workers, and the runner all resolve them the
     * same way (D-019: the spec's spellings win over the file, and a
     * parallel run must behave like its sequential twin).
     *
     */
    private function overrides(CliOptions $options, WorkingDirectory $workingDirectory): Overrides
    {
        // Which baseline this run reads, and whether it reads one at all.
        // --generate-baseline is a *generate*: the run must see every
        // deprecation, so it reads nothing and writes fresh, where
        // --update-deprecations-baseline merges into the one it read.
        $baseline = $options->useBaseline ?? $options->generateBaseline;

        return new Overrides(
            bootstrap: $options->bootstrap,
            backupGlobals: $options->backupGlobals,
            backupStaticProperties: $options->backupStaticProperties,
            beStrictAboutChangesToGlobalState: $options->strictGlobalState,
            requireCoverageMetadata: $options->requireCoverageMetadata,
            reportUselessTests: $options->reportUselessTests ? null : false,
            diffContext: $options->diffContext,
            baselineFile: $baseline === null
                ? null
                : $workingDirectory->absolute($baseline),
            ignoreBaseline: $options->ignoreBaseline || $options->generateBaseline !== null
                ? true
                : null,
            resolveDependencies: $options->resolveDependencies,
            coverageTargeting: $options->disableCoverageTargeting ? false : null,
            coverageFilter: $options->coverageFilter,
            repeat: $options->repeat,
            disallowTestOutput: $options->disallowTestOutput ? true : null,
            enforceTimeLimit: $options->enforceTimeLimit ? true : null,
            defaultTimeLimit: $options->defaultTimeLimit,
        );
    }

    /**
     * @param list<non-empty-string> $includePaths the spec's --include-path
     */
    private function applyPhpSettings(Configuration $configuration, WorkingDirectory $workingDirectory, Overrides $overrides = new Overrides(), array $includePaths = []): void
    {
        // Before the bootstrap: a suite that relies on include_path expects
        // it set by the time its own loader runs.
        if ($includePaths !== []) {
            $absolute = array_map(
                $workingDirectory->absolute(...),
                $includePaths,
            );

            set_include_path(implode(PATH_SEPARATOR, [...$absolute, get_include_path()]));
        }

        // Run-wide display state, set before any test can render a failure.
        Differ::context($overrides->diffContext);

        $bootstrap = $overrides->bootstrap ?? $configuration->bootstrap;

        if ($bootstrap !== null) {
            if (!str_starts_with($bootstrap, '/')) {
                $bootstrap = $workingDirectory->path . '/' . $bootstrap;
            }

            if (is_file($bootstrap)) {
                require_once $bootstrap;
            }
        }

        foreach ($configuration->php->ini as $name => $value) {
            ini_set($name, $value);
        }

        foreach ($configuration->php->env as $name => $value) {
            // All three channels, like the spec's <env> handler:
            // frameworks resolve env from $_SERVER first, and a parent
            // process (artisan test) may have exported conflicting
            // values there.
            $this->exportEnv($name, $value);
        }

        foreach ($configuration->php->constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }
}
