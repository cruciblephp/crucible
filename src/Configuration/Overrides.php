<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

use function array_filter;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * The configuration and runner values a command line may override, in one
 * object.
 *
 * These are the overrides that must survive the process boundary: a
 * worker loads the project's configuration from disk and would
 * otherwise run with settings the parent was told to change, so a
 * `--parallel` run would quietly disagree with its sequential twin.
 * Carrying them as one manifest field rather than one field each keeps
 * that plumbing to a single seam.
 *
 * Every field is nullable and null means "not given", so resolution
 * stays the null-coalesce the rest of the CLI uses.
 */
final readonly class Overrides
{
    /**
     * @param ?non-empty-string $bootstrap      the spec's --bootstrap; wins over the configuration's
     * @param ?int              $diffContext    the spec's --diff-context: unchanged lines kept around each change
     * @param ?non-empty-string $baselineFile   the deprecations baseline to read; null = the cache directory's
     * @param ?bool             $ignoreBaseline the spec's --ignore-baseline: read no baseline at all
     * @param ?bool             $resolveDependencies the spec's --resolve-dependencies/--ignore-dependencies: run the scheduler's #[Depends] repair pass
     * @param ?bool             $coverageTargeting   the spec's --disable-coverage-targeting: false makes a test contribute everything it executed
     * @param list<non-empty-string> $coverageFilter the spec's --coverage-filter: directories added to the collection scope
     * @param ?bool             $disallowTestOutput the spec's --disallow-test-output
     * @param ?bool             $enforceTimeLimit  the spec's --enforce-time-limit
     * @param ?int              $defaultTimeLimit  the spec's --default-time-limit, in seconds
     * @param ?int              $repeat            the spec's --repeat: run every selected test this many times; anything under 2 repeats nothing, which {@see Repetition::apply()} is what decides
     */
    public function __construct(
        public ?string $bootstrap = null,
        public ?bool $backupGlobals = null,
        public ?bool $backupStaticProperties = null,
        public ?bool $beStrictAboutChangesToGlobalState = null,
        public ?bool $requireCoverageMetadata = null,
        public ?bool $reportUselessTests = null,
        public ?int $diffContext = null,
        public ?string $baselineFile = null,
        public ?bool $ignoreBaseline = null,
        public ?bool $resolveDependencies = null,
        public ?bool $coverageTargeting = null,
        public array $coverageFilter = [],
        public ?int $repeat = null,
        public ?bool $disallowTestOutput = null,
        public ?bool $enforceTimeLimit = null,
        public ?int $defaultTimeLimit = null,
    ) {}

    /**
     * @return array<string, bool|int|string|list<non-empty-string>>
     */
    public function toArray(): array
    {
        return array_filter([
            'bootstrap'                         => $this->bootstrap,
            'backupGlobals'                     => $this->backupGlobals,
            'backupStaticProperties'            => $this->backupStaticProperties,
            'beStrictAboutChangesToGlobalState' => $this->beStrictAboutChangesToGlobalState,
            'requireCoverageMetadata'           => $this->requireCoverageMetadata,
            'reportUselessTests'                => $this->reportUselessTests,
            'diffContext'                       => $this->diffContext,
            'baselineFile'                      => $this->baselineFile,
            'ignoreBaseline'                    => $this->ignoreBaseline,
            'resolveDependencies'               => $this->resolveDependencies,
            'coverageTargeting'                 => $this->coverageTargeting,
            'repeat'                            => $this->repeat,
            'disallowTestOutput'                => $this->disallowTestOutput,
            'enforceTimeLimit'                  => $this->enforceTimeLimit,
            'defaultTimeLimit'                  => $this->defaultTimeLimit,
        ], static fn(bool|int|string|null $value): bool => $value !== null)
            + ($this->coverageFilter === [] ? [] : ['coverageFilter' => $this->coverageFilter]);
    }

    /**
     * Decoded JSON, so the keys are whatever the manifest carried: every
     * field is validated on the way in rather than assumed.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn(string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== ''
            ? $data[$key]
            : null;

        $bool = static fn(string $key): ?bool => isset($data[$key]) && is_bool($data[$key])
            ? $data[$key]
            : null;

        $int = static fn(string $key): ?int => isset($data[$key]) && is_int($data[$key])
            ? $data[$key]
            : null;


        /** @return list<non-empty-string> */
        $strings = static function (string $key) use ($data): array {
            $values = [];

            foreach (isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [] as $value) {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            }

            return $values;
        };

        return new self(
            bootstrap: $string('bootstrap'),
            backupGlobals: $bool('backupGlobals'),
            backupStaticProperties: $bool('backupStaticProperties'),
            beStrictAboutChangesToGlobalState: $bool('beStrictAboutChangesToGlobalState'),
            requireCoverageMetadata: $bool('requireCoverageMetadata'),
            reportUselessTests: $bool('reportUselessTests'),
            diffContext: $int('diffContext'),
            baselineFile: $string('baselineFile'),
            ignoreBaseline: $bool('ignoreBaseline'),
            resolveDependencies: $bool('resolveDependencies'),
            coverageTargeting: $bool('coverageTargeting'),
            coverageFilter: $strings('coverageFilter'),
            repeat: $int('repeat'),
            disallowTestOutput: $bool('disallowTestOutput'),
            enforceTimeLimit: $bool('enforceTimeLimit'),
            defaultTimeLimit: $int('defaultTimeLimit'),
        );
    }
}
