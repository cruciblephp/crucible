<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\BrowserEngine;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Extension\CommandGate;
use LucianoPereira\Crucible\Extension\Extension;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Impact\ImpactRule;
use LucianoPereira\Crucible\Reporting\ConsoleReporter;
use LucianoPereira\Crucible\Reporting\MapView;
use LucianoPereira\Crucible\Reporting\ReportFormat\Formats\{JsonReportFormat, MarkdownReportFormat, PdfReportFormat, SarifReportFormat};
use LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\JUnitSubscriber;
use LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\OtrSubscriber;
use LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\TestDoxHtmlSubscriber;
use LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\TestDoxSummarySubscriber;
use LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\TestDoxTextSubscriber;
use LucianoPereira\Crucible\Reporting\TeamCityReporter;
use LucianoPereira\Crucible\Reporting\TestDoxReporter;
use LucianoPereira\Crucible\Runner\Backoff;
use LucianoPereira\Crucible\Runner\RetryPolicy;
use LucianoPereira\Crucible\Vitest\VitestSuite;

use function array_is_list;
use function array_values;
use function is_string;
use function sprintf;

/**
 * Fluent builder returned by Crucible::configure() inside a project's
 * crucible.php file. Every method maps 1:1 to a phpunit.xml element or
 * attribute; the mapping is documented per method so migration from
 * an existing phpunit.xml is mechanical.
 */
final class Builder
{
    /** @var list<TestSuite> */
    private array $testSuites = [];

    private Source $source;
    private ?string $bootstrap = null;
    /** @var ?non-empty-string */
    private ?string $reportTitle = null;
    /** @var non-empty-string */
    private string $cacheDirectory         = '.crucible.cache';
    private bool $cacheResult              = true;
    private bool $colors                   = false;
    private ExecutionOrder $executionOrder = ExecutionOrder::Declared;

    private bool $failOnDeprecation         = false;
    private bool $failOnIncomplete          = false;
    private bool $failOnNotice              = false;
    private bool $failOnRisky               = false;
    private bool $failOnSkipped             = false;
    private bool $failOnWarning             = false;
    private bool $failOnDirectDeprecation   = false;
    private bool $failOnIndirectDeprecation = false;
    private bool $failOnSelfDeprecation     = false;
    private bool $failOnEmptyTestSuite      = true;
    private bool $stopOnDefect              = false;
    private bool $stopOnError               = false;
    private bool $stopOnFailure             = false;
    private bool $stopOnRisky               = false;
    private bool $stopOnSkipped             = false;
    private bool $stopOnIncomplete          = false;
    private bool $stopOnDeprecation         = false;
    private bool $stopOnNotice              = false;
    private bool $stopOnWarning             = false;
    private bool $testdox                   = false;
    private ?bool $phpunitCompatibility     = null;
    /** @var list<Quirk> */
    private array $quirks                           = [];
    private bool $backupGlobals                     = false;
    private bool $backupStaticProperties            = false;
    private bool $beStrictAboutChangesToGlobalState = false;

    private bool $requireCoverageMetadata = false;

    private bool $beStrictAboutCoverageMetadata = false;
    private ?int $maxSelfDeprecations           = null;
    private ?int $maxDirectDeprecations         = null;
    private ?int $maxIndirectDeprecations       = null;

    private RetryPolicy $retries;

    private BrowserConfiguration $browser;

    /** @var list<non-empty-string> */
    private array $quarantine = [];

    /** @var array<non-empty-string, string> */
    private array $ini = [];

    /** @var array<non-empty-string, string> */
    private array $env = [];

    /** @var list<CommandGate> */
    private array $commandGates = [];

    /** @var list<Extension> */
    private array $extensions = [];

    /** @var list<VitestSuite> */
    private array $vitestSuites = [];

    /** @var list<ImpactRule> */
    private array $impactRules = [];

    /** @var array<string, array{class: class-string, params: array<string, mixed>}> */
    private array $reportFormats;

    /** @var array<string, array{class: class-string, params: array<string, mixed>}> */
    private array $subscribers;

    /** @var array<string, array{class: class-string, params: array<string, mixed>}> */
    private array $progressViews;

    /** @var ?non-empty-string */
    private ?string $retriggerHotFile = null;

    private int $retriggerPort = 0;

    /** @var array<non-empty-string, bool|float|int|string|null> */
    private array $constants = [];

    public function __construct()
    {
        $this->source  = new Source();
        $this->retries = new RetryPolicy();
        $this->browser = new BrowserConfiguration();

        // Crucible's own PDF/Markdown/JSON/SARIF reports, pre-seeded
        // through the exact same report-format registration path a
        // third party's own plugin uses — no privileged, hardcoded
        // dispatch. A project's crucible.php overrides any entry by
        // calling ->reportFormat() again with the same key.
        $this->reportFormats['pdf']      = ['class' => PdfReportFormat::class, 'params' => []];
        $this->reportFormats['markdown'] = ['class' => MarkdownReportFormat::class, 'params' => []];
        $this->reportFormats['json']     = ['class' => JsonReportFormat::class, 'params' => []];
        $this->reportFormats['sarif']    = ['class' => SarifReportFormat::class, 'params' => []];

        // Same non-privileged pre-seeding for the JUnit subscriber — a
        // project's crucible.php overrides it by calling ->subscriber()
        // again with the same key.
        $this->subscribers['junit'] = ['class' => JUnitSubscriber::class, 'params' => []];

        // The documentation view's three file targets, registered the
        // same way: --testdox-text/html/summary is sugar for these
        // keys, exactly as --log-junit is sugar for junit.
        $this->subscribers['testdox-text']    = ['class' => TestDoxTextSubscriber::class, 'params' => []];
        $this->subscribers['testdox-html']    = ['class' => TestDoxHtmlSubscriber::class, 'params' => []];
        $this->subscribers['testdox-summary'] = ['class' => TestDoxSummarySubscriber::class, 'params' => []];

        // Open Test Reporting, the opentest4j event schema (--log-otr).
        $this->subscribers['otr'] = ['class' => OtrSubscriber::class, 'params' => []];

        // Same non-privileged pre-seeding for the three built-in
        // progress views — a project's crucible.php overrides one by
        // calling ->progressView() again with the same key, which also
        // changes what the --testdox/--teamcity flags construct, since
        // those flags now just select these same keys (D-023/D-066).
        $this->progressViews['console']  = ['class' => ConsoleReporter::class, 'params' => []];
        $this->progressViews['testdox']  = ['class' => TestDoxReporter::class, 'params' => []];
        $this->progressViews['teamcity'] = ['class' => TeamCityReporter::class, 'params' => []];

        // The map, in its two modes. Two keys rather than a new command-line
        // flag: --view already selects a view, and a mode that is only a
        // constructor param is one a crucible.php can set as easily.
        $this->progressViews['map']            = ['class' => MapView::class, 'params' => []];
        $this->progressViews['map-fullscreen'] = ['class' => MapView::class, 'params' => ['fullscreen' => true]];
    }

    /**
     * phpunit.xml: <phpunit bootstrap="...">
     *
     * @param non-empty-string $file
     */
    public function bootstrap(string $file): self
    {
        $this->bootstrap = $file;

        return $this;
    }

    /**
     * phpunit.xml: <testsuite name="..."><directory>...</directory></testsuite>
     *
     * @param non-empty-string                          $name
     * @param non-empty-string|array<non-empty-string>  $directories accepts a single path or a list of paths
     * @param list<non-empty-string>                    $files
     * @param non-empty-string                          $suffix
     */
    public function testSuite(string $name, string|array $directories, array $files = [], string $suffix = 'Test.php'): self
    {
        foreach ($this->testSuites as $existing) {
            if ($existing->name === $name) {
                throw new ConfigurationException(
                    sprintf('Test suite "%s" is defined more than once.', $name),
                );
            }
        }

        $directories = is_string($directories) ? [$directories] : $directories;

        if (!array_is_list($directories)) {
            throw new ConfigurationException(
                sprintf('Directories of test suite "%s" must be a list of paths.', $name),
            );
        }

        $this->testSuites[] = new TestSuite($name, $directories, $files, $suffix);

        return $this;
    }

    /**
     * phpunit.xml: <source><include>/<exclude>
     *
     * @param list<non-empty-string> $include
     * @param list<non-empty-string> $exclude
     * @param list<non-empty-string> $includeFiles
     * @param list<non-empty-string> $excludeFiles
     */
    public function source(array $include = [], array $exclude = [], array $includeFiles = [], array $excludeFiles = []): self
    {
        $this->source = new Source($include, $includeFiles, $exclude, $excludeFiles);

        return $this;
    }

    /**
     * The title line of generated report documents (PDF today) — the
     * project name, a release tag, whatever the archive needs to say.
     * Unset, reporters fall back to "Test report".
     *
     * @param non-empty-string $title
     */
    public function reportTitle(string $title): self
    {
        $this->reportTitle = $title;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit cacheDirectory="...">
     *
     * @param non-empty-string $directory
     */
    public function cacheDirectory(string $directory): self
    {
        $this->cacheDirectory = $directory;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit cacheResult="...">. Default matches the
     * spec (true). Disabling also empties the history that defects and
     * duration ordering read from.
     */
    public function cacheResult(bool $enabled = true): self
    {
        $this->cacheResult = $enabled;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit colors="...">. Default matches the spec (false).
     */
    public function colors(bool $colors = true): self
    {
        $this->colors = $colors;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit executionOrder="...">
     */
    public function executionOrder(ExecutionOrder $order): self
    {
        $this->executionOrder = $order;

        return $this;
    }

    /**
     * Enables every failOn* flag at once. Builder sugar over six
     * parity flags — adds no capability phpunit.xml doesn't have.
     */
    public function strict(): self
    {
        $this->failOnDeprecation = true;
        $this->failOnIncomplete  = true;
        $this->failOnNotice      = true;
        $this->failOnRisky       = true;
        $this->failOnSkipped     = true;
        $this->failOnWarning     = true;

        return $this;
    }

    public function failOnDeprecation(bool $enabled = true): self
    {
        $this->failOnDeprecation = $enabled;

        return $this;
    }

    public function failOnIncomplete(bool $enabled = true): self
    {
        $this->failOnIncomplete = $enabled;

        return $this;
    }

    public function failOnNotice(bool $enabled = true): self
    {
        $this->failOnNotice = $enabled;

        return $this;
    }

    public function failOnRisky(bool $enabled = true): self
    {
        $this->failOnRisky = $enabled;

        return $this;
    }

    public function failOnSkipped(bool $enabled = true): self
    {
        $this->failOnSkipped = $enabled;

        return $this;
    }

    public function failOnWarning(bool $enabled = true): self
    {
        $this->failOnWarning = $enabled;

        return $this;
    }

    public function failOnDirectDeprecation(bool $enabled = true): self
    {
        $this->failOnDirectDeprecation = $enabled;

        return $this;
    }

    public function failOnIndirectDeprecation(bool $enabled = true): self
    {
        $this->failOnIndirectDeprecation = $enabled;

        return $this;
    }

    public function failOnSelfDeprecation(bool $enabled = true): self
    {
        $this->failOnSelfDeprecation = $enabled;

        return $this;
    }

    public function failOnEmptyTestSuite(bool $enabled = true): self
    {
        $this->failOnEmptyTestSuite = $enabled;

        return $this;
    }

    public function stopOnDefect(bool $enabled = true): self
    {
        $this->stopOnDefect = $enabled;

        return $this;
    }

    public function stopOnError(bool $enabled = true): self
    {
        $this->stopOnError = $enabled;

        return $this;
    }

    public function stopOnFailure(bool $enabled = true): self
    {
        $this->stopOnFailure = $enabled;

        return $this;
    }

    public function stopOnRisky(bool $enabled = true): self
    {
        $this->stopOnRisky = $enabled;

        return $this;
    }

    public function stopOnSkipped(bool $enabled = true): self
    {
        $this->stopOnSkipped = $enabled;

        return $this;
    }

    public function stopOnIncomplete(bool $enabled = true): self
    {
        $this->stopOnIncomplete = $enabled;

        return $this;
    }

    public function stopOnDeprecation(bool $enabled = true): self
    {
        $this->stopOnDeprecation = $enabled;

        return $this;
    }

    public function stopOnNotice(bool $enabled = true): self
    {
        $this->stopOnNotice = $enabled;

        return $this;
    }

    public function stopOnWarning(bool $enabled = true): self
    {
        $this->stopOnWarning = $enabled;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit testdox="...">
     */
    public function testdox(bool $enabled = true): self
    {
        $this->testdox = $enabled;

        return $this;
    }

    /**
     * phpunit.xml: <php><ini name="..." value="..."/></php>
     *
     * @param non-empty-string $name
     */
    public function ini(string $name, string $value): self
    {
        $this->ini[$name] = $value;

        return $this;
    }

    /**
     * phpunit.xml: <php><env name="..." value="..."/></php>
     *
     * @param non-empty-string $name
     */
    public function env(string $name, string $value): self
    {
        $this->env[$name] = $value;

        return $this;
    }

    /**
     * phpunit.xml: <php><const name="..." value="..."/></php>
     *
     * @param non-empty-string $name
     */
    public function constant(string $name, bool|float|int|string|null $value): self
    {
        $this->constants[$name] = $value;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit backupGlobals="...">
     */
    public function backupGlobals(bool $enabled = true): self
    {
        $this->backupGlobals = $enabled;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit backupStaticProperties="...">
     */
    public function backupStaticProperties(bool $enabled = true): self
    {
        $this->backupStaticProperties = $enabled;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit beStrictAboutChangesToGlobalState="...">
     */
    public function beStrictAboutChangesToGlobalState(bool $enabled = true): self
    {
        $this->beStrictAboutChangesToGlobalState = $enabled;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit requireCoverageMetadata="..."> — a test
     * without any Covers or Uses metadata settles risky (D-063).
     */
    public function requireCoverageMetadata(bool $enabled = true): self
    {
        $this->requireCoverageMetadata = $enabled;

        return $this;
    }

    /**
     * phpunit.xml: <phpunit beStrictAboutCoverageMetadata="..."> —
     * a test executing source code outside its covered/used targets
     * settles risky, naming the stray units (D-063). Needs a
     * coverage run to compare against.
     */
    public function beStrictAboutCoverageMetadata(bool $enabled = true): self
    {
        $this->beStrictAboutCoverageMetadata = $enabled;

        return $this;
    }

    /**
     * Crucible-native: per-scope deprecation budgets, the typed form of
     * the Symfony bridge's max[self|direct|indirect] grammar. The run
     * exits non-zero when a scope exceeds its budget; null means no
     * limit for that scope. Baselined deprecations never count.
     */
    public function deprecationThresholds(?int $self = null, ?int $direct = null, ?int $indirect = null): self
    {
        $this->maxSelfDeprecations     = $self;
        $this->maxDirectDeprecations   = $direct;
        $this->maxIndirectDeprecations = $indirect;

        return $this;
    }

    /**
     * Crucible-native (growth G4/D-043): re-run failing tests up to
     * $count extra times, optionally pausing between attempts —
     * fixed or exponential backoff from $delay seconds, capped by
     * $maxDelay, jittered for network-flavored suites. A pass on a
     * retry is classified FLAKY, never silently green. #[Retry] on a
     * test overrides this per test.
     *
     * @param int<0, max> $count
     */
    public function retries(int $count, Backoff $backoff = Backoff::None, float $delay = 0.0, ?float $maxDelay = null, bool $jitter = false): self
    {
        $this->retries = new RetryPolicy($count, $backoff, $delay, $maxDelay, $jitter);

        return $this;
    }

    /**
     * Crucible-native (growth G4): known-flaky tests whose failures do
     * not affect the exit code. Ids are `file::name` (a dataset
     * suffix `#name` pins one row). The tests still run and report;
     * quarantined tests that pass are named as release candidates.
     * #[Quarantined] on the test itself is the code-local equivalent.
     *
     * @param non-empty-string ...$testIds
     */
    public function quarantine(string ...$testIds): self
    {
        // Variadics accept named arguments, which would make the
        // spread reintroduce string keys.
        $this->quarantine = [...$this->quarantine, ...array_values($testIds)];

        return $this;
    }

    /**
     * PHPUnit-namespace compatibility aliases (Crucible-native option).
     * Default (null) is the coexistence policy: aliases load
     * automatically when phpunit/phpunit is not installed, and stay
     * off when it is. true forces migration mode (fails fast if
     * PHPUnit classes are already loaded); false disables aliasing.
     */
    public function phpunitCompatibility(?bool $enabled = true): self
    {
        $this->phpunitCompatibility = $enabled;

        return $this;
    }

    /**
     * Reproduce a named incumbent bug instead of correcting it
     * (Crucible-native option).
     *
     * Opted into one at a time and never as a mode: a single
     * "compatibility" switch becomes a bucket nobody can audit, and
     * flipping it to silence one failure silently accepts every other
     * bug inside it. See {@see Quirk} for what each name buys and what
     * it costs.
     */
    public function quirks(Quirk ...$quirks): self
    {
        $this->quirks = array_values($quirks);

        return $this;
    }

    /**
     * Crucible-native (browser tier): explicit opt-in for browser tests.
     * Off by default — a `visit()` in the suite never activates the
     * tier by itself, and the default state costs nothing (no npm, no
     * browser downloads, Node untouched). `enabled: false` is the
     * deterministic-skip state for lanes without browsers. Nothing is
     * ever installed automatically.
     *
     * @param int<1, max>           $timeoutMs
     * @param non-empty-string|null $playwrightRoot directory whose node_modules provides the
     *                                              playwright CLI; null = the working directory
     * @param non-empty-string|null $requestHandler class implementing Browser\Server\RequestHandler;
     *                                              relative visit() URLs serve through it in-process
     *                                              (D-065 — the Laravel bridge ships one)
     */
    public function browser(
        bool $enabled = true,
        BrowserEngine $engine = BrowserEngine::Chrome,
        int $timeoutMs = 5_000,
        ?string $playwrightRoot = null,
        bool $headed = false,
        ?string $requestHandler = null,
    ): self {
        $this->browser = new BrowserConfiguration($enabled, $engine, $timeoutMs, $playwrightRoot, $headed, $requestHandler);

        return $this;
    }

    /**
     * Crucible-native (extension surface, Tier 0): orchestrate an external
     * command as part of the run — a static analyser, a JS test runner,
     * a linter, any tool with a meaningful exit code. The command runs
     * after the PHP suite, its output streams through to the console
     * verbatim, and its **exit code is the verdict**: zero passes,
     * non-zero fails the run with a named reason. Crucible never parses the
     * command's output — exit code is the one contract that does not
     * break across tool versions. Tools wanting their results folded into
     * the test tree write a PHP Extension instead; this is the robust,
     * no-plugin floor.
     *
     *     ->command('larastan', ['vendor/bin/phpstan', 'analyse'])
     *     ->command('js',       ['npx', 'vitest', 'run'])
     *
     * @param non-empty-string       $label            names the check in the summary, the reason, and the report
     * @param list<non-empty-string> $argv             argv form — no shell, no quoting, no injection
     * @param ?WorkingDirectory      $workingDirectory null = the run's working directory
     * @param ?positive-int          $timeout          seconds before Crucible kills the command; null = no limit
     */
    public function command(string $label, array $argv, ?WorkingDirectory $workingDirectory = null, ?int $timeout = null): self
    {
        foreach ($this->commandGates as $existing) {
            if ($existing->label === $label) {
                throw new ConfigurationException(
                    sprintf('Command gate "%s" is defined more than once.', $label),
                );
            }
        }

        if ($argv === []) {
            throw new ConfigurationException(
                sprintf('Command gate "%s" needs a non-empty list of argv parts.', $label),
            );
        }

        $this->commandGates[] = new CommandGate($label, $argv, $workingDirectory, $timeout);

        return $this;
    }

    /**
     * Register a PHP-native extension (D-078) — an instance, constructed
     * with its own typed configuration. Crucible dispatches it by the roles
     * it implements: a {@see Check} inspects the project after the suite
     * and presents a run-scoped artifact Crucible tests. Unlike a command
     * gate it runs in-process, with no shelling out.
     *
     *     ->extension(new PhpcpdCheck(paths: ['src'], minTokens: 70))
     */
    public function extension(Extension $extension): self
    {
        $this->extensions[] = $extension;

        return $this;
    }

    /**
     * Orchestrate a JavaScript suite through Vitest (D-079). A normal
     * `crucible` run spawns `vitest run --reporter=json` in this directory
     * after the PHP suite and folds its results into the same run,
     * report, and exit code — one command for the whole stack.
     *
     *     ->vitest('resources/js')
     *
     * @param non-empty-string  $directory the JS project root (holds package.json and vitest)
     * @param ?non-empty-string $binary    the vitest executable; null = `<directory>/node_modules/.bin/vitest`
     */
    public function vitest(string $directory = '.', ?string $binary = null): self
    {
        $this->vitestSuites[] = new VitestSuite($directory, $binary);

        return $this;
    }

    /**
     * Declare that changing these files puts these test groups in doubt
     * (D-083). The dependency graph resolves *class references*, so a
     * file reached by convention or at runtime — an asset, a Blade
     * template, a translation, a fixture — is invisible to it whatever
     * its extension. Only the author knows what such a change endangers.
     *
     *     ->impactRule('resources/js', ['browser'])
     *     ->impactRule('resources/lang', ['i18n'])
     *     ->impactRule('resources/views/mail/*.blade.php', ['mail'])
     *
     * Rules are **additive**: a match adds groups under `--changed` and
     * in watch mode, and can never remove one, so a missing rule is
     * never worse than declaring none.
     *
     * @param non-empty-string       $pattern project-relative path; a directory covers everything
     *                                        beneath it, and `*`/`?` glob (matching across `/`)
     * @param list<non-empty-string> $groups
     */
    public function impactRule(string $pattern, array $groups): self
    {
        $this->impactRules[] = new ImpactRule($pattern, $groups);

        return $this;
    }

    /**
     * Register a report-format plugin by class-string + params — the
     * report-format extension surface, deliberately kept separate from
     * {@see extension()} above: a class-string + reflection-read
     * `#[ReportFormat]` attribute + validated params array, not an
     * already-constructed instance. Crucible's own PDF/Markdown formats
     * are registered this same way, pre-seeded by the constructor —
     * calling this again with the same key overrides that default.
     *
     *     ->reportFormat('pdf', PdfReportFormat::class, ['output' => 'report.pdf'])
     *
     * @param  non-empty-string  $key
     * @param  class-string  $class
     * @param  array<string, mixed>  $params
     */
    public function reportFormat(string $key, string $class, array $params = []): self
    {
        $this->reportFormats[$key] = ['class' => $class, 'params' => $params];

        return $this;
    }

    /**
     * Register a subscriber plugin by class-string + params — the
     * subscriber extension surface, the same class-string + reflection
     * pattern as {@see reportFormat()} above, kept separate from
     * {@see extension()} for the same reason. Unlike a report format's
     * params (read at render() call time), a subscriber's params reach
     * its constructor, since `Listener::handle()` has no room for them.
     * Crucible's own JUnit subscriber is registered this same way,
     * pre-seeded by the constructor — calling this again with the same
     * key overrides that default.
     *
     *     ->subscriber('junit', JUnitSubscriber::class, ['output' => 'report.xml'])
     *
     * @param  non-empty-string  $key
     * @param  class-string  $class
     * @param  array<string, mixed>  $params
     */
    public function subscriber(string $key, string $class, array $params = []): self
    {
        $this->subscribers[$key] = ['class' => $class, 'params' => $params];

        return $this;
    }

    /**
     * Register a progress-view plugin by class-string + params — the
     * live, STDOUT-facing printer during a run, the same class-string +
     * reflection pattern as {@see reportFormat()}/{@see subscriber()}
     * above. Unlike a subscriber (every selected one runs, additively),
     * exactly one progress view is selected per run (D-023/D-066).
     * Crucible's own console/testdox/teamcity views are registered this
     * same way, pre-seeded by the constructor — calling this again with
     * the same key overrides that default, including for `console`,
     * `testdox`, or `teamcity` themselves.
     *
     *     ->progressView('console', CustomDotsReporter::class)
     *
     * @param  non-empty-string  $key
     * @param  class-string  $class
     * @param  array<string, mixed>  $params
     */
    public function progressView(string $key, string $class, array $params = []): self
    {
        $this->progressViews[$key] = ['class' => $class, 'params' => $params];

        return $this;
    }

    /**
     * Let whoever already knows what changed push a change set into
     * `crucible --watch` (D-083), instead of Crucible reconstructing one from
     * artifacts it would have to understand. A bundler plugin, a build
     * step, a git hook — see `integration/` for a ready-made producer.
     *
     *     ->retrigger()
     *
     * **Off by default**: a test tool must not open a socket unasked.
     * When enabled, watch binds 127.0.0.1 on an ephemeral port and
     * writes the full URL (token included) to the hot file, whose very
     * presence tells a producer that Crucible is listening. It is removed
     * on exit, and a failure to bind is never fatal — the session just
     * runs without it.
     *
     * @param non-empty-string $hotFile where the address is published; relative to the working directory
     * @param int              $port    0 = ephemeral, which never collides between projects
     */
    public function retrigger(string $hotFile = '.crucible.hot', int $port = 0): self
    {
        $this->retriggerHotFile = $hotFile;
        $this->retriggerPort    = $port;

        return $this;
    }

    public function build(): Configuration
    {
        return new Configuration(
            testSuites: $this->testSuites,
            source: $this->source,
            php: new Php($this->ini, $this->env, $this->constants),
            bootstrap: $this->bootstrap,
            reportTitle: $this->reportTitle,
            cacheDirectory: $this->cacheDirectory,
            cacheResult: $this->cacheResult,
            colors: $this->colors,
            executionOrder: $this->executionOrder,
            failOnDeprecation: $this->failOnDeprecation,
            failOnIncomplete: $this->failOnIncomplete,
            failOnNotice: $this->failOnNotice,
            failOnRisky: $this->failOnRisky,
            failOnSkipped: $this->failOnSkipped,
            failOnWarning: $this->failOnWarning,
            failOnDirectDeprecation: $this->failOnDirectDeprecation,
            failOnIndirectDeprecation: $this->failOnIndirectDeprecation,
            failOnSelfDeprecation: $this->failOnSelfDeprecation,
            failOnEmptyTestSuite: $this->failOnEmptyTestSuite,
            stopOnDefect: $this->stopOnDefect,
            stopOnError: $this->stopOnError,
            stopOnFailure: $this->stopOnFailure,
            stopOnRisky: $this->stopOnRisky,
            stopOnSkipped: $this->stopOnSkipped,
            stopOnIncomplete: $this->stopOnIncomplete,
            stopOnDeprecation: $this->stopOnDeprecation,
            stopOnNotice: $this->stopOnNotice,
            stopOnWarning: $this->stopOnWarning,
            testdox: $this->testdox,
            phpunitCompatibility: $this->phpunitCompatibility,
            quirks: $this->quirks,
            backupGlobals: $this->backupGlobals,
            backupStaticProperties: $this->backupStaticProperties,
            beStrictAboutChangesToGlobalState: $this->beStrictAboutChangesToGlobalState,
            requireCoverageMetadata: $this->requireCoverageMetadata,
            beStrictAboutCoverageMetadata: $this->beStrictAboutCoverageMetadata,
            maxSelfDeprecations: $this->maxSelfDeprecations,
            maxDirectDeprecations: $this->maxDirectDeprecations,
            maxIndirectDeprecations: $this->maxIndirectDeprecations,
            retries: $this->retries,
            quarantine: $this->quarantine,
            browser: $this->browser,
            commandGates: $this->commandGates,
            extensions: $this->extensions,
            vitest: $this->vitestSuites,
            impactRules: $this->impactRules,
            reportFormats: $this->reportFormats,
            subscribers: $this->subscribers,
            progressViews: $this->progressViews,
            retriggerHotFile: $this->retriggerHotFile,
            retriggerPort: $this->retriggerPort,
        );
    }
}
