<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

/**
 * The runner-facing slice of the configuration.
 */
final readonly class RunnerOptions
{
    /**
     * @param list<non-empty-string> $projectDirectories  absolute prefixes counting as own code (deprecation attribution)
     * @param list<non-empty-string> $deprecationBaseline suppressed deprecation keys, "file|message"
     * @param RetryPolicy            $retries             retry budget and backoff shape (G4/D-043); pass-on-retry is FLAKY
     * @param list<non-empty-string> $quarantine          test ids whose failures do not affect the exit code (G4)
     * @param array<string, list<list<int>>> $propertyFailures the failure database (D-040), keyed test-id#property N
     * @param bool                   $fullSuite           no CLI selection narrowed the plan (D-071); with every planned test finished, run:finish reports complete
     * @param bool                   $disallowTestOutput  the spec's --disallow-test-output: a test that prints is risky
     * @param bool                   $enforceTimeLimit    the spec's --enforce-time-limit: a test slower than its size allows is risky
     * @param ?int                   $defaultTimeLimit    the spec's --default-time-limit, in seconds, for a test that declares no size
     * @param bool                   $coverageTargeting   honour the Covers and Uses metadata when deciding what a test contributes; false is the spec's --disable-coverage-targeting
     */
    public function __construct(
        public bool $backupGlobals = false,
        public bool $backupStaticProperties = false,
        public bool $beStrictAboutChangesToGlobalState = false,
        public bool $requireCoverageMetadata = false,
        public bool $beStrictAboutCoverageMetadata = false,
        public bool $coverageTargeting = true,
        public bool $disallowTestOutput = false,
        public bool $enforceTimeLimit = false,
        public ?int $defaultTimeLimit = null,
        public bool $stopOnDefect = false,
        public bool $stopOnError = false,
        public bool $stopOnFailure = false,
        public bool $stopOnRisky = false,
        public bool $stopOnSkipped = false,
        public bool $stopOnIncomplete = false,
        public bool $stopOnDeprecation = false,
        public bool $stopOnNotice = false,
        public bool $stopOnWarning = false,
        public array $projectDirectories = [],
        public WorkingDirectory $workingDirectory = new WorkingDirectory('/'),
        public array $deprecationBaseline = [],
        public RetryPolicy $retries = new RetryPolicy(),
        public array $quarantine = [],
        public array $propertyFailures = [],
        public bool $updateSnapshots = false,
        public bool $fullSuite = false,
        public bool $inlineSnapshotRewrite = false,
        public bool $reportUselessTests = true,
    ) {}

    /**
     * @param ?bool                  $stopOnDefect        CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnError         CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnFailure       CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnRisky         CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnSkipped       CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnIncomplete    CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnDeprecation   CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnNotice        CLI override; null falls back to the configuration
     * @param ?bool                  $stopOnWarning       CLI override; null falls back to the configuration
     * @param list<non-empty-string> $projectDirectories
     * @param list<non-empty-string> $deprecationBaseline
     * @param ?int<0, max>           $retries             CLI count override; the configured backoff shape survives it
     * @param array<string, list<list<int>>> $propertyFailures
     * @param ?bool                  $strictCoverage      CLI --strict-coverage override (D-063); null falls back to the configuration
     * @param bool                   $fullSuite           no CLI selection narrowed the plan (D-071)
     * @param Overrides              $overrides           CLI values that win over the configuration's, carried across the worker boundary
     */
    public static function fromConfiguration(
        Configuration $configuration,
        ?bool $stopOnDefect = null,
        ?bool $stopOnError = null,
        ?bool $stopOnFailure = null,
        ?bool $stopOnRisky = null,
        ?bool $stopOnSkipped = null,
        ?bool $stopOnIncomplete = null,
        ?bool $stopOnDeprecation = null,
        ?bool $stopOnNotice = null,
        ?bool $stopOnWarning = null,
        array $projectDirectories = [],
        WorkingDirectory $workingDirectory = new WorkingDirectory('/'),
        array $deprecationBaseline = [],
        ?int $retries = null,
        array $propertyFailures = [],
        bool $updateSnapshots = false,
        ?bool $strictCoverage = null,
        bool $fullSuite = false,
        bool $inlineSnapshotRewrite = false,
        Overrides $overrides = new Overrides(),
    ): self {
        return new self(
            backupGlobals: $overrides->backupGlobals ?? $configuration->backupGlobals,
            backupStaticProperties: $overrides->backupStaticProperties ?? $configuration->backupStaticProperties,
            beStrictAboutChangesToGlobalState: $overrides->beStrictAboutChangesToGlobalState ?? $configuration->beStrictAboutChangesToGlobalState,
            requireCoverageMetadata: $overrides->requireCoverageMetadata ?? $configuration->requireCoverageMetadata,
            beStrictAboutCoverageMetadata: $strictCoverage ?? $configuration->beStrictAboutCoverageMetadata,
            coverageTargeting: $overrides->coverageTargeting ?? true,
            disallowTestOutput: $overrides->disallowTestOutput ?? false,
            enforceTimeLimit: $overrides->enforceTimeLimit ?? false,
            defaultTimeLimit: $overrides->defaultTimeLimit,
            stopOnDefect: $stopOnDefect ?? $configuration->stopOnDefect,
            stopOnError: $stopOnError ?? $configuration->stopOnError,
            stopOnFailure: $stopOnFailure ?? $configuration->stopOnFailure,
            stopOnRisky: $stopOnRisky ?? $configuration->stopOnRisky,
            stopOnSkipped: $stopOnSkipped ?? $configuration->stopOnSkipped,
            stopOnIncomplete: $stopOnIncomplete ?? $configuration->stopOnIncomplete,
            stopOnDeprecation: $stopOnDeprecation ?? $configuration->stopOnDeprecation,
            stopOnNotice: $stopOnNotice ?? $configuration->stopOnNotice,
            stopOnWarning: $stopOnWarning ?? $configuration->stopOnWarning,
            projectDirectories: $projectDirectories,
            workingDirectory: $workingDirectory,
            deprecationBaseline: $deprecationBaseline,
            retries: $retries !== null ? $configuration->retries->withCount($retries) : $configuration->retries,
            quarantine: $configuration->quarantine,
            propertyFailures: $propertyFailures,
            updateSnapshots: $updateSnapshots,
            fullSuite: $fullSuite,
            inlineSnapshotRewrite: $inlineSnapshotRewrite,
            reportUselessTests: $overrides->reportUselessTests ?? true,
        );
    }
}
