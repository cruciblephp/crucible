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
use LucianoPereira\Crucible\Extension\CommandGate;
use LucianoPereira\Crucible\Extension\Extension;
use LucianoPereira\Crucible\Impact\ImpactRule;
use LucianoPereira\Crucible\Runner\RetryPolicy;
use LucianoPereira\Crucible\Types\TypeTestSuite;
use LucianoPereira\Crucible\Vitest\VitestSuite;

/**
 * The fully resolved test-run configuration.
 *
 * This is the XML-free replacement for phpunit.xml: a plain, immutable,
 * fully typed value object produced by the Builder in the project's
 * crucible.php file. There is no schema validation step because there is
 * no schema — the PHP type system is the schema.
 */
final readonly class Configuration
{
    /**
     * @param list<TestSuite>        $testSuites
     * @param non-empty-string       $cacheDirectory
     * @param RetryPolicy            $retries     retry budget and backoff shape; a pass on retry is FLAKY
     * @param list<non-empty-string> $quarantine   test ids (file::name, optionally #dataset) whose failures do not affect the exit code
     * @param ?non-empty-string      $reportTitle  the report documents' title — project name or archival tag; null = the reporters' default
     * @param list<CommandGate>      $commandGates external tools orchestrated after the suite; each votes the exit code by its own exit status
     * @param list<Extension>        $extensions   PHP-native plugins (D-078); dispatched by role — a Check inspects the project after the suite and votes the exit code
     * @param list<VitestSuite>      $vitest       JavaScript suites (D-079) run through Vitest after the PHP suite; their results fold into the same run
     * @param list<TypeTestSuite>    $typeTests    type-test suites (D-130): assertType() calls analysed by PHPStan, each a test in the run
     * @param list<Quirk>            $quirks       incumbent bugs to reproduce rather than correct, each named individually
     * @param list<ImpactRule>       $impactRules  declared path → groups rules (D-083) for files the dependency graph cannot reach; additive only
     * @param array<string, array{class: class-string, params: array<string, mixed>}> $reportFormats report-format plugins, keyed by format key; a class-string + params registration (not an Extension instance), resolved by ReportFormatRegistry — deliberately separate from `extensions` above
     * @param array<string, array{class: class-string, params: array<string, mixed>}> $subscribers subscriber plugins, keyed by subscriber key; same class-string + params shape as $reportFormats, resolved by SubscriberRegistry — a subscriber's params reach its constructor rather than a call-time argument, since Listener::handle() has no room for them
     * @param array<string, array{class: class-string, params: array<string, mixed>}> $progressViews progress-view plugins, keyed by view key; same class-string + params shape as $subscribers, resolved by ProgressViewRegistry — exactly one is subscribed per run (single-select, unlike $subscribers' additive resolution)
     * @param ?non-empty-string      $retriggerHotFile where `--watch` publishes its retrigger endpoint (D-083); null = the endpoint is off
     * @param int                    $retriggerPort    0 = an ephemeral port
     */
    public function __construct(
        public array $testSuites,
        public Source $source,
        public Php $php,
        public ?string $bootstrap = null,
        public ?string $reportTitle = null,
        public string $cacheDirectory = '.crucible.cache',
        public bool $cacheResult = true,
        public bool $colors = false,
        public ExecutionOrder $executionOrder = ExecutionOrder::Declared,
        public bool $failOnDeprecation = false,
        public bool $failOnIncomplete = false,
        public bool $failOnNotice = false,
        public bool $failOnRisky = false,
        public bool $failOnSkipped = false,
        public bool $failOnWarning = false,
        public bool $failOnDirectDeprecation = false,
        public bool $failOnIndirectDeprecation = false,
        public bool $failOnSelfDeprecation = false,
        public bool $failOnEmptyTestSuite = true,
        public bool $stopOnDefect = false,
        public bool $stopOnError = false,
        public bool $stopOnFailure = false,
        public bool $stopOnRisky = false,
        public bool $stopOnSkipped = false,
        public bool $stopOnIncomplete = false,
        public bool $stopOnDeprecation = false,
        public bool $stopOnNotice = false,
        public bool $stopOnWarning = false,
        public bool $testdox = false,
        public ?bool $phpunitCompatibility = null,
        public array $quirks = [],
        public bool $backupGlobals = false,
        public bool $backupStaticProperties = false,
        public bool $beStrictAboutChangesToGlobalState = false,
        public bool $requireCoverageMetadata = false,
        public bool $beStrictAboutCoverageMetadata = false,
        public ?int $maxSelfDeprecations = null,
        public ?int $maxDirectDeprecations = null,
        public ?int $maxIndirectDeprecations = null,
        public RetryPolicy $retries = new RetryPolicy(),
        public array $quarantine = [],
        public BrowserConfiguration $browser = new BrowserConfiguration(),
        public array $commandGates = [],
        public array $extensions = [],
        public array $vitest = [],
        public array $typeTests = [],
        public array $impactRules = [],
        public array $reportFormats = [],
        public array $subscribers = [],
        public array $progressViews = [],
        public ?string $retriggerHotFile = null,
        public int $retriggerPort = 0,
    ) {}
}
