<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * What the supervisor hands a worker on stdin: the configuration to
 * load, the work to run, and the ordering to apply — everything a
 * worker needs to reproduce its slice of the run deterministically,
 * as plain JSON. The manifest is the entire input protocol; the
 * NDJSON event stream on stdout is the entire output protocol.
 */
final readonly class WorkerManifest
{
    /**
     * @param non-empty-string  $configuration   absolute path of the configuration file
     * @param list<WorkUnit>    $units
     * @param ?positive-int     $testToken       ParaTest-compatible worker-slot number (TEST_TOKEN)
     * @param ?non-empty-string $uniqueTestToken run-unique per-slot token (UNIQUE_TEST_TOKEN)
     * @param ?int<0, max>      $retries         the run's resolved retry budget (G4); null = configuration decides
     * @param ?non-empty-string $coverageArtifact where this worker writes its CoverageData JSON (D-041); null = no coverage
     * @param bool              $coverageBranch   collect branch coverage in the window (D-062, xdebug only)
     * @param bool              $pathCoverage     also collect path analysis (--path-coverage, xdebug only)
     * @param ?string           $globalState      the parent's exported constants and globals, for #[PreserveGlobalState(true)]; null = boot clean
     * @param ?bool             $strictCoverage   CLI --strict-coverage override (D-063); null = configuration decides
     * @param ?non-empty-string $browser          CLI --browser engine override (D-064); null = configuration decides
     * @param bool              $debug            CLI --debug headed override (D-064)
     * @param Overrides         $overrides        configuration values the CLI overrode; a worker loads the file from disk and would otherwise disagree with its sequential twin
     * @param list<non-empty-string> $includePaths the spec's --include-path, applied before the worker's bootstrap
     * @param array<string, string>  $dependencyValues    name => base64 of a serialized #[Depends] return value produced by an earlier unit
     * @param list<string>           $provideValues       names whose return value a later unit depends on, so this worker records them
     * @param ?non-empty-string      $dependencyArtifact  where this worker writes the values it was asked to provide; null = none wanted
     */
    public function __construct(
        public string $configuration,
        public array $units,
        public ExecutionOrder $order = ExecutionOrder::Declared,
        public int $seed = 0,
        public ?int $testToken = null,
        public ?string $uniqueTestToken = null,
        public ?int $retries = null,
        public ?string $coverageArtifact = null,
        public bool $updateSnapshots = false,
        public bool $coverageBranch = false,
        public ?bool $strictCoverage = null,
        public ?string $browser = null,
        public bool $debug = false,
        public Overrides $overrides = new Overrides(),
        public array $includePaths = [],
        public array $dependencyValues = [],
        public array $provideValues = [],
        public ?string $dependencyArtifact = null,
        public bool $pathCoverage = false,
        public ?string $globalState = null,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'configuration' => $this->configuration,
            'units'         => array_map(
                static fn(WorkUnit $unit): array => ['file' => $unit->file, 'tests' => $unit->tests],
                $this->units,
            ),
            'order'              => $this->order->value,
            'seed'               => $this->seed,
            'testToken'          => $this->testToken,
            'uniqueTestToken'    => $this->uniqueTestToken,
            'retries'            => $this->retries,
            'overrides'          => $this->overrides->toArray(),
            'includePaths'       => $this->includePaths,
            'coverageArtifact'   => $this->coverageArtifact,
            'updateSnapshots'    => $this->updateSnapshots,
            'coverageBranch'     => $this->coverageBranch,
            'strictCoverage'     => $this->strictCoverage,
            'browser'            => $this->browser,
            'debug'              => $this->debug,
            'dependencyValues'   => $this->dependencyValues,
            'provideValues'      => $this->provideValues,
            'dependencyArtifact' => $this->dependencyArtifact,
            'pathCoverage'       => $this->pathCoverage,
            'globalState'        => $this->globalState,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Narrows discovered groups to this manifest's work: groups whose
     * file no unit names are dropped; a unit with a test list keeps
     * the entries it names — a declared name keeps every dataset row
     * of it, a full id string (file::name#dataset) keeps exactly that
     * row, so supervisor-side selection survives the process boundary.
     *
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    public function filter(array $groups): array
    {
        $filtered = [];

        foreach ($groups as $group) {
            if ($group->tests === []) {
                continue;
            }

            $file      = $group->tests[0]->id->file;
            $names     = [];
            $wholeFile = false;
            $matched   = false;

            foreach ($this->units as $unit) {
                if ($unit->file !== $file) {
                    continue;
                }

                $matched = true;

                if ($unit->tests === null) {
                    $wholeFile = true;

                    break;
                }

                $names = [...$names, ...$unit->tests];
            }

            if (!$matched) {
                continue;
            }

            if ($wholeFile) {
                $filtered[] = $group;

                continue;
            }

            $tests = array_values(array_filter(
                $group->tests,
                static fn(TestDefinition $test): bool => in_array($test->id->name, $names, true)
                    || in_array($test->id->toString(), $names, true),
            ));

            if ($tests !== []) {
                $filtered[] = new TestGroup($group->name, $tests, $group->beforeAll, $group->afterAll);
            }
        }

        return $filtered;
    }

    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return null;
        }

        $configuration = $decoded['configuration'] ?? null;
        $order         = ExecutionOrder::tryFrom(is_string($decoded['order'] ?? null) ? $decoded['order'] : '');
        $seed          = $decoded['seed'] ?? 0;

        if (!is_string($configuration) || $configuration === '' || !$order instanceof ExecutionOrder || !is_int($seed)) {
            return null;
        }

        $units = [];

        foreach (is_array($decoded['units'] ?? null) ? $decoded['units'] : [] as $unit) {
            if (!is_array($unit) || !is_string($unit['file'] ?? null) || $unit['file'] === '') {
                return null;
            }

            $tests = null;

            if (is_array($unit['tests'] ?? null)) {
                $tests = [];

                foreach ($unit['tests'] as $test) {
                    if (!is_string($test) || $test === '') {
                        return null;
                    }

                    $tests[] = $test;
                }
            }

            $units[] = new WorkUnit($unit['file'], $tests);
        }

        $testToken       = $decoded['testToken'] ?? null;
        $uniqueTestToken = $decoded['uniqueTestToken'] ?? null;
        $retries         = $decoded['retries'] ?? null;
        $includePaths    = [];

        foreach (is_array($decoded['includePaths'] ?? null) ? $decoded['includePaths'] : [] as $path) {
            if (is_string($path) && $path !== '') {
                $includePaths[] = $path;
            }
        }

        $coverageArtifact = $decoded['coverageArtifact'] ?? null;
        $provideValues    = [];

        foreach (is_array($decoded['provideValues'] ?? null) ? $decoded['provideValues'] : [] as $name) {
            if (is_string($name) && $name !== '') {
                $provideValues[] = $name;
            }
        }

        return new self(
            $configuration,
            $units,
            $order,
            $seed,
            is_int($testToken) && $testToken >= 1 ? $testToken : null,
            is_string($uniqueTestToken) && $uniqueTestToken !== '' ? $uniqueTestToken : null,
            is_int($retries) && $retries >= 0 ? $retries : null,
            is_string($coverageArtifact) && $coverageArtifact !== '' ? $coverageArtifact : null,
            ($decoded['updateSnapshots'] ?? false) === true,
            ($decoded['coverageBranch'] ?? false) === true,
            is_bool($decoded['strictCoverage'] ?? null) ? $decoded['strictCoverage'] : null,
            is_string($decoded['browser'] ?? null) && $decoded['browser'] !== '' ? $decoded['browser'] : null,
            ($decoded['debug'] ?? false) === true,
            Overrides::fromArray(is_array($decoded['overrides'] ?? null) ? $decoded['overrides'] : []),
            $includePaths,
            DependencyValues::fromArray(is_array($decoded['dependencyValues'] ?? null) ? $decoded['dependencyValues'] : []),
            $provideValues,
            is_string($decoded['dependencyArtifact'] ?? null) && $decoded['dependencyArtifact'] !== ''
                ? $decoded['dependencyArtifact']
                : null,
            ($decoded['pathCoverage'] ?? false) === true,
            is_string($decoded['globalState'] ?? null) && $decoded['globalState'] !== ''
                ? $decoded['globalState']
                : null,
        );
    }
}
