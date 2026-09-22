<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use LucianoPereira\Crucible\CLI\Commands\CompatCheckCommand;
use LucianoPereira\Crucible\CLI\Commands\CompletionCommand;
use LucianoPereira\Crucible\CLI\Commands\ExtensionsCommand;
use LucianoPereira\Crucible\CLI\Commands\FlakesCommand;
use LucianoPereira\Crucible\CLI\Commands\InitCommand;
use LucianoPereira\Crucible\CLI\Commands\LintInlineCommand;
use LucianoPereira\Crucible\CLI\Commands\ManualCommand;
use LucianoPereira\Crucible\CLI\Commands\MigrateConfigCommand;
use LucianoPereira\Crucible\CLI\Commands\MutationCommand;
use LucianoPereira\Crucible\CLI\Commands\PhpstanInitCommand;
use LucianoPereira\Crucible\CLI\Commands\RunTestsCommand;
use LucianoPereira\Crucible\CLI\Commands\ValidateConfigCommand;
use LucianoPereira\Crucible\CLI\Commands\WarmCoverageCacheCommand;
use LucianoPereira\Crucible\CLI\Commands\WatchCommand;
use LucianoPereira\Crucible\Console\Components\Splash;
use LucianoPereira\Crucible\Console\Exceptions\CancelledException;
use LucianoPereira\Crucible\Console\Exceptions\NonInteractiveException;
use LucianoPereira\Crucible\Console\Output\Help;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Style\Color;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Reporting\MapView;
use LucianoPereira\Crucible\Version;

use function getcwd;
use function is_string;
use function max;
use function min;
use function printf;
use function sprintf;
use function strlen;
use function version_compare;

use const PHP_EOL;
use const STDOUT;

final class Application
{
    private const string RESET = "\e[0m";

    /** Milliseconds per banner frame — readable, and paid for by a fork rather than by the run. */
    private const int BANNER_FRAME_MS = 90;

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $options = CliOptions::fromArgv($argv);

        if (is_string($options)) {
            print $options . PHP_EOL;

            return 1;
        }

        // Worker mode prints nothing: stdout is the NDJSON protocol.
        if ($options->worker) {
            return (new RunTestsCommand())->worker();
        }

        $wantsBanner = $this->wantsBanner($options);
        $banner      = $wantsBanner ? $this->startBanner($options->version) : null;

        if ($options->version) {
            return 0;
        }

        // The spec's two version gates. --atleast-version is a CI guard:
        // it answers with an exit code, not a message. --check-version asks
        // whether a newer release exists, which Crucible cannot answer —
        // there is no update channel and it does not phone home — so it
        // says so rather than pretending the running version is current.
        if ($options->atLeastVersion !== null) {
            $banner?->settle();

            return version_compare(Version::NUMBER, $options->atLeastVersion, '>=') ? 0 : 1;
        }

        if ($options->checkVersion) {
            $banner?->settle();

            print 'Crucible has no update channel and does not check for new versions.' . PHP_EOL;

            return 0;
        }

        // The spec's php.ini audit: advisory about the environment, so
        // it answers and exits rather than running anything. Exit 1
        // when a setting is not as recommended, which is what makes it
        // usable as a CI gate on the machine rather than the code.
        if ($options->checkPhpConfiguration) {
            $banner?->settle();

            return $this->printPhpConfiguration();
        }

        $banner?->settle();

        // The separator belongs to the banner, so it goes when the banner
        // does — a suppressed banner must not leave its blank line behind.
        if ($wantsBanner) {
            print PHP_EOL;
        }

        if ($options->help) {
            $this->help();

            return 0;
        }

        $cwd = getcwd();

        if ($cwd === false) {
            print 'Cannot determine the current working directory.' . PHP_EOL;

            return 1;
        }

        $workingDirectory = new WorkingDirectory($cwd);

        // ⚠ The two ways a console component ends a command. Uncaught,
        // Ctrl-C at a prompt printed a stack trace over the half-drawn
        // frame it had just cancelled.
        try {
            return $this->dispatch($options, $argv, $workingDirectory);
        } catch (CancelledException) {
            print 'Cancelled.' . PHP_EOL;

            return 130;
        } catch (NonInteractiveException $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(CliOptions $options, array $argv, WorkingDirectory $workingDirectory): int
    {
        // Parsing the source scope for method structure is the cost the
        // Crap4J, XML, and per-method HTML reports pay. Warming it is
        // paying that cost deliberately, before the run that needs it.
        if ($options->warmCoverageCache) {
            return (new WarmCoverageCacheCommand())->execute($options, $workingDirectory);
        }

        if ($options->validateConfiguration) {
            return (new ValidateConfigCommand())->execute($options, $workingDirectory);
        }

        if ($options->init) {
            return (new InitCommand())->execute($workingDirectory);
        }

        if ($options->migrateConfig) {
            return (new MigrateConfigCommand())->execute($workingDirectory);
        }

        if ($options->phpstanInit) {
            return (new PhpstanInitCommand())->execute($options, $workingDirectory);
        }

        if ($options->lintInline) {
            return (new LintInlineCommand())->execute($options, $workingDirectory);
        }

        if ($options->mutationIndex) {
            return (new MutationCommand())->index($options, $workingDirectory);
        }

        if ($options->mutate) {
            return (new MutationCommand())->mutate($options, $argv, $workingDirectory);
        }

        if ($options->flakes) {
            return (new FlakesCommand())->execute($options, $argv, $workingDirectory);
        }

        if ($options->compatCheck) {
            return (new CompatCheckCommand())->execute($options, $argv, $workingDirectory);
        }

        if ($options->extensions) {
            return (new ExtensionsCommand())->execute($options, $argv, $workingDirectory);
        }

        if ($options->completion) {
            return (new CompletionCommand())->execute($options->argument);
        }

        if ($options->manual) {
            return (new ManualCommand())->execute($options->out);
        }

        if ($options->watch) {
            return (new WatchCommand())->execute($options, $argv, $workingDirectory);
        }

        return (new RunTestsCommand())->execute($options, $argv, $workingDirectory);
    }

    /**
     * @return int<0, 1>
     */
    private function printPhpConfiguration(): int
    {
        $results = PhpConfigurationCheck::results();
        $width   = 0;

        foreach ($results as $result) {
            $width = max($width, strlen($result['name'] . ' = ' . $result['expected']));
        }

        print 'Checking whether PHP is configured for development.' . PHP_EOL . PHP_EOL;

        $exit = 0;

        foreach ($results as $result) {
            if (!$result['ok']) {
                $exit = 1;
            }

            printf(
                '%-' . $width . "s ... %s" . PHP_EOL,
                $result['name'] . ' = ' . $result['expected'],
                $result['ok'] ? 'ok' : sprintf('not ok (%s)', $result['actual'] === '' ? '(empty)' : $result['actual']),
            );
        }

        return $exit;
    }

    /**
     * The banner, branded where a human is looking and plain where a log is.
     *
     * The colourless form is byte-identical to what this printed before —
     * it goes to pipes, CI and captured output, and a banner is not worth
     * changing those. Colour is decided by Capabilities, which already
     * honours NO_COLOR, FORCE_COLOR and the --colors override, so this adds
     * no new rule about when to use it.
     *
     * The mark is the project's own: a diamond and the wordmark swept by
     * {@see Splash}, the version in the accent, the author dimmed.
     *
     * ⚠ The author is `dim`, not a pinned grey. It was Brand::slate()
     * (#4A4A4A), which is the unlit state of an animation against a known
     * background — as a byline on a dark terminal it is unreadable. `dim`
     * is the terminal's own attenuation of its own foreground, so it stays
     * legible whatever the theme.
     *
     * ⚠ The sweep is forked, not slept through. `--version` is the one
     * case with nothing behind it to overlap, so there it runs
     * synchronously and IS the command; everywhere else it sweeps while
     * the configuration loads and settles when the caller is ready to
     * print. A banner that costs a real run half a second is a banner
     * someone turns off.
     */
    /**
     * Whether this run gets a banner line of its own.
     *
     * ⚠ Not when the map is coming. Its top border already carries the
     * mark, the version and the byline, so printing them again above it
     * is the same identity twice — and on the alternate screen the line
     * above scrolls away unread anyway.
     */
    private function wantsBanner(CliOptions $options): bool
    {
        if ($options->noOutput) {
            return false;
        }

        // ⚠ A completion script is evaluated by the shell, not read by a
        // person: `eval "$(crucible completion bash)"` would try to run
        // the banner as shell and fail on the first word.
        if ($options->completion) {
            return false;
        }

        // --version is the banner, whatever view a run would have used.
        if ($options->version) {
            return true;
        }

        return !MapView::isDefaultFor(
            $options->view,
            $options->testdox === true,
            $options->teamcity === true,
            Runtime::terminal(),
        );
    }

    private function startBanner(bool $versionOnly): ?Splash
    {
        $terminal = Runtime::terminal();

        if (!Capabilities::color($terminal)) {
            printf('crucible %s by %s.' . PHP_EOL, Version::NUMBER, Version::AUTHOR);

            return null;
        }

        $splash           = new Splash($terminal, '◆ crucible');
        $splash->interval = self::BANNER_FRAME_MS;
        $splash->suffix   = sprintf(
            ' %s%s%s %sby %s.%s',
            $this->paint(Theme::accent()),
            Version::NUMBER,
            self::RESET,
            Style::none()->dim()->toAnsi(),
            Version::AUTHOR,
            self::RESET,
        );

        if ($versionOnly) {
            $splash->sweeps = 1;
            $splash->render();

            return null;
        }

        $splash->begin();

        return $splash;
    }

    private function paint(Color $color): string
    {
        return Style::none()->withForeground($color)->toAnsi();
    }

    private function help(): void
    {
        $terminal = Runtime::terminal();

        print (new Help(min(100, max(60, $terminal->columns())), Capabilities::color($terminal)))->render(<<<'EOT'
            Usage:
              crucible [options]
              crucible migrate-config      Convert phpunit.xml(.dist) to a crucible.php and exit
              crucible flakes [--rounds N]  Hunt order-dependent tests via seeded random rounds
              crucible phpstan-init        Wire Crucible's PHPStan extension into phpstan.neon
              crucible lint-inline         Analyse @crucible doctests with PHPStan via shadow files
              crucible mutation-index      Emit the per-line covering-tests index (JSON) for a mutation tool
              crucible mutate              Mutation-test the covered source and report the score (needs --coverage first)
              crucible compat-check        Diagnose tests that pass on the real incumbent (pest, else phpunit) but fail on Crucible
                --auto-fix                   Apply known-safe rewrites without asking
                --revert                     Undo fixes applied by a previous --auto-fix run
              crucible extensions          List registered report-format, subscriber, and progress-view plugins and their availability
                --key <name>                 Show one plugin's full detail instead of the summary list
                --preview                    Render or run a live sample through --key <name> and write it
                --out <path>                 Preview output path (default: a temp file, path printed)
                --json                       Machine-readable output for the list/detail view
              crucible completion <shell>   Print the tab-completion script for bash, zsh or fish
              crucible manual               Read Crucible's manual fullscreen (↑/↓ scroll, / search, 1-9 follow links, ⌫ back, q quit)
                --out <path>                Write the manual to <path> as a PDF instead of reading it
                --manual                     The same, spelled as an option

            Options:
              --assignee <name>         Select only todos assigned to <name>
              --configuration <file>    Read configuration from <file> instead of crucible.php
              --covers <name,...>       Run only tests declaring one of these covered targets
              --exclude-filter <pattern>  Never run tests whose name matches <pattern>
              --exclude-group <name,...>  Never run tests in the given group(s)
              --exclude-testsuite <name,...>  Never run the named test suite(s)
              --filter <pattern>        Run only tests whose name matches <pattern>
              --group <name,...>        Run only tests in the given group(s)
              --ignore-dependencies     Leave the scheduled positions alone; an unmet Depends still skips
              --issue <id>              Select only todos referencing issue <id>
              --log-events-json <file>  Write the NDJSON event stream to <file>
              --log-events-text <file>  Write the event stream to <file> as one line per event
              --log-events-verbose <file>  As --log-events-text, keeping each event's payload fields
              --log-junit <file>        Write a JUnit XML report to <file> (CI interop)
              --log-markdown <file>     Write a Markdown report to <file>
              --log-otr <file>          Write an Open Test Reporting (opentest4j) event stream to <file>
              --log-pdf <file>          Write a PDF report to <file>
              --log-teamcity <file>     Write TeamCity service messages to <file>, leaving the progress output alone
              --order-by <order>        Run tests in order: default|defects|duration|random|reverse|size
              --parallel <N>            Run test classes in N worker processes (LPT-scheduled)
              --process-isolation       Run every test in its own process, as if each were marked RunInSeparateProcess
              --profile                 List the slowest tests, with each one's share of total runtime
              --random-order-seed <N>   Seed for --order-by random, to replay an ordering
              --report <key:path,...>   Render one or more registered report formats (see crucible extensions) to <path>
              --requires-ext <name,...>  Run only tests requiring one of these extensions
              --resolve-dependencies    Reorder so a Depends prerequisite runs before its dependent (the default)
              --run-test-id <id,...>    Run only the tests with these ids (see --list-test-ids)
              --shard <M/N>             Run the Mth of N hash-stable suite slices (CI sharding)
              --subscriber <key:path,...>  Subscribe one or more registered subscribers (see crucible extensions) writing to <path>
              --teamcity                Sugar for --view=teamcity: replace the progress output with TeamCity service messages
              --test-files-file <file>  Run only the test files listed in <file>, one per line
              --test-id-filter-file <file>  Run only the test ids listed in <file>, one per line
              --test-suffix <suffix,...>  Discover test files by these suffixes instead of each suite's
              --testdox                 Sugar for --view=testdox: replace the progress output with the documentation view
              --testdox-html <file>     Write the documentation view to <file> as a standalone HTML page
              --testdox-summary <file>  Write only the run tally to <file>, without the per-test listing
              --testdox-text <file>     Write the documentation view to <file> as plain text
              --testsuite <name,...>    Run only the named test suite(s)
              --todos                   Select only todo-marked tests — the open-work listing
              --uses <name,...>         Run only tests declaring one of these used targets
              --view <key>              Select a registered progress view (see crucible extensions) by key
                  map             the suite as a map, a cell per test, drawn inline
                  map-fullscreen  the same on the alternate screen (the default at a terminal)
                  console         the dot-per-test view (the default in CI and pipes)
              --no-wip                  Exclude work-in-progress (->wip()) tests from the run
              --with-telemetry          Prefix each event-text line with elapsed time and peak memory
              --changed[=<ref>]         Run only tests affected by changes vs <ref> (default HEAD)
              --coverage                Collect line coverage (pcov or xdebug) and print the summary
              --coverage-branch         Also collect branch coverage (needs xdebug — pcov cannot)
              --coverage-clover <file>  Write Clover XML coverage to <file> (CI interop)
              --coverage-cobertura <file>  Write Cobertura XML coverage to <file> (CI interop)
              --coverage-html <dir>     Write the self-contained HTML coverage report into <dir>
              --coverage-openclover <file>  Write OpenClover XML coverage to <file> (CI interop)
              --coverage-php <file>     Write the coverage data to <file> as a PHP file that returns it
              --dirty                   Run only tests affected by uncommitted work (staged, unstaged, untracked)
              --fail-on-flaky           Exit non-zero when a test passed only on retry
              --min <percent>           Fail the run below this line-coverage percentage (collects coverage on its own)
              --path-coverage           Also collect path coverage, whole routes through a function (needs xdebug)
              --related <file,...>      Run only tests affected by the given file(s)
              --repeat <N>              Run every selected test N times, pass or fail
              --reproducible            Normalise what describes the run, so two runs of the same code write identical report bytes
              --retries <N>             Re-run failing tests up to N times; a pass on retry is FLAKY
              --retry <N>               PHPUnit spelling: attempt each test up to N times in total
              --strict-coverage         Be strict about coverage metadata: executing code outside the declared Covers/Uses targets is risky
              --validate-configuration  Check that crucible.php loads and every path it names exists
              --warm-coverage-cache     Parse the coverage scope's source now, so a later report does not
              --watch                   Re-run affected tests on every file change (q quits)
              --coverage-text[=<file>]  Write the text coverage report to <file>, or the terminal when bare
              --coverage-crap4j <file>  Write the Crap4J XML report (complexity against coverage) to <file>
              --coverage-filter <dir>   Add <dir> to the coverage scope, alongside whatever ->source() declares
              --coverage-text-summary  Text coverage report: totals only, no per-file rows
              --coverage-text-uncovered  Text coverage report: keep the files with no covered line
              --coverage-xml <dir>      Write the XML coverage report into <dir>
              --coverage-xml-no-source  Omit the source element from the XML coverage report
              --disable-coverage-ignore  Accepted: Crucible has no coverage-ignore metadata to disable
              --disable-coverage-targeting  Every test contributes all it executed, not only its declared targets
              --fail-on-deprecation     Exit non-zero when a test triggers a deprecation
              --fail-on-incomplete      Exit non-zero when a test is incomplete
              --fail-on-notice          Exit non-zero when a test triggers a notice
              --fail-on-risky           Exit non-zero when a test is risky
              --fail-on-skipped         Exit non-zero when a test is skipped
              --fail-on-warning         Exit non-zero when a test triggers a warning
              --include-git-information  Record the checkout commit, branch, and cleanliness in the coverage data
              --stop-on-defect          Stop after the first error, failure, or risky test
              --stop-on-deprecation     Stop after the first test that triggers a deprecation
              --stop-on-error           Stop after the first error
              --stop-on-failure         Stop after the first failure
              --stop-on-incomplete      Stop after the first incomplete test
              --stop-on-notice          Stop after the first test that triggers a notice
              --stop-on-risky           Stop after the first risky test
              --stop-on-skipped         Stop after the first skipped test
              --stop-on-warning         Stop after the first test that triggers a warning
              --update-baseline  Add the run's deprecations to the baseline file
              --update-snapshots, -u    Record or replace snapshot values (never happens implicitly)
              --without-class-view      HTML coverage report: drop the per-method table
              --without-file-view       HTML coverage report: drop the annotated source pages
              --colors[=<when>]         Colorize output: auto (default), always, never
              --browser <engine>        Run browser tests on: chrome (default), firefox, safari
              --cache-directory <dir>   Directory for Crucible's caches
              --cache-result            Write the result cache (default)
              --do-not-cache-result     Do not read or write the result cache
              --debug                   Print each test's name as it starts, for locating a hang
              --init                    Create a crucible.php configuration file in the current directory
              --list-groups             List the groups they belong to
              --list-suites             List the configured test suites
              --list-test-files         List the files they live in
              --list-test-ids           List their ids, ready to feed back to --run-test-id
              --list-tests              List the selected tests instead of running them
              --list-tests-xml          List the selected tests as XML
              --version                 Print version information and exit
              -h, --help                Print this help and exit

            PHPUnit spellings, accepted so an existing command line runs unchanged:
              --all                     Accepted: Crucible runs every configured suite already
              --atleast-version <v>     Exit 0 only if the running version is at least <v>
              --bootstrap <file>        Load <file> before the suite; wins over the configuration's
              --branch-coverage         Same as --coverage-branch
              --check-php-configuration  Report whether PHP is configured for development, and exit 1 if not
              --check-version           Report that Crucible has no update channel, and exit 0
              --columns <n|max>         Progress characters per line (default 64)
              --compact                 Progress and tally only, nothing between them
              --no-configuration        Run without reading crucible.php at all
              --no-coverage             Collect no coverage, whatever else this command line asks for
              --default-time-limit <sec>  The budget for a test that declares no size
              --diff-context <n>        Unchanged lines to keep around each change in a diff
              --disallow-test-output    A test that prints anything is risky
              --display-all-issues      All six of the above at once
              --display-deprecations    List the deprecations the tally counted, with their scope
              --display-errors          List the errored tests and their reasons
              --display-incomplete      List the incomplete tests and their reasons
              --display-notices         List the notices the tally counted
              --display-phpunit-deprecations   Accepted; Crucible emits no framework-internal issues
              --display-phpunit-notices        Accepted; Crucible emits no framework-internal issues
              --display-skipped         List the skipped tests and their reasons
              --display-warnings        List the warnings the tally counted
              --enforce-time-limit      A test slower than its declared size allows is risky
              --extension <class>       Register an extension class for this run
              --no-extensions           Register no extensions, configured or from --extension
              --fail-on-all-issues             Turn on every fail-on policy above
              --no-fail-on-deprecation     Do not exit non-zero on a deprecation
              --fail-on-direct     Exit non-zero on a deprecation your code triggered in a dependency
              --no-fail-on-direct    Do not exit non-zero on a direct deprecation
              --fail-on-empty-test-suite       Exit non-zero when the run selected no tests
              --no-fail-on-empty-test-suite  Do not exit non-zero when the run selected no tests
              --no-fail-on-incomplete      Do not exit non-zero on an incomplete test
              --fail-on-indirect   Exit non-zero on a deprecation one dependency triggered in another
              --no-fail-on-indirect  Do not exit non-zero on an indirect deprecation
              --no-fail-on-notice          Do not exit non-zero on a notice
              --fail-on-phpunit-deprecation    Accepted; Crucible emits no framework-internal issues
              --no-fail-on-phpunit-deprecation  Accepted; Crucible emits no framework-internal issues
              --fail-on-phpunit-notice         Accepted; Crucible emits no framework-internal issues
              --no-fail-on-phpunit-notice       Accepted; Crucible emits no framework-internal issues
              --fail-on-phpunit-warning        Accepted; Crucible emits no framework-internal issues
              --no-fail-on-phpunit-warning      Accepted; Crucible emits no framework-internal issues
              --no-fail-on-risky           Do not exit non-zero on a risky test
              --fail-on-self       Exit non-zero on a deprecation triggered in your own code
              --no-fail-on-self      Do not exit non-zero on a self deprecation
              --no-fail-on-skipped         Do not exit non-zero on a skipped test
              --no-fail-on-warning         Do not exit non-zero on a warning
              --generate-baseline <file>  Run reading no baseline, then write what it saw to <file>
              --globals-backup          Back up and restore $GLOBALS around every test
              --ignore-baseline         Read no deprecations baseline at all
              --include-path <path>     Prepend <path> to PHP's include_path before the bootstrap
              --no-logging              Write no report or log file, whatever else this command line asks for
              --migrate-configuration   Same as the migrate-config command
              --no-output               Print nothing at all; the exit code and the log targets are unchanged
              --php-advisory  Print that advisory before the run instead
              --no-php-advisory  Never print it (the default)
              --no-progress             Print no per-test progress characters
              --random-order            Same as --order-by random
              --require-coverage  Mark a test risky when it declares no coverage target
              --no-results              Print no problem list and no passed tree
              --reverse-list            List the problems last-first
              --reverse-order           Same as --order-by reverse
              --static-backup           Back up and restore static properties around every test
              --stderr                  Write the progress view to stderr instead of stdout
              --strict-global-state     Mark a test risky when it changes global state
              --use-baseline <file>     Read the deprecations baseline from <file>
              --no-useless-test-reports  Do not mark a test that asserts nothing as risky

            Longer PHPUnit spellings, both accepted (Crucible's name first):
              --cache-result  --record-test-run-history
              --do-not-cache-result  --do-not-record-test-run-history
              --coverage-text-summary  --only-summary-for-coverage-text
              --coverage-text-uncovered  --show-uncovered-for-coverage-text
              --coverage-xml-no-source  --exclude-source-from-xml-coverage
              --no-fail-on-deprecation  --do-not-fail-on-deprecation
              --fail-on-direct  --fail-on-direct-deprecation
              --no-fail-on-direct  --do-not-fail-on-direct-deprecation
              --no-fail-on-empty-test-suite  --do-not-fail-on-empty-test-suite
              --no-fail-on-incomplete  --do-not-fail-on-incomplete
              --fail-on-indirect  --fail-on-indirect-deprecation
              --no-fail-on-indirect  --do-not-fail-on-indirect-deprecation
              --no-fail-on-notice  --do-not-fail-on-notice
              --no-fail-on-phpunit-deprecation  --do-not-fail-on-phpunit-deprecation
              --no-fail-on-phpunit-notice  --do-not-fail-on-phpunit-notice
              --no-fail-on-phpunit-warning  --do-not-fail-on-phpunit-warning
              --no-fail-on-risky  --do-not-fail-on-risky
              --fail-on-self  --fail-on-self-deprecation
              --no-fail-on-self  --do-not-fail-on-self-deprecation
              --no-fail-on-skipped  --do-not-fail-on-skipped
              --no-fail-on-warning  --do-not-fail-on-warning
              --init  --generate-configuration
              --log-events-verbose  --log-events-verbose-text
              --php-advisory  --warn-when-php-is-not-configured-for-development
              --no-php-advisory  --do-not-warn-when-php-is-not-configured-for-development
              --require-coverage  --require-coverage-contribution
              --requires-ext  --requires-php-extension
              --update-baseline  --update-deprecations-baseline
              --no-useless-test-reports  --do-not-report-useless-tests

            Crucible is an independent test framework targeting drop-in
            compatibility with PHPUnit 13 suites. HELP.md documents every
            option in full; RELEASE.md records what shipped.

            EOT);
    }
}
