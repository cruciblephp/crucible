<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The classified backlog. compare.php answers "how many does the parser
 * refuse"; this answers "what would each cost", which is the only form of the
 * question that can be worked from.
 *
 * Every refusal carries a verdict and the evidence for it — the capability
 * that already ships, or the reason none does. Checking whether something is
 * already decided means searching the design record for the *capability*, not
 * for the option string; the notes below name the capability found.
 *
 *     php conformance/probes/20-cli-surface/ledger.php          # tally
 *     php conformance/probes/20-cli-surface/ledger.php alias    # one verdict
 *
 * Exits non-zero when the ledger and the parser disagree, so a newly covered
 * option cannot sit here claiming to be a gap, and a newly refused one cannot
 * go unrecorded.
 */

require __DIR__ . '/probe.php';
require __DIR__ . '/../../drift.php';

/**
 * Closed since the audit opened: still in the oracle's help, no longer refused
 * by Crucible's parser. Kept rather than deleted so the ledger records what
 * the work bought, and asserted from the other side — an entry here that the
 * parser still refuses is a regression.
 */
const COVERED = [
    '--retry'                                                  => 'accepted as the PHPUnit spelling of --retries; N is total attempts, converted at parse time',
    '--bootstrap'                                              => 'resolves the configuration ->bootstrap(); rides the worker manifest',
    '--extension'                                              => 'instantiates the class and registers it for the run, by role',
    '--globals-backup'                                         => 'resolves ->backupGlobals(); rides the worker manifest',
    '--static-backup'                                          => 'resolves ->backupStaticProperties(); rides the worker manifest',
    '--strict-global-state'                                    => 'resolves ->beStrictAboutChangesToGlobalState(); rides the worker manifest',
    '--require-coverage-contribution'                          => 'resolves ->requireCoverageMetadata(); rides the worker manifest',
    '--random-order'                                           => 'rewritten to --order-by=random before the parse loop',
    '--reverse-order'                                          => 'rewritten to --order-by=reverse before the parse loop',
    '--branch-coverage'                                        => 'rewritten to --coverage-branch before the parse loop',
    '--migrate-configuration'                                  => 'rewritten to the migrate-config command before the parse loop',
    '--no-coverage'                                            => 'suppresses every coverage spelling on the same command line',
    '--do-not-fail-on-deprecation'                             => 'the off half of the failOnDeprecation tri-state',
    '--do-not-fail-on-incomplete'                              => 'the off half of the failOnIncomplete tri-state',
    '--do-not-fail-on-notice'                                  => 'the off half of the failOnNotice tri-state',
    '--do-not-fail-on-risky'                                   => 'the off half of the failOnRisky tri-state',
    '--do-not-fail-on-skipped'                                 => 'the off half of the failOnSkipped tri-state',
    '--do-not-fail-on-warning'                                 => 'the off half of the failOnWarning tri-state',
    '--stop-on-risky'                                          => 'halts the in-process runner on Outcome::Risky',
    '--stop-on-skipped'                                        => 'halts the in-process runner on Outcome::Skipped',
    '--stop-on-incomplete'                                     => 'halts the in-process runner on Outcome::Incomplete',
    '--stop-on-deprecation'                                    => 'halts on the issue a test emitted, whatever its outcome',
    '--stop-on-notice'                                         => 'halts on the issue a test emitted, whatever its outcome',
    '--stop-on-warning'                                        => 'halts on the issue a test emitted, whatever its outcome',
    '--fail-on-self-deprecation'                               => 'judges the DeprecationScope::Self_ count the collector already attributes',
    '--fail-on-direct-deprecation'                             => 'judges the DeprecationScope::Direct count',
    '--fail-on-indirect-deprecation'                           => 'judges the DeprecationScope::Indirect count',
    '--do-not-fail-on-self-deprecation'                        => 'the off half of the failOnSelfDeprecation tri-state',
    '--do-not-fail-on-direct-deprecation'                      => 'the off half of the failOnDirectDeprecation tri-state',
    '--do-not-fail-on-indirect-deprecation'                    => 'the off half of the failOnIndirectDeprecation tri-state',
    '--fail-on-all-issues'                                     => 'turns on every fail-on policy; a negation alongside it still wins',
    '--fail-on-empty-test-suite'                               => 'the default already; the switch states it',
    '--do-not-fail-on-empty-test-suite'                        => 'makes an empty selection exit 0',
    '--fail-on-phpunit-deprecation'                            => 'accepted, inert: Crucible raises no framework-internal issues',
    '--fail-on-phpunit-notice'                                 => 'accepted, inert: Crucible raises no framework-internal issues',
    '--fail-on-phpunit-warning'                                => 'accepted, inert: Crucible raises no framework-internal issues',
    '--do-not-fail-on-phpunit-deprecation'                     => 'accepted, inert: Crucible raises no framework-internal issues',
    '--do-not-fail-on-phpunit-notice'                          => 'accepted, inert: Crucible raises no framework-internal issues',
    '--do-not-fail-on-phpunit-warning'                         => 'accepted, inert: Crucible raises no framework-internal issues',
    '--display-phpunit-deprecations'                           => 'accepted, inert: Crucible raises no framework-internal issues',
    '--display-phpunit-notices'                                => 'accepted, inert: Crucible raises no framework-internal issues',
    '--exclude-filter'                                         => 'the negated --filter, in TestSelection; exclusion wins',
    '--exclude-testsuite'                                      => 'skips the named suites in discovery; wins over --testsuite',
    '--test-suffix'                                            => 'overrides the suffix each suite declares; *.pest.php and *.crucible.php stay unconditional',
    '--covers'                                                 => 'selects on the Covers* attributes, class or class::method',
    '--uses'                                                   => 'selects on the Uses* attributes, class or class::method',
    '--requires-php-extension'                                 => 'selects on the RequiresPhpExtension attribute',
    '--run-test-id'                                            => 'selects by TestId::toString(), accumulating',
    '--test-id-filter-file'                                    => 'the same selection read from a file, # comments allowed',
    '--test-files-file'                                        => 'selects by file, absolute or working-directory-relative',
    '--list-tests'                                             => 'prints the selection instead of running it, exit 0',
    '--list-test-ids'                                          => 'prints ids ready to feed back to --run-test-id',
    '--list-test-files'                                        => 'prints the files the selection lives in',
    '--list-groups'                                            => 'prints the groups the selection belongs to',
    '--list-suites'                                            => 'prints the configured suites',
    '--list-tests-xml'                                         => 'prints the selection as XML',
    '--no-logging'                                             => 'suppresses every log target on the same command line',
    '--no-extensions'                                          => 'registers none, configured or from --extension',
    '--no-configuration'                                       => 'runs without reading crucible.php at all',
    '--include-path'                                           => 'prepends to PHP include_path before the bootstrap; rides the manifest',
    '--do-not-report-useless-tests'                            => 'turns off the no-assertion risky classification; rides the manifest',
    '--atleast-version'                                        => 'exit 0 only if the running version is at least the given one',
    '--check-version'                                          => 'answered rather than performed: Crucible has no update channel',
    '--compact'                                                => 'progress and tally only; a declared ConsoleReporter parameter',
    '--columns'                                                => 'progress characters per line; "max" resolves to a fixed wide value',
    '--no-progress'                                            => 'suppresses the progress characters',
    '--no-results'                                             => 'suppresses the problem list and the passed tree',
    '--no-output'                                              => 'subscribes no view and buffers the post-run block away; the verdict is unchanged',
    '--generate-baseline'                                      => 'writes a fresh deprecations baseline and suppresses against it for the run',
    '--use-baseline'                                           => 'reads the deprecations baseline from the given path; rides the manifest',
    '--ignore-baseline'                                        => 'runs against no baseline, whatever the configuration selected',
    '--log-events-text'                                        => 'the event stream as one line per event: sequence and name',
    '--log-events-verbose-text'                                => 'the same, keeping each event payload field',
    '--log-teamcity'                                           => 'the registered teamcity view resolved onto a file, leaving the progress output alone',
    '--resolve-dependencies'                                   => 'runs the scheduler #[Depends] repair pass, which is the default; the switch states it',
    '--ignore-dependencies'                                    => 'drops the repair pass; an unmet #[Depends] still skips, as in the spec',
    '--process-isolation'                                      => 'every test gets its own worker; #[Depends] values ride the boundary with it',
    '--coverage-text'                                          => 'the text report to a chosen sink; bare is the terminal, as the spec documents',
    '--only-summary-for-coverage-text'                         => 'totals only; the per-file rows are dropped, the numbers are not',
    '--show-uncovered-for-coverage-text'                       => 'keeps the rows for files with no covered line, which the report otherwise elides',
    '--coverage-php'                                           => 'a PHP file returning the run coverage data, loadable back',
    '--coverage-openclover'                                    => 'the clover document in the maintained fork framing',
    '--coverage-filter'                                        => 'directories added to the collection scope; rides the manifest',
    '--disable-coverage-targeting'                             => 'each test contributes everything it executed, not only its declared targets',
    '--disable-coverage-ignore'                                => 'accepted, already satisfied: Crucible has no coverage-ignore metadata to disable',
    '--include-git-information'                                => 'records commit, branch, and cleanliness in the serialized coverage data',
    '--coverage-crap4j'                                        => 'CRAP per method, over token-read complexity validated against the spec calculator',
    '--coverage-xml'                                           => 'an index plus one document per file: classes, methods, and which test reached each line',
    '--exclude-source-from-xml-coverage'                       => 'drops the source element from those documents',
    '--without-class-view'                                     => 'drops the per-method table from the HTML report',
    '--without-file-view'                                      => 'drops the annotated source pages, and the links that would 404 without them',
    '--testdox-text'                                           => 'the documentation view as a file-backed subscriber, like --log-junit',
    '--testdox-html'                                           => 'the same listing as a self-contained HTML page',
    '--testdox-summary'                                        => 'the run tally alone, from the same collector',
    '--repeat'                                                 => 'every selected test runs N times as ordinary members of the plan; excludes the retry options',
    '--enforce-time-limit'                                     => 'a test slower than its declared size allows is risky; measured, not interrupted',
    '--default-time-limit'                                     => 'the budget for a test that declares no size',
    '--disallow-test-output'                                   => 'test output is captured, reported as an event, and policed by this switch',
    '--check-php-configuration'                                => 'the advisory php.ini audit, byte-identical to the oracle on every setting',
    '--warn-when-php-is-not-configured-for-development'        => 'the same advisory printed before a run',
    '--do-not-warn-when-php-is-not-configured-for-development' => 'the off half of that tri-state, and the default',
    '--path-coverage'                                          => 'whole routes through a function, from the same xdebug pass as branches',
    '--record-test-run-history'                                => 'rewritten to --cache-result: the result cache is the per-test run history',
    '--do-not-record-test-run-history'                         => 'rewritten to --do-not-cache-result',
    '--with-telemetry'                                         => 'elapsed time and peak memory on each event-text line, in the spec format',
    '--warm-coverage-cache'                                    => 'parses the coverage scope up front; the analysis cache is keyed by path and mtime',
    '--log-otr'                                                => 'the opentest4j event schema, written live as a subscriber',
    '--generate-configuration'                                 => 'rewritten to --init, which writes the PHP configuration',
    '--validate-configuration'                                 => 'no XML schema to check, so it checks what a PHP configuration can be wrong about: that it loads, and that every path it names exists',
    '--all'                                                    => 'accepted, already satisfied: there is no configuration-level test selection to ignore',
    '--stderr'                                                 => 'resolves the progress view onto STDERR',
    '--reverse-list'                                           => 'lists the problems last-first',
    '--diff-context'                                           => 'collapses unchanged lines in a diff; run-wide state on the Differ, rides the manifest',
    '--display-deprecations'                                   => 'lists the IssueLog deprecations with file, line, and scope',
    '--display-notices'                                        => 'lists the IssueLog notices',
    '--display-warnings'                                       => 'lists the IssueLog warnings',
    '--display-errors'                                         => 'lists the errored tests and their reasons, from a new OutcomeLog listener',
    '--display-skipped'                                        => 'lists the skipped tests and their reasons',
    '--display-incomplete'                                     => 'lists the incomplete tests and their reasons',
    '--display-all-issues'                                     => 'resolves to all six at parse time',
];

/**
 * The backlog, now empty: every option the oracle offers is accepted.
 * Kept rather than deleted, because the check below runs in both
 * directions — a newly refused option lands here as unrecorded drift,
 * and this is where its verdict would go.
 *
 * alias    the capability ships; only this spelling is missing
 * wiring   the machinery ships; a target, negation, or resolution is missing
 * new      needs machinery Crucible does not have
 * declined the design record assessed the capability and chose against it
 */
const LEDGER = [];

\require_oracle();

$rejected = \rejected_options(\oracle_options());

// All three directions are the shared check: the backlog says what may
// still be refused, COVERED says what must not be refused again.
$drift = \Drift::between(
    $rejected,
    \array_map(static fn(array $entry): string => $entry[1], LEDGER),
    COVERED,
);

$filter = $argv[1] ?? null;
$tally  = [];

foreach (LEDGER as $option => [$verdict, $note]) {
    $tally[$verdict] = ($tally[$verdict] ?? 0) + 1;

    if ($filter === $verdict) {
        \printf("  %-52s %s\n", $option, $note);
    }
}

if ($filter !== null) {
    echo "\n";
}

\printf("rejected by crucible's parser: %d\n", \count($rejected));

foreach (['alias', 'wiring', 'new', 'declined'] as $verdict) {
    \printf("  %-9s %3d\n", $verdict, $tally[$verdict] ?? 0);
}

\printf("covered since the audit opened: %d\n", \count(COVERED));

$status = 0;

if (!$drift->clean()) {
    \printf("\n%s", $drift->report('backlog', 'a newly refused option: give it a verdict'));
    $status = 1;
}

exit($status);
