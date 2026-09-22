<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use function array_keys;
use function array_map;
use function array_slice;
use function ctype_digit;
use function explode;
use function in_array;
use function is_numeric;
use function max;
use function sprintf;
use function str_contains;
use function str_starts_with;

use const PATH_SEPARATOR;

/**
 * The parsed command line, one nullable field per option: null means
 * "not given", so the CLI-overrides-configuration resolution
 * (CLI value ?? config value) is a null-coalesce, never a sentinel
 * comparison. Parsing is registry-driven and closed — an unknown
 * option or stray argument is an error, not a silent no-op.
 */
final readonly class CliOptions
{
    private const array FLAGS = [
        '--version', '--help', '-h', '--init', '--worker',
        '--stop-on-defect', '--stop-on-error', '--stop-on-failure',
        '--stop-on-risky', '--stop-on-skipped', '--stop-on-incomplete',
        '--stop-on-deprecation', '--stop-on-notice', '--stop-on-warning',
        '--fail-on-deprecation', '--fail-on-incomplete', '--fail-on-notice',
        '--fail-on-risky', '--fail-on-skipped', '--fail-on-warning',
        '--fail-on-flaky',
        '--no-fail-on-deprecation', '--no-fail-on-incomplete', '--no-fail-on-notice',
        '--no-fail-on-risky', '--no-fail-on-skipped', '--no-fail-on-warning',
        '--fail-on-direct', '--fail-on-indirect', '--fail-on-self',
        '--no-fail-on-direct', '--no-fail-on-indirect', '--no-fail-on-self',
        '--fail-on-empty-test-suite', '--no-fail-on-empty-test-suite',
        '--fail-on-all-issues',
        '--fail-on-phpunit-deprecation', '--fail-on-phpunit-notice', '--fail-on-phpunit-warning',
        '--no-fail-on-phpunit-deprecation', '--no-fail-on-phpunit-notice', '--no-fail-on-phpunit-warning',
        '--display-phpunit-deprecations', '--display-phpunit-notices',
        '--display-all-issues', '--display-deprecations', '--display-notices', '--display-warnings',
        '--display-errors', '--display-skipped', '--display-incomplete',
        '--globals-backup', '--static-backup', '--strict-global-state',
        '--require-coverage', '--no-coverage',
        '--cache-result', '--do-not-cache-result',
        '--testdox', '--teamcity',
        '--update-baseline',
        '--watch', '--coverage', '--coverage-branch', '--path-coverage', '--strict-coverage',
        '--update-snapshots', '-u',
        '--todos', '--no-wip', '--debug', '--profile', '--dirty',
        '--list-tests', '--list-groups', '--list-suites', '--list-test-files',
        '--list-test-ids', '--list-tests-xml',
        '--auto-fix', '--revert',
        '--preview', '--json',
        '--no-logging', '--no-extensions', '--no-configuration',
        '--no-useless-test-reports', '--check-version',
        '--compact', '--no-progress', '--no-results', '--no-output', '--stderr', '--reverse-list',
        '--ignore-baseline',
        '--resolve-dependencies', '--ignore-dependencies', '--process-isolation',
        '--coverage-text-summary', '--coverage-text-uncovered',
        '--disable-coverage-ignore', '--disable-coverage-targeting',
        '--without-class-view', '--without-file-view', '--coverage-xml-no-source',
        '--include-git-information', '--enforce-time-limit', '--disallow-test-output', '--with-telemetry',
        '--warm-coverage-cache', '--validate-configuration', '--all',
        '--check-php-configuration', '--reproducible',
        '--php-advisory',
        '--no-php-advisory',
    ];

    private const array VALUED = [
        '--configuration', '--log-events-json', '--log-junit', '--log-markdown', '--log-pdf',
        '--order-by', '--random-order-seed', '--parallel', '--filter', '--cache-directory',
        '--shard', '--retries', '--retry', '--repeat', '--default-time-limit', '--rounds', '--coverage-clover',
        '--coverage-html', '--coverage-cobertura',
        '--testdox-text', '--testdox-html', '--testdox-summary',
        '--log-otr',
        '--coverage-php', '--coverage-openclover', '--coverage-crap4j', '--coverage-xml',
        '--assignee', '--issue', '--browser', '--bootstrap',
        '--exclude-filter', '--test-files-file', '--test-id-filter-file',
        '--include-path', '--atleast-version',
        '--columns', '--diff-context', '--generate-baseline', '--use-baseline',
        '--log-events-text', '--log-events-verbose', '--log-teamcity',
        '--key', '--out', '--view', '--min',
    ];

    private const array REPEATABLE = ['--testsuite', '--group', '--exclude-group', '--related', '--report', '--subscriber', '--extension', '--exclude-testsuite', '--test-suffix', '--covers', '--uses', '--requires-ext', '--run-test-id', '--coverage-filter'];

    /**
     * Spellings the spec uses for something Crucible already spells its
     * own way. Rewritten before the parse loop sees them, so there is one
     * option in the registry, one code path, and no second meaning to keep
     * in sync — an alias here can only ever be the option it points at.
     */
    private const array ALIASES = [
        '--random-order'          => '--order-by=random',
        '--reverse-order'         => '--order-by=reverse',
        '--branch-coverage'       => '--coverage-branch',
        '--migrate-configuration' => 'migrate-config',
        '--manual'                => 'manual',

        // The spec's name for the result cache. Crucible's cache *is* a
        // per-test run history — bounded outcomes plus the last
        // duration — and feeds the same two orderings the spec's does.
        '--generate-configuration'         => '--init',
        '--record-test-run-history'        => '--cache-result',
        '--do-not-record-test-run-history' => '--do-not-cache-result',

        // ⚠ The spec's spellings for options Crucible names more
        // briefly. They are aliases rather than the canonical name so
        // `--help` can stay readable, and they are kept rather than
        // dropped because a suite migrating to Crucible arrives with a
        // command line already written against them.
        '--do-not-fail-on-deprecation'                             => '--no-fail-on-deprecation',
        '--do-not-fail-on-incomplete'                              => '--no-fail-on-incomplete',
        '--do-not-fail-on-notice'                                  => '--no-fail-on-notice',
        '--do-not-fail-on-risky'                                   => '--no-fail-on-risky',
        '--do-not-fail-on-skipped'                                 => '--no-fail-on-skipped',
        '--do-not-fail-on-warning'                                 => '--no-fail-on-warning',
        '--do-not-fail-on-direct-deprecation'                      => '--no-fail-on-direct',
        '--do-not-fail-on-indirect-deprecation'                    => '--no-fail-on-indirect',
        '--do-not-fail-on-self-deprecation'                        => '--no-fail-on-self',
        '--fail-on-direct-deprecation'                             => '--fail-on-direct',
        '--fail-on-indirect-deprecation'                           => '--fail-on-indirect',
        '--fail-on-self-deprecation'                               => '--fail-on-self',
        '--do-not-fail-on-empty-test-suite'                        => '--no-fail-on-empty-test-suite',
        '--do-not-fail-on-phpunit-deprecation'                     => '--no-fail-on-phpunit-deprecation',
        '--do-not-fail-on-phpunit-notice'                          => '--no-fail-on-phpunit-notice',
        '--do-not-fail-on-phpunit-warning'                         => '--no-fail-on-phpunit-warning',
        '--do-not-report-useless-tests'                            => '--no-useless-test-reports',
        '--only-summary-for-coverage-text'                         => '--coverage-text-summary',
        '--show-uncovered-for-coverage-text'                       => '--coverage-text-uncovered',
        '--exclude-source-from-xml-coverage'                       => '--coverage-xml-no-source',
        '--warn-when-php-is-not-configured-for-development'        => '--php-advisory',
        '--do-not-warn-when-php-is-not-configured-for-development' => '--no-php-advisory',
        '--requires-php-extension'                                 => '--requires-ext',
        '--log-events-verbose-text'                                => '--log-events-verbose',
        '--require-coverage-contribution'                          => '--require-coverage',

        // Crucible's own, shortened before 1.0 fixes it.
        '--update-deprecations-baseline' => '--update-baseline',
    ];

    private const array COMMANDS = ['migrate-config', 'flakes', 'phpstan-init', 'lint-inline', 'mutation-index', 'mutate', 'compat-check', 'extensions', 'manual', 'completion'];

    /**
     * @param ?non-empty-string       $configuration
     * @param ?non-empty-string       $logEventsJson
     * @param ?non-empty-string       $logJunit
     * @param ?non-empty-string       $logMarkdown
     * @param ?non-empty-string       $logPdf
     * @param ?non-empty-string       $orderBy
     * @param ?non-empty-string       $filter
     * @param ?non-empty-string       $shard
     * @param ?non-empty-string       $cacheDirectory
     * @param ?('auto'|'always'|'never') $colors
     * @param ?list<non-empty-string> $testsuite
     * @param list<non-empty-string>  $groups
     * @param list<non-empty-string>  $excludeGroups
     * @param ?non-empty-string       $changed the git reference to diff against; bare --changed means HEAD
     * @param list<non-empty-string>  $extensionClasses class names registered from the CLI, instantiated with no arguments
     * @param ?non-empty-string       $excludeFilter    the spec's --exclude-filter, the negated form of --filter
     * @param ?non-empty-string       $testFilesFile    the spec's --test-files-file: a file listing the test files to run
     * @param ?non-empty-string       $testIdFilterFile the spec's --test-id-filter-file: a file listing the test ids to run
     * @param list<non-empty-string>  $excludeTestsuite the spec's --exclude-testsuite; exclusion wins over --testsuite
     * @param list<non-empty-string>  $testSuffixes     the spec's --test-suffix; empty keeps each suite's own
     * @param list<non-empty-string>  $covers           the spec's --covers: only tests declaring one of these targets
     * @param list<non-empty-string>  $uses             the spec's --uses: the same, over the Uses* attributes
     * @param list<non-empty-string>  $requiresPhpExtension the spec's --requires-php-extension
     * @param list<non-empty-string>  $runTestIds       the spec's --run-test-id, accumulated
     * @param ?non-empty-string       $list             what to list instead of running: tests|groups|suites|test-files|test-ids|tests-xml
     * @param list<non-empty-string>  $includePaths     the spec's --include-path, prepended to PHP's include_path
     * @param ?non-empty-string       $atLeastVersion   the spec's --atleast-version: exit 0 only if the running version is at least this
     * @param ?int<1, max>            $columns          the spec's --columns; "max" resolves to a fixed wide value
     * @param ?int<0, max>            $diffContext      the spec's --diff-context: unchanged lines kept around each change
     * @param ?int                    $repeat           the spec's --repeat: run every selected test this many times
     * @param list<non-empty-string>  $coverageFilter   the spec's --coverage-filter: directories coverage is restricted to
     * @param ?string                 $coverageText     the spec's --coverage-text; '' = the terminal, a path = that file
     * @param ?float                  $minCoverage      the spec's --min: the run fails below this line-coverage percentage
     * @param bool                    $reproducible     Crucible-native: normalise what describes the RUN, so two runs of the same code write identical bytes
     * @param ?non-empty-string       $generateBaseline the spec's --generate-baseline: write a fresh deprecations baseline here
     * @param ?non-empty-string       $useBaseline      the spec's --use-baseline: read the deprecations baseline from here
     * @param ?non-empty-string       $bootstrap the spec's --bootstrap; wins over the configuration's
     * @param list<non-empty-string>  $related
     * @param list<non-empty-string>  $report   each "key:path" — selects a registered report format for this run, key matching Configuration::$reportFormats
     * @param list<non-empty-string>  $subscriber each "key:path" — selects a registered subscriber for this run, key matching Configuration::$subscribers
     * @param ?int<0, max>            $retries  validated by the numeric-option check
     * @param ?int<0, max>            $rounds   validated by the numeric-option check
     * @param ?non-empty-string       $assignee select only todos assigned to this name
     * @param ?non-empty-string       $issue    select only todos referencing this issue
     * @param ?non-empty-string       $key      `crucible extensions`: the report-format key to describe/preview; omitted = list every registered format
     * @param ?non-empty-string       $out      `crucible extensions --preview`: where to write the rendered sample; default = a temp file, path printed
     * @param ?non-empty-string       $view     select a registered progress view by key, matching Configuration::$progressViews; wins over --testdox/--teamcity (D-023)
     */
    private function __construct(
        public bool $version = false,
        public bool $help = false,
        public bool $init = false,
        public bool $worker = false,
        public bool $migrateConfig = false,
        public bool $phpstanInit = false,
        public bool $lintInline = false,
        public bool $mutationIndex = false,
        public bool $mutate = false,
        public bool $compatCheck = false,
        public bool $autoFix = false,
        public bool $revert = false,
        public bool $extensions = false,
        public bool $manual = false,
        public bool $completion = false,
        /** The bare word after a command: `crucible completion bash`. */
        public ?string $argument = null,
        public bool $preview = false,
        public bool $json = false,
        public ?string $key = null,
        public ?string $out = null,
        public ?string $view = null,
        public ?string $configuration = null,
        public ?string $logEventsJson = null,
        public ?string $logJunit = null,
        public ?string $logMarkdown = null,
        public ?string $logPdf = null,
        public ?string $orderBy = null,
        public ?int $randomOrderSeed = null,
        public ?int $parallel = null,
        public ?string $filter = null,
        public ?string $shard = null,
        public ?string $cacheDirectory = null,
        public ?array $testsuite = null,
        public array $groups = [],
        public array $excludeGroups = [],
        public ?bool $stopOnDefect = null,
        public ?bool $stopOnError = null,
        public ?bool $stopOnFailure = null,
        public ?bool $stopOnRisky = null,
        public ?bool $stopOnSkipped = null,
        public ?bool $stopOnIncomplete = null,
        public ?bool $stopOnDeprecation = null,
        public ?bool $stopOnNotice = null,
        public ?bool $stopOnWarning = null,
        public ?bool $failOnDeprecation = null,
        public ?bool $failOnIncomplete = null,
        public ?bool $failOnNotice = null,
        public ?bool $failOnRisky = null,
        public ?bool $failOnSkipped = null,
        public ?bool $failOnWarning = null,
        public ?bool $failOnDirectDeprecation = null,
        public ?bool $failOnIndirectDeprecation = null,
        public ?bool $failOnSelfDeprecation = null,
        public ?bool $failOnEmptyTestSuite = null,
        public ?bool $backupGlobals = null,
        public ?bool $backupStaticProperties = null,
        public ?bool $strictGlobalState = null,
        public ?bool $requireCoverageMetadata = null,
        public ?string $bootstrap = null,
        public array $extensionClasses = [],
        public ?string $excludeFilter = null,
        public ?string $testFilesFile = null,
        public ?string $testIdFilterFile = null,
        public array $excludeTestsuite = [],
        public array $testSuffixes = [],
        public array $covers = [],
        public array $uses = [],
        public array $requiresPhpExtension = [],
        public array $runTestIds = [],
        public ?string $list = null,
        public bool $noExtensions = false,
        public bool $noConfiguration = false,
        public bool $reportUselessTests = true,
        public bool $checkVersion = false,
        public ?string $atLeastVersion = null,
        public array $includePaths = [],
        public bool $compact = false,
        public bool $noProgress = false,
        public bool $noResults = false,
        public bool $noOutput = false,
        public bool $stderr = false,
        public bool $reverseList = false,
        public ?int $columns = null,
        public ?int $diffContext = null,
        public bool $displayDeprecations = false,
        public bool $displayNotices = false,
        public bool $displayWarnings = false,
        public bool $displayErrors = false,
        public bool $displaySkipped = false,
        public bool $displayIncomplete = false,
        public ?string $generateBaseline = null,
        public ?string $useBaseline = null,
        public bool $ignoreBaseline = false,
        public ?bool $resolveDependencies = null,
        public bool $processIsolation = false,
        public ?string $logEventsText = null,
        public ?string $logEventsVerboseText = null,
        public ?string $logTeamcity = null,
        public ?bool $cacheResult = null,
        public ?string $logOtr = null,
        public ?string $testdoxText = null,
        public ?string $testdoxHtml = null,
        public ?string $testdoxSummary = null,
        public ?bool $testdox = null,
        public ?bool $teamcity = null,
        public ?string $colors = null,
        public bool $updateDeprecationsBaseline = false,
        public ?string $changed = null,
        public array $related = [],
        public array $report = [],
        public array $subscriber = [],
        public bool $watch = false,
        public ?int $retries = null,
        public ?int $repeat = null,
        public bool $checkPhpConfiguration = false,
        public ?bool $warnWhenPhpIsNotConfiguredForDevelopment = null,
        public bool $warmCoverageCache = false,
        public bool $validateConfiguration = false,
        public bool $all = false,
        public bool $withTelemetry = false,
        public bool $disallowTestOutput = false,
        public bool $enforceTimeLimit = false,
        public ?int $defaultTimeLimit = null,
        public ?bool $failOnFlaky = null,
        public bool $flakes = false,
        public ?int $rounds = null,
        public bool $coverage = false,
        public ?string $coverageClover = null,
        public bool $coverageBranch = false,
        public bool $pathCoverage = false,
        public bool $strictCoverage = false,
        public ?string $coverageHtml = null,
        public ?string $coverageCobertura = null,
        public ?string $coveragePhp = null,
        public ?string $coverageOpenClover = null,
        public ?string $coverageCrap4j = null,
        public ?string $coverageXml = null,
        public ?string $coverageText = null,
        public ?float $minCoverage = null,
        public bool $reproducible = false,
        public bool $onlySummaryForCoverageText = false,
        public bool $showUncoveredForCoverageText = false,
        public bool $withoutClassView = false,
        public bool $withoutFileView = false,
        public bool $excludeSourceFromXmlCoverage = false,
        public bool $disableCoverageIgnore = false,
        public bool $disableCoverageTargeting = false,
        public bool $includeGitInformation = false,
        public array $coverageFilter = [],
        public bool $updateSnapshots = false,
        public bool $todos = false,
        public ?string $assignee = null,
        public ?string $issue = null,
        public bool $noWip = false,
        public ?string $browser = null,
        public bool $debug = false,
        public bool $profile = false,
        public bool $dirty = false,
    ) {}


    /**
     * Whether anything on this command line asks for a coverage driver.
     * One question with one answer, so a new report target cannot be
     * wired to a writer and then silently never collected for.
     */
    public function wantsCoverage(): bool
    {
        return $this->coverage
            || $this->coverageBranch
            || $this->pathCoverage
            || $this->coverageClover !== null
            || $this->coverageCobertura !== null
            || $this->coverageHtml !== null
            || $this->coverageOpenClover !== null
            || $this->coveragePhp !== null
            || $this->coverageCrap4j !== null
            || $this->coverageXml !== null
            || $this->coverageText !== null
            // A floor is a request to measure: you cannot ask for one and
            // not want the measurement it is a floor on.
            || $this->minCoverage !== null;
    }

    /**
     * A switch with both spellings present: --fail-on-x turns the policy
     * on, --no-fail-on-x turns it off, neither leaves it null so the
     * configuration decides.
     *
     * @param array<string, true> $flags
     */
    private static function tristate(array $flags, string $on, string $off): ?bool
    {
        return match (true) {
            isset($flags[$on])  => true,
            isset($flags[$off]) => false,
            default             => null,
        };
    }

    /**
     * Every name the parser accepts: options, their aliases, commands.
     *
     * ⚠ Read from the same constants the parse loop reads, so a
     * generated completion script cannot drift from what the binary
     * takes. `--worker` is the supervisor's private handshake with its
     * own children and is not a thing a user types.
     *
     * @return list<string>
     */
    public static function accepted(): array
    {
        $names = [];

        foreach ([self::FLAGS, self::VALUED, self::REPEATABLE, self::COMMANDS] as $set) {
            foreach ($set as $name) {
                $names[$name] = true;
            }
        }

        foreach (array_keys(self::ALIASES) as $name) {
            $names[$name] = true;
        }

        // Parsed by hand because their value is optional.
        foreach (['--changed', '--colors', '--coverage-text'] as $byHand) {
            $names[$byHand] = true;
        }

        unset($names['--worker']);

        return array_keys($names);
    }

    /**
     * @param list<string> $argv
     *
     * @return self|non-empty-string the options, or an error message
     */
    public static function fromArgv(array $argv): self|string
    {
        /** @var array<string, true> $flags */
        $flags = [];

        /** @var array<string, non-empty-string> $values */
        $values = [];

        /** @var array<string, list<non-empty-string>> $lists */
        $lists = [];

        /** @var ?('auto'|'always'|'never') $colors */
        $colors = null;

        /** The command seen, and the bare word following it. */
        $command         = null;
        $commandArgument = null;

        /** @var ?non-empty-string $changed */
        $changed = null;

        /** @var ?string $coverageText '' = the terminal, non-empty = that file */
        $coverageText = null;

        // ⚠ The alias is resolved on the NAME, not on the whole
        // argument. Every alias used to be a flag, so `--alias=value`
        // never came up; the spec's spellings for valued options can be
        // written that way, and matching the whole token would have let
        // them through unresolved to be rejected as unknown.
        $arguments = array_map(
            static function (string $argument): string {
                if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
                    [$name, $value] = explode('=', $argument, 2);

                    return (self::ALIASES[$name] ?? $name) . '=' . $value;
                }

                return self::ALIASES[$argument] ?? $argument;
            },
            array_slice($argv, 1),
        );

        for ($i = 0; isset($arguments[$i]); $i++) {
            $argument = $arguments[$i];
            $name     = $argument;
            $inline   = null;

            if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
                [$name, $inline] = explode('=', $argument, 2);
            }

            if (in_array($name, self::FLAGS, true)) {
                if ($inline !== null) {
                    return sprintf('Option %s does not take a value.', $name);
                }

                $flags[$name] = true;

                continue;
            }

            if (in_array($name, self::VALUED, true) || in_array($name, self::REPEATABLE, true)) {
                $value = $inline;

                if ($value === null && isset($arguments[$i + 1])) {
                    $value = $arguments[$i + 1];
                    $i++;
                }

                if ($value === null || $value === '') {
                    return sprintf('Option %s requires a value.', $name);
                }

                if (in_array($name, self::REPEATABLE, true)) {
                    foreach (explode(',', $value) as $item) {
                        if ($item !== '') {
                            $lists[$name][] = $item;
                        }
                    }

                    continue;
                }

                // Last occurrence wins, matching the spec's CLI.
                $values[$name] = $value;

                continue;
            }

            if (in_array($name, self::COMMANDS, true)) {
                $flags[$name] = true;
                $command      = $name;

                continue;
            }

            // ⚠ Only after a command, and only the first one. A bare word
            // with no command before it is still an error, so a mistyped
            // option cannot be silently swallowed as an argument.
            if ($command !== null && $commandArgument === null && !str_starts_with($name, '-')) {
                $commandArgument = $name;

                continue;
            }

            // --changed takes an optional value: bare means HEAD (the
            // working tree's uncommitted changes), and like --colors
            // it never consumes the next argument (only the = form).
            if ($name === '--changed') {
                $reference = $inline ?? 'HEAD';

                if ($reference === '') {
                    return 'Option --changed expects a git reference.';
                }

                $changed = $reference;

                continue;
            }

            // --coverage-text takes an optional value, the spec spelling
            // it --coverage-text=<file> for exactly that reason: bare
            // means the terminal, so it never consumes the next argument.
            if ($name === '--coverage-text') {
                $coverageText = $inline ?? '';

                continue;
            }

            // --colors takes an optional value: bare means auto, and
            // it never consumes the next argument (only the = form).
            if ($name === '--colors') {
                $when = $inline ?? 'auto';

                if (!in_array($when, ['auto', 'always', 'never'], true)) {
                    return sprintf('Option --colors expects auto, always or never, got "%s".', $when);
                }

                $colors = $when;

                continue;
            }

            return str_starts_with($argument, '-')
                ? sprintf('Unknown option "%s". Run crucible --help for the list of options.', $argument)
                : sprintf('Unrecognized argument "%s".', $argument);
        }

        foreach (['--random-order-seed', '--parallel', '--retries', '--retry', '--rounds', '--diff-context'] as $numeric) {
            if (isset($values[$numeric]) && !ctype_digit($values[$numeric])) {
                return sprintf('Option %s expects a non-negative integer, got "%s".', $numeric, $values[$numeric]);
            }
        }

        // A percentage, not an integer: --min=99.5 is a real ask, and the
        // number the gate compares is the one the report prints.
        if (isset($values['--min'])) {
            if (!is_numeric($values['--min'])) {
                return sprintf('Option --min expects a percentage between 0 and 100, got "%s".', $values['--min']);
            }

            $minimum = (float) $values['--min'];

            if ($minimum < 0.0 || $minimum > 100.0) {
                return sprintf('Option --min expects a percentage between 0 and 100, got "%s".', $values['--min']);
            }

            // Refused rather than resolved: --no-coverage says measure
            // nothing, --min says fail below a measurement. Letting one
            // win silently would drop a CI gate and report success.
            if (isset($flags['--no-coverage'])) {
                return 'Options --min and --no-coverage are mutually exclusive.';
            }
        }

        if (isset($flags['--cache-result'], $flags['--do-not-cache-result'])) {
            return 'Options --cache-result and --do-not-cache-result are mutually exclusive.';
        }

        $switches = [
            'deprecation', 'incomplete', 'notice', 'risky', 'skipped', 'warning',
            'direct', 'indirect', 'self',
            'empty-test-suite',
            'phpunit-deprecation', 'phpunit-notice', 'phpunit-warning',
        ];

        foreach ($switches as $issue) {
            if (isset($flags['--fail-on-' . $issue], $flags['--no-fail-on-' . $issue])) {
                return sprintf('Options --fail-on-%1$s and --no-fail-on-%1$s are mutually exclusive.', $issue);
            }
        }

        // --fail-on-all-issues turns on every policy the run has, which is
        // what the spec means by "all". A negation still wins: the point of
        // pairing it with --no-fail-on-skipped is "everything but that".
        if (isset($flags['--fail-on-all-issues'])) {
            foreach ($switches as $issue) {
                if (!isset($flags['--no-fail-on-' . $issue])) {
                    $flags['--fail-on-' . $issue] = true;
                }
            }
        }

        if (isset($flags['--php-advisory'], $flags['--no-php-advisory'])) {
            return 'Options --php-advisory and --no-php-advisory are mutually exclusive.';
        }

        if (isset($flags['--resolve-dependencies'], $flags['--ignore-dependencies'])) {
            return 'Options --resolve-dependencies and --ignore-dependencies are mutually exclusive.';
        }

        if (isset($values['--generate-baseline'], $values['--use-baseline'])) {
            return 'Options --generate-baseline and --use-baseline are mutually exclusive.';
        }

        if (isset($values['--retries'], $values['--retry'])) {
            return 'Options --retries and --retry are mutually exclusive.';
        }

        // Repeating and retrying answer opposite questions — "run it
        // again regardless" against "run it again only if it failed" —
        // so the spec refuses the pair rather than ordering them.
        if (isset($values['--repeat']) && (isset($values['--retries']) || isset($values['--retry']))) {
            return 'Options --repeat and --retries/--retry are mutually exclusive.';
        }

        // The oracle's --retry counts *total* attempts ("attempt each test up
        // to N times"); Crucible's --retries counts the extra ones, following
        // nextest (D-043), which is also what ->retries() and #[Retry] mean.
        // Both spellings stay exact by converting here rather than by moving
        // either vocabulary onto the other's.
        $retries = match (true) {
            isset($values['--retries']) => max(0, (int) $values['--retries']),
            isset($values['--retry'])   => max(0, (int) $values['--retry'] - 1),
            default                     => null,
        };

        // --display-all-issues is the spec's "every --display-* at once",
        // resolved here so the command sees one already-answered question
        // per kind rather than an "or the all switch" at every use.
        $displayAll = isset($flags['--display-all-issues']);

        // --columns takes a number or the word "max", which the spec uses
        // for "as wide as the terminal". Without a terminal to ask, max is
        // the widest line worth writing to a pipe.
        $columns = null;

        if (isset($values['--columns'])) {
            if ($values['--columns'] === 'max') {
                $columns = 120;
            } elseif (ctype_digit($values['--columns']) && (int) $values['--columns'] > 0) {
                $columns = (int) $values['--columns'];
            } else {
                return sprintf('Option --columns expects a positive integer or "max", got "%s".', $values['--columns']);
            }
        }

        // A single --include-path may carry several entries, separated the
        // way PHP itself separates them.
        $includePaths = [];

        foreach (isset($values['--include-path']) ? explode(PATH_SEPARATOR, $values['--include-path']) : [] as $path) {
            if ($path !== '') {
                $includePaths[] = $path;
            }
        }

        // The listing family is one question — what to print instead of
        // running — so it resolves to one value rather than six booleans
        // the command would have to re-derive an order for.
        $list = null;

        foreach (['tests', 'groups', 'suites', 'test-files', 'test-ids', 'tests-xml'] as $what) {
            if (isset($flags['--list-' . $what])) {
                if ($list !== null) {
                    return sprintf('Options --list-%s and --list-%s are mutually exclusive.', $list, $what);
                }

                $list = $what;
            }
        }

        // The spec's --no-coverage suppresses coverage for the whole run, so
        // a CI line can append it to a command that already asks for reports.
        // --no-logging and --no-extensions are the same shape over the log
        // targets and the plugins: a blanket off switch appended to a command
        // that already asks for them.
        $noCoverage   = isset($flags['--no-coverage']);
        $noLogging    = isset($flags['--no-logging']);
        $noExtensions = isset($flags['--no-extensions']);

        $cacheResult = null;

        if (isset($flags['--cache-result'])) {
            $cacheResult = true;
        } elseif (isset($flags['--do-not-cache-result'])) {
            $cacheResult = false;
        }

        return new self(
            version: isset($flags['--version']),
            help: isset($flags['--help']) || isset($flags['-h']),
            init: isset($flags['--init']),
            worker: isset($flags['--worker']),
            migrateConfig: isset($flags['migrate-config']),
            phpstanInit: isset($flags['phpstan-init']),
            lintInline: isset($flags['lint-inline']),
            mutationIndex: isset($flags['mutation-index']),
            mutate: isset($flags['mutate']),
            compatCheck: isset($flags['compat-check']),
            autoFix: isset($flags['--auto-fix']),
            revert: isset($flags['--revert']),
            extensions: isset($flags['extensions']),
            manual: isset($flags['manual']),
            completion: isset($flags['completion']),
            argument: $commandArgument,
            preview: isset($flags['--preview']),
            json: isset($flags['--json']),
            key: $values['--key'] ?? null,
            out: $values['--out'] ?? null,
            view: $values['--view'] ?? null,
            configuration: $values['--configuration'] ?? null,
            logEventsJson: $noLogging ? null : ($values['--log-events-json'] ?? null),
            logJunit: $noLogging ? null : ($values['--log-junit'] ?? null),
            logMarkdown: $noLogging ? null : ($values['--log-markdown'] ?? null),
            logPdf: $noLogging ? null : ($values['--log-pdf'] ?? null),
            orderBy: $values['--order-by'] ?? null,
            randomOrderSeed: isset($values['--random-order-seed']) ? (int) $values['--random-order-seed'] : null,
            parallel: isset($values['--parallel']) ? (int) $values['--parallel'] : null,
            filter: $values['--filter'] ?? null,
            shard: $values['--shard'] ?? null,
            cacheDirectory: $values['--cache-directory'] ?? null,
            testsuite: $lists['--testsuite'] ?? null,
            groups: $lists['--group'] ?? [],
            excludeGroups: $lists['--exclude-group'] ?? [],
            stopOnDefect: isset($flags['--stop-on-defect']) ? true : null,
            stopOnError: isset($flags['--stop-on-error']) ? true : null,
            stopOnFailure: isset($flags['--stop-on-failure']) ? true : null,
            stopOnRisky: isset($flags['--stop-on-risky']) ? true : null,
            stopOnSkipped: isset($flags['--stop-on-skipped']) ? true : null,
            stopOnIncomplete: isset($flags['--stop-on-incomplete']) ? true : null,
            stopOnDeprecation: isset($flags['--stop-on-deprecation']) ? true : null,
            stopOnNotice: isset($flags['--stop-on-notice']) ? true : null,
            stopOnWarning: isset($flags['--stop-on-warning']) ? true : null,
            failOnDeprecation: self::tristate($flags, '--fail-on-deprecation', '--no-fail-on-deprecation'),
            failOnIncomplete: self::tristate($flags, '--fail-on-incomplete', '--no-fail-on-incomplete'),
            failOnNotice: self::tristate($flags, '--fail-on-notice', '--no-fail-on-notice'),
            failOnRisky: self::tristate($flags, '--fail-on-risky', '--no-fail-on-risky'),
            failOnSkipped: self::tristate($flags, '--fail-on-skipped', '--no-fail-on-skipped'),
            failOnWarning: self::tristate($flags, '--fail-on-warning', '--no-fail-on-warning'),
            failOnDirectDeprecation: self::tristate($flags, '--fail-on-direct', '--no-fail-on-direct'),
            failOnIndirectDeprecation: self::tristate($flags, '--fail-on-indirect', '--no-fail-on-indirect'),
            failOnSelfDeprecation: self::tristate($flags, '--fail-on-self', '--no-fail-on-self'),
            failOnEmptyTestSuite: self::tristate($flags, '--fail-on-empty-test-suite', '--no-fail-on-empty-test-suite'),
            backupGlobals: isset($flags['--globals-backup']) ? true : null,
            backupStaticProperties: isset($flags['--static-backup']) ? true : null,
            strictGlobalState: isset($flags['--strict-global-state']) ? true : null,
            requireCoverageMetadata: isset($flags['--require-coverage']) ? true : null,
            bootstrap: $values['--bootstrap'] ?? null,
            extensionClasses: $noExtensions ? [] : ($lists['--extension'] ?? []),
            excludeFilter: $values['--exclude-filter'] ?? null,
            testFilesFile: $values['--test-files-file'] ?? null,
            testIdFilterFile: $values['--test-id-filter-file'] ?? null,
            excludeTestsuite: $lists['--exclude-testsuite'] ?? [],
            testSuffixes: $lists['--test-suffix'] ?? [],
            covers: $lists['--covers'] ?? [],
            uses: $lists['--uses'] ?? [],
            requiresPhpExtension: $lists['--requires-ext'] ?? [],
            runTestIds: $lists['--run-test-id'] ?? [],
            list: $list,
            noExtensions: $noExtensions,
            noConfiguration: isset($flags['--no-configuration']),
            reportUselessTests: !isset($flags['--no-useless-test-reports']),
            checkVersion: isset($flags['--check-version']),
            atLeastVersion: $values['--atleast-version'] ?? null,
            includePaths: $includePaths,
            compact: isset($flags['--compact']),
            noProgress: isset($flags['--no-progress']) || isset($flags['--no-output']),
            noResults: isset($flags['--no-results']) || isset($flags['--no-output']),
            noOutput: isset($flags['--no-output']),
            stderr: isset($flags['--stderr']),
            reverseList: isset($flags['--reverse-list']),
            columns: $columns,
            diffContext: isset($values['--diff-context']) ? max(0, (int) $values['--diff-context']) : null,
            displayDeprecations: $displayAll || isset($flags['--display-deprecations']),
            displayNotices: $displayAll || isset($flags['--display-notices']),
            displayWarnings: $displayAll || isset($flags['--display-warnings']),
            displayErrors: $displayAll || isset($flags['--display-errors']),
            displaySkipped: $displayAll || isset($flags['--display-skipped']),
            displayIncomplete: $displayAll || isset($flags['--display-incomplete']),
            generateBaseline: $values['--generate-baseline'] ?? null,
            useBaseline: $values['--use-baseline'] ?? null,
            ignoreBaseline: isset($flags['--ignore-baseline']),
            resolveDependencies: self::tristate($flags, '--resolve-dependencies', '--ignore-dependencies'),
            processIsolation: isset($flags['--process-isolation']),
            logEventsText: $noLogging ? null : ($values['--log-events-text'] ?? null),
            logEventsVerboseText: $noLogging ? null : ($values['--log-events-verbose'] ?? null),
            logTeamcity: $noLogging ? null : ($values['--log-teamcity'] ?? null),
            cacheResult: $cacheResult,
            logOtr: $noLogging ? null : ($values['--log-otr'] ?? null),
            testdoxText: $noLogging ? null : ($values['--testdox-text'] ?? null),
            testdoxHtml: $noLogging ? null : ($values['--testdox-html'] ?? null),
            testdoxSummary: $noLogging ? null : ($values['--testdox-summary'] ?? null),
            testdox: isset($flags['--testdox']) ? true : null,
            teamcity: isset($flags['--teamcity']) ? true : null,
            colors: $colors,
            updateDeprecationsBaseline: isset($flags['--update-baseline']),
            changed: $changed,
            related: $lists['--related'] ?? [],
            report: $noLogging ? [] : ($lists['--report'] ?? []),
            subscriber: $noLogging ? [] : ($lists['--subscriber'] ?? []),
            watch: isset($flags['--watch']),
            retries: $retries,
            repeat: isset($values['--repeat']) ? max(1, (int) $values['--repeat']) : null,
            checkPhpConfiguration: isset($flags['--check-php-configuration']),
            warnWhenPhpIsNotConfiguredForDevelopment: self::tristate(
                $flags,
                '--php-advisory',
                '--no-php-advisory',
            ),
            warmCoverageCache: isset($flags['--warm-coverage-cache']),
            validateConfiguration: isset($flags['--validate-configuration']),
            all: isset($flags['--all']),
            withTelemetry: isset($flags['--with-telemetry']),
            disallowTestOutput: isset($flags['--disallow-test-output']),
            enforceTimeLimit: isset($flags['--enforce-time-limit']),
            defaultTimeLimit: isset($values['--default-time-limit']) ? max(0, (int) $values['--default-time-limit']) : null,
            failOnFlaky: isset($flags['--fail-on-flaky']) ? true : null,
            flakes: isset($flags['flakes']),
            rounds: isset($values['--rounds']) ? max(0, (int) $values['--rounds']) : null,
            coverage: !$noCoverage && isset($flags['--coverage']),
            coverageClover: $noCoverage ? null : ($values['--coverage-clover'] ?? null),
            coverageBranch: !$noCoverage && (isset($flags['--coverage-branch']) || isset($flags['--path-coverage'])),
            pathCoverage: !$noCoverage && isset($flags['--path-coverage']),
            strictCoverage: !$noCoverage && isset($flags['--strict-coverage']),
            coverageHtml: $noCoverage ? null : ($values['--coverage-html'] ?? null),
            coverageCobertura: $noCoverage ? null : ($values['--coverage-cobertura'] ?? null),
            coveragePhp: $noCoverage ? null : ($values['--coverage-php'] ?? null),
            coverageOpenClover: $noCoverage ? null : ($values['--coverage-openclover'] ?? null),
            coverageCrap4j: $noCoverage ? null : ($values['--coverage-crap4j'] ?? null),
            coverageXml: $noCoverage ? null : ($values['--coverage-xml'] ?? null),
            coverageText: $noCoverage ? null : $coverageText,
            minCoverage: isset($values['--min']) ? (float) $values['--min'] : null,
            reproducible: isset($flags['--reproducible']),
            onlySummaryForCoverageText: isset($flags['--coverage-text-summary']),
            showUncoveredForCoverageText: isset($flags['--coverage-text-uncovered']),
            withoutClassView: isset($flags['--without-class-view']),
            withoutFileView: isset($flags['--without-file-view']),
            excludeSourceFromXmlCoverage: isset($flags['--coverage-xml-no-source']),
            disableCoverageIgnore: isset($flags['--disable-coverage-ignore']),
            disableCoverageTargeting: isset($flags['--disable-coverage-targeting']),
            includeGitInformation: isset($flags['--include-git-information']),
            coverageFilter: $noCoverage ? [] : ($lists['--coverage-filter'] ?? []),
            updateSnapshots: isset($flags['--update-snapshots']) || isset($flags['-u']),
            todos: isset($flags['--todos']),
            assignee: $values['--assignee'] ?? null,
            issue: $values['--issue'] ?? null,
            noWip: isset($flags['--no-wip']),
            browser: $values['--browser'] ?? null,
            debug: isset($flags['--debug']),
            profile: isset($flags['--profile']),
            dirty: isset($flags['--dirty']),
        );
    }
}
