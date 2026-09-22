<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Framework\TestCase;

use function implode;
use function is_string;
use function sprintf;

#[CoversClass(CliOptions::class)]
final class CliOptionsTest extends TestCase
{
    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): CliOptions
    {
        $options = CliOptions::fromArgv(['crucible', ...$argv]);

        if (is_string($options)) {
            self::fail('Unexpected parse error: ' . $options);
        }

        return $options;
    }

    public function testAbsentOptionsAreNullSoConfigurationWins(): void
    {
        $options = $this->parse([]);

        $this->assertNull($options->filter);
        $this->assertNull($options->stopOnFailure);
        $this->assertNull($options->cacheResult);
        $this->assertNull($options->testsuite);
        $this->assertSame([], $options->groups);
    }

    public function testValuedOptionsAcceptSpaceAndEqualsForms(): void
    {
        $spaced = $this->parse(['--filter', 'testFoo', '--parallel', '4']);
        $equals = $this->parse(['--filter=testFoo', '--parallel=4']);

        $this->assertSame('testFoo', $spaced->filter);
        $this->assertSame('testFoo', $equals->filter);
        $this->assertSame(4, $spaced->parallel);
        $this->assertSame(4, $equals->parallel);
    }

    public function testViewIsAValuedOptionSelectingAProgressViewKey(): void
    {
        $this->assertNull($this->parse([])->view);
        $this->assertSame('teamcity', $this->parse(['--view=teamcity'])->view);
        $this->assertSame('console', $this->parse(['--view', 'console'])->view);
    }

    public function testRepeatableOptionsAccumulateAndSplitOnCommas(): void
    {
        $options = $this->parse(['--group', 'db,slow', '--group', 'net', '--exclude-group=flaky']);

        $this->assertSame(['db', 'slow', 'net'], $options->groups);
        $this->assertSame(['flaky'], $options->excludeGroups);
    }

    public function testBooleanFlagsParseToTrueNotNull(): void
    {
        $options = $this->parse(['--stop-on-failure', '--fail-on-risky', '--do-not-cache-result']);

        $this->assertTrue($options->stopOnFailure);
        $this->assertTrue($options->failOnRisky);
        $this->assertFalse($options->cacheResult);
        $this->assertNull($options->stopOnError);
    }

    public function testUnknownOptionsAndStrayArgumentsAreErrors(): void
    {
        $this->assertIsString(CliOptions::fromArgv(['crucible', '--frobnicate']));
        $this->assertIsString(CliOptions::fromArgv(['crucible', 'tests/SomeTest.php']));
        $this->assertIsString(CliOptions::fromArgv(['crucible', '--filter']));
        $this->assertIsString(CliOptions::fromArgv(['crucible', '--stop-on-failure=yes']));
        $this->assertIsString(CliOptions::fromArgv(['crucible', '--parallel', 'lots']));
        $this->assertIsString(CliOptions::fromArgv(['crucible', '--cache-result', '--do-not-cache-result']));
    }

    public function testLastOccurrenceOfAValuedOptionWins(): void
    {
        $this->assertSame('second', $this->parse(['--filter', 'first', '--filter', 'second'])->filter);
    }

    public function testChangedTakesAnOptionalReference(): void
    {
        $this->assertNull($this->parse([])->changed);
        $this->assertSame('HEAD', $this->parse(['--changed'])->changed);
        $this->assertSame('main', $this->parse(['--changed=main'])->changed);
    }

    public function testBareChangedNeverConsumesTheNextArgument(): void
    {
        $options = $this->parse(['--changed', '--testdox']);

        $this->assertSame('HEAD', $options->changed);
        $this->assertTrue($options->testdox);
    }

    public function testAnEmptyChangedReferenceIsAnError(): void
    {
        $this->assertIsString(CliOptions::fromArgv(['crucible', '--changed=']));
    }

    public function testRelatedAccumulates(): void
    {
        $options = $this->parse(['--related', 'src/A.php', '--related', 'src/B.php,src/C.php']);

        $this->assertSame(['src/A.php', 'src/B.php', 'src/C.php'], $options->related);
    }

    public function testWatchIsAFlag(): void
    {
        $this->assertFalse($this->parse([])->watch);
        $this->assertTrue($this->parse(['--watch'])->watch);
    }

    public function testRetriesIsANumericOption(): void
    {
        $this->assertNull($this->parse([])->retries);
        $this->assertSame(3, $this->parse(['--retries', '3'])->retries);
        $this->assertIsString(CliOptions::fromArgv(['crucible', '--retries', 'lots']));
    }

    public function testFailOnFlakyIsAFlag(): void
    {
        $this->assertNull($this->parse([])->failOnFlaky);
        $this->assertTrue($this->parse(['--fail-on-flaky'])->failOnFlaky);
    }

    public function testFlakesIsACommandWithRounds(): void
    {
        $this->assertFalse($this->parse([])->flakes);

        $options = $this->parse(['flakes', '--rounds', '8']);

        $this->assertTrue($options->flakes);
        $this->assertSame(8, $options->rounds);
    }

    public function testCompatCheckIsACommandWithAutoFixAndRevert(): void
    {
        $none = $this->parse([]);

        $this->assertFalse($none->compatCheck);
        $this->assertFalse($none->autoFix);
        $this->assertFalse($none->revert);

        $options = $this->parse(['compat-check', '--auto-fix']);

        $this->assertTrue($options->compatCheck);
        $this->assertTrue($options->autoFix);
        $this->assertFalse($options->revert);

        $revert = $this->parse(['compat-check', '--revert']);

        $this->assertTrue($revert->revert);
        $this->assertFalse($revert->autoFix);
    }

    public function testCoverageIsOptIn(): void
    {
        $none = $this->parse([]);

        $this->assertFalse($none->coverage);
        $this->assertNull($none->coverageClover);

        $options = $this->parse(['--coverage', '--coverage-clover', 'build/clover.xml']);

        $this->assertTrue($options->coverage);
        $this->assertSame('build/clover.xml', $options->coverageClover);
    }

    public function testTodoListingFlagsParse(): void
    {
        $none = $this->parse([]);

        $this->assertFalse($none->todos);
        $this->assertNull($none->assignee);
        $this->assertNull($none->issue);

        $spaced = $this->parse(['--todos', '--assignee', 'luciano', '--issue', '31']);
        $equals = $this->parse(['--todos', '--assignee=luciano', '--issue=31']);

        foreach ([$spaced, $equals] as $options) {
            $this->assertTrue($options->todos);
            $this->assertSame('luciano', $options->assignee);
            $this->assertSame('31', $options->issue);
        }

        $this->assertSame(
            'Option --todos does not take a value.',
            CliOptions::fromArgv(['crucible', '--todos=open']),
        );
    }

    public function testProfileAndDirtyParseAsFlags(): void
    {
        $none = $this->parse([]);

        $this->assertFalse($none->profile);
        $this->assertFalse($none->dirty);

        $both = $this->parse(['--profile', '--dirty']);

        $this->assertTrue($both->profile);
        $this->assertTrue($both->dirty);
    }

    public function testNeitherFlagTakesAValue(): void
    {
        // Both are switches; `--profile=10` would silently look like a
        // limit, so it is refused rather than ignored.
        $this->assertSame(
            'Option --profile does not take a value.',
            CliOptions::fromArgv(['crucible', '--profile=10']),
        );

        $this->assertSame(
            'Option --dirty does not take a value.',
            CliOptions::fromArgv(['crucible', '--dirty=HEAD']),
        );
    }

    public function testTheSpecsRetrySpellingCountsTotalAttempts(): void
    {
        // --retry is "attempt each test up to N times"; --retries is the
        // extra attempts after the first, which is what ->retries() and
        // #[Retry] mean. One of the two has to convert, and it is here.
        $this->assertSame(2, $this->parse(['--retry', '3'])->retries);
        $this->assertSame(0, $this->parse(['--retry', '1'])->retries);
        $this->assertSame(0, $this->parse(['--retry', '0'])->retries);
        $this->assertSame(3, $this->parse(['--retries', '3'])->retries);
    }

    public function testTheTwoRetrySpellingsAreMutuallyExclusive(): void
    {
        $this->assertSame(
            'Options --retries and --retry are mutually exclusive.',
            CliOptions::fromArgv(['crucible', '--retry', '3', '--retries', '2']),
        );
    }

    public function testAliasesAreRewrittenToTheOptionTheyPointAt(): void
    {
        $this->assertSame('random', $this->parse(['--random-order'])->orderBy);
        $this->assertSame('reverse', $this->parse(['--reverse-order'])->orderBy);
        $this->assertTrue($this->parse(['--branch-coverage'])->coverageBranch);
        $this->assertTrue($this->parse(['--migrate-configuration'])->migrateConfig);
    }

    public function testAnAliasStillAcceptsWhatTheOptionItPointsAtAccepts(): void
    {
        // The rewrite happens before the parse loop, so a later --order-by
        // wins over an earlier --random-order exactly as two --order-by
        // would: last occurrence wins, no special case.
        $this->assertSame('duration', $this->parse(['--random-order', '--order-by', 'duration'])->orderBy);
        $this->assertSame('random', $this->parse(['--order-by', 'duration', '--random-order'])->orderBy);
    }

    public function testDoNotFailOnIsTheOffHalfOfTheFailOnTriState(): void
    {
        // false, not null: it has to beat a crucible.php that turns the
        // policy on, which a null could not.
        $this->assertNull($this->parse([])->failOnRisky);
        $this->assertTrue($this->parse(['--fail-on-risky'])->failOnRisky);
        $this->assertFalse($this->parse(['--no-fail-on-risky'])->failOnRisky);

        $this->assertFalse($this->parse(['--no-fail-on-deprecation'])->failOnDeprecation);
        $this->assertFalse($this->parse(['--no-fail-on-incomplete'])->failOnIncomplete);
        $this->assertFalse($this->parse(['--no-fail-on-notice'])->failOnNotice);
        $this->assertFalse($this->parse(['--no-fail-on-skipped'])->failOnSkipped);
        $this->assertFalse($this->parse(['--no-fail-on-warning'])->failOnWarning);

        // The spec's longer spelling reaches the same switch. A suite
        // migrating from PHPUnit arrives with a command line already
        // written against these, and it has to keep working.
        $this->assertFalse($this->parse(['--do-not-fail-on-risky'])->failOnRisky);
        $this->assertFalse($this->parse(['--do-not-fail-on-deprecation'])->failOnDeprecation);
        $this->assertFalse($this->parse(['--do-not-fail-on-incomplete'])->failOnIncomplete);
        $this->assertFalse($this->parse(['--do-not-fail-on-notice'])->failOnNotice);
        $this->assertFalse($this->parse(['--do-not-fail-on-skipped'])->failOnSkipped);
        $this->assertFalse($this->parse(['--do-not-fail-on-warning'])->failOnWarning);
    }

    /**
     * A spec spelling written with `=` resolves like any other.
     *
     * ⚠ Aliases used to be flags only, so the rewrite matched the whole
     * argument. A valued option written the spec's way — and `=` is how
     * most CI files write them — would have reached the parser
     * unresolved and been rejected as unknown.
     */
    public function testASpecSpellingResolvesWhenWrittenWithAnEquals(): void
    {
        $withSpace  = $this->parse(['--requires-php-extension', 'pcntl']);
        $withEquals = $this->parse(['--requires-php-extension=pcntl']);

        $this->assertSame(['pcntl'], $withSpace->requiresPhpExtension);
        $this->assertSame(['pcntl'], $withEquals->requiresPhpExtension, 'the same, written with =');

        // And the short spelling it resolves to answers identically.
        $this->assertSame(['pcntl'], $this->parse(['--requires-ext=pcntl'])->requiresPhpExtension);
    }

    public function testAFailOnSwitchAndItsNegationTogetherIsAnError(): void
    {
        $this->assertSame(
            'Options --fail-on-risky and --no-fail-on-risky are mutually exclusive.',
            CliOptions::fromArgv(['crucible', '--fail-on-risky', '--no-fail-on-risky']),
        );

        // ⚠ Written the spec's way, the clash is still caught. Aliases
        // are resolved before the check, so a command line mixing the
        // two spellings cannot slip past it — and the message names the
        // option Crucible documents rather than the one it accepted.
        $this->assertSame(
            'Options --fail-on-risky and --no-fail-on-risky are mutually exclusive.',
            CliOptions::fromArgv(['crucible', '--fail-on-risky', '--do-not-fail-on-risky']),
        );
    }

    public function testTheSpecsConfigurationSwitchesParseAsTriStates(): void
    {
        $options = $this->parse([
            '--globals-backup',
            '--static-backup',
            '--strict-global-state',
            '--require-coverage-contribution',
        ]);

        $this->assertTrue($options->backupGlobals);
        $this->assertTrue($options->backupStaticProperties);
        $this->assertTrue($options->strictGlobalState);
        $this->assertTrue($options->requireCoverageMetadata);

        $absent = $this->parse([]);

        $this->assertNull($absent->backupGlobals);
        $this->assertNull($absent->backupStaticProperties);
        $this->assertNull($absent->strictGlobalState);
        $this->assertNull($absent->requireCoverageMetadata);
    }

    public function testBootstrapIsValuedAndExtensionRepeats(): void
    {
        $this->assertSame('vendor/autoload.php', $this->parse(['--bootstrap', 'vendor/autoload.php'])->bootstrap);
        $this->assertNull($this->parse([])->bootstrap);

        $this->assertSame(
            ['A\\First', 'A\\Second'],
            $this->parse(['--extension', 'A\\First', '--extension', 'A\\Second'])->extensionClasses,
        );
    }

    public function testNoCoverageWinsOverEveryCoverageSpellingOnTheSameCommandLine(): void
    {
        // A CI line appends it to a command that already asks for reports,
        // which is the whole reason the spec has it.
        $options = $this->parse([
            '--coverage',
            '--coverage-branch',
            '--coverage-clover', 'clover.xml',
            '--coverage-html', 'coverage',
            '--coverage-cobertura', 'cobertura.xml',
            '--strict-coverage',
            '--no-coverage',
        ]);

        $this->assertFalse($options->coverage);
        $this->assertFalse($options->coverageBranch);
        $this->assertFalse($options->strictCoverage);
        $this->assertNull($options->coverageClover);
        $this->assertNull($options->coverageHtml);
        $this->assertNull($options->coverageCobertura);
    }

    public function testEveryStopOnSwitchParsesAsATriState(): void
    {
        $absent = $this->parse([]);
        $given  = $this->parse([
            '--stop-on-risky',
            '--stop-on-skipped',
            '--stop-on-incomplete',
            '--stop-on-deprecation',
            '--stop-on-notice',
            '--stop-on-warning',
        ]);

        foreach (['stopOnRisky', 'stopOnSkipped', 'stopOnIncomplete', 'stopOnDeprecation', 'stopOnNotice', 'stopOnWarning'] as $field) {
            $this->assertNull($absent->{$field}, $field . ' should default to null so the configuration decides');
            $this->assertTrue($given->{$field});
        }
    }

    public function testTheBlanketOffSwitchesSuppressWhatTheSameCommandLineAsked(): void
    {
        $logging = $this->parse([
            '--log-junit', 'junit.xml',
            '--log-markdown', 'report.md',
            '--log-pdf', 'report.pdf',
            '--log-events-json', 'events.ndjson',
            '--log-events-text', 'events.txt',
            '--log-events-verbose-text', 'events.verbose.txt',
            '--log-teamcity', 'teamcity.txt',
            '--report', 'pdf:out.pdf',
            '--subscriber', 'junit:out.xml',
            '--no-logging',
        ]);

        $this->assertNull($logging->logJunit);
        $this->assertNull($logging->logMarkdown);
        $this->assertNull($logging->logPdf);
        $this->assertNull($logging->logEventsJson);
        $this->assertNull($logging->logEventsText);
        $this->assertNull($logging->logEventsVerboseText);
        $this->assertNull($logging->logTeamcity);
        $this->assertSame([], $logging->report);
        $this->assertSame([], $logging->subscriber);

        $extensions = $this->parse(['--extension', 'A\\Plugin', '--no-extensions']);

        $this->assertTrue($extensions->noExtensions);
        $this->assertSame([], $extensions->extensionClasses);
    }

    public function testTheEventStreamTakesThreeIndependentFileTargets(): void
    {
        $absent = $this->parse([]);

        $this->assertNull($absent->logEventsText);
        $this->assertNull($absent->logEventsVerboseText);
        $this->assertNull($absent->logTeamcity);

        $given = $this->parse([
            '--log-events-text', 'events.txt',
            '--log-events-verbose-text', 'events.verbose.txt',
            '--log-teamcity', 'teamcity.txt',
        ]);

        $this->assertSame('events.txt', $given->logEventsText);
        $this->assertSame('events.verbose.txt', $given->logEventsVerboseText);
        $this->assertSame('teamcity.txt', $given->logTeamcity);

        // --log-teamcity is a target, not a view: it never selects one.
        $this->assertNull($given->teamcity);
    }

    public function testTheDependencySwitchesAreATriStateAndIsolationIsRunWide(): void
    {
        $absent = $this->parse([]);

        $this->assertNull($absent->resolveDependencies, 'absent means the default repair pass, not an override');
        $this->assertFalse($absent->processIsolation);

        $this->assertTrue($this->parse(['--resolve-dependencies'])->resolveDependencies);
        $this->assertFalse($this->parse(['--ignore-dependencies'])->resolveDependencies);
        $this->assertTrue($this->parse(['--process-isolation'])->processIsolation);

        $this->assertSame(
            'Options --resolve-dependencies and --ignore-dependencies are mutually exclusive.',
            CliOptions::fromArgv(['crucible', '--resolve-dependencies', '--ignore-dependencies']),
        );
    }

    public function testTheCoverageSurfaceAnswersOneQuestionAboutCollecting(): void
    {
        // Every report target has to imply collection, or a run writes an
        // empty report and says nothing about why.
        $this->assertFalse($this->parse([])->wantsCoverage());

        foreach ([
            ['--coverage'],
            ['--coverage-branch'],
            ['--coverage-clover', 'c.xml'],
            ['--coverage-cobertura', 'c.xml'],
            ['--coverage-html', 'dir'],
            ['--coverage-openclover', 'oc.xml'],
            ['--coverage-php', 'cov.php'],
            ['--coverage-text'],
            ['--coverage-text=cov.txt'],
        ] as $argv) {
            $this->assertTrue($this->parse($argv)->wantsCoverage(), implode(' ', $argv) . ' must ask for a driver');
        }

        // --coverage-text is the spec's one optional-value coverage
        // option: bare is the terminal, which is not the same as absent.
        $this->assertNull($this->parse([])->coverageText);
        $this->assertSame('', $this->parse(['--coverage-text'])->coverageText);
        $this->assertSame('cov.txt', $this->parse(['--coverage-text=cov.txt'])->coverageText);

        // ...and being optional, it never swallows the next argument.
        $bare = $this->parse(['--coverage-text', '--filter', 'X']);
        $this->assertSame('', $bare->coverageText);
        $this->assertSame('X', $bare->filter);
    }

    public function testNoCoverageSuppressesEveryCoverageTargetAndTheFilter(): void
    {
        $options = $this->parse([
            '--coverage-openclover', 'oc.xml',
            '--coverage-php', 'cov.php',
            '--coverage-text=cov.txt',
            '--coverage-filter', 'src',
            '--no-coverage',
        ]);

        $this->assertNull($options->coverageOpenClover);
        $this->assertNull($options->coveragePhp);
        $this->assertNull($options->coverageText);
        $this->assertSame([], $options->coverageFilter);
        $this->assertFalse($options->wantsCoverage());
    }

    public function testIncludePathSplitsOnThePlatformSeparator(): void
    {
        $this->assertSame([], $this->parse([])->includePaths);
        $this->assertSame(
            ['lib', 'vendor/legacy'],
            $this->parse(['--include-path', 'lib' . PATH_SEPARATOR . 'vendor/legacy'])->includePaths,
        );
    }

    public function testUselessTestReportingIsOnUntilTurnedOff(): void
    {
        $this->assertTrue($this->parse([])->reportUselessTests);
        $this->assertFalse($this->parse(['--do-not-report-useless-tests'])->reportUselessTests);
    }

    public function testTheCoverageFloorIsAPercentageNotAnInteger(): void
    {
        $this->assertSame(90.0, $this->parse(['--min', '90'])->minCoverage);
        $this->assertSame(99.5, $this->parse(['--min', '99.5'])->minCoverage);
        $this->assertNull($this->parse([])->minCoverage);
    }

    public function testTheCoverageFloorCollectsCoverageOnItsOwn(): void
    {
        // Asking for a floor is asking for the measurement it floors.
        // Requiring --coverage beside it would be a second flag that can
        // only ever be forgotten.
        $this->assertTrue($this->parse(['--min', '90'])->wantsCoverage());
    }

    public function testACoverageFloorOutsideZeroToOneHundredIsAnError(): void
    {
        foreach (['101', '-1', 'ninety'] as $bad) {
            $this->assertSame(
                sprintf('Option --min expects a percentage between 0 and 100, got "%s".', $bad),
                CliOptions::fromArgv(['crucible', '--min', $bad]),
            );
        }
    }

    public function testTheCoverageFloorAndNoCoverageAreMutuallyExclusive(): void
    {
        // Refused, not resolved: --no-coverage silently winning would
        // drop the gate and let the pipeline report success.
        $this->assertSame(
            'Options --min and --no-coverage are mutually exclusive.',
            CliOptions::fromArgv(['crucible', '--min', '90', '--no-coverage']),
        );
    }
}
