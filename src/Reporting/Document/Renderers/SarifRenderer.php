<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Renderers;

use DateTimeImmutable;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\Renderers\Support\FailureLocation;
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportContext;
use LucianoPereira\Crucible\Version;
use UnexpectedValueException;

use function array_map;
use function json_encode;
use function round;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

/**
 * SARIF 2.1.0 — the OASIS static-analysis-results standard, ingested
 * natively by GitHub Code Scanning (PR inline annotations, the
 * Security tab) and Azure DevOps. Each non-passed test becomes one
 * `result`; Failed/Errored map to `error`, Incomplete/Risky to
 * `warning`, Skipped to `note`, mirroring how severity already reads
 * in every other reporter. Sourced from the same `Document` as every
 * other format via {@see FoldingTree}, the one block still holding
 * the raw `TestFinished` events (file, failure trace) that
 * `ProblemList` already flattened away.
 */
final readonly class SarifRenderer
{
    /**
     * @var array<string, array{ruleId: string, name: string, level: string, description: string, fallback: string}>
     */
    private const array KINDS = [
        'fail' => [
            'ruleId'      => 'test-failed', 'name' => 'TestFailed', 'level' => 'error',
            'description' => 'An assertion did not hold.', 'fallback' => 'Test failed.',
        ],
        'error' => [
            'ruleId'      => 'test-errored', 'name' => 'TestErrored', 'level' => 'error',
            'description' => 'The test threw or triggered a fatal error.', 'fallback' => 'Test errored.',
        ],
        'skip' => [
            'ruleId'      => 'test-skipped', 'name' => 'TestSkipped', 'level' => 'note',
            'description' => 'The test was skipped.', 'fallback' => 'Test skipped.',
        ],
        'incomplete' => [
            'ruleId'      => 'test-incomplete', 'name' => 'TestIncomplete', 'level' => 'warning',
            'description' => 'The test is marked incomplete.', 'fallback' => 'Test incomplete.',
        ],
        'risky' => [
            'ruleId'      => 'test-risky', 'name' => 'TestRisky', 'level' => 'warning',
            'description' => 'The test passed but is considered risky.', 'fallback' => 'Test risky.',
        ],
    ];

    public function render(Document $document, ReportContext $context): string
    {
        $problems = $this->foldingTree($document)->problems();

        $sarif = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs'    => [
                [
                    'tool' => [
                        'driver' => [
                            'name'           => 'crucible',
                            'informationUri' => 'https://github.com/cruciblephp/crucible',
                            'version'        => Version::NUMBER,
                            'rules'          => $this->rules(),
                        ],
                    ],
                    'invocations' => [$this->invocation($context)],
                    'results'     => array_map($this->result(...), $problems),
                ],
            ],
        ];

        return json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    private function foldingTree(Document $document): FoldingTree
    {
        foreach ($document->blocks as $block) {
            if ($block instanceof FoldingTree) {
                return $block;
            }
        }

        throw new UnexpectedValueException('Document has no FoldingTree block — every RunReportDocument includes one.');
    }

    /**
     * `executionSuccessful` is SARIF's own term for "did the analysis
     * tool complete without crashing" — separate from whether any
     * results were found, which `results` already conveys. Reaching
     * this method at all means the run finished, so it is always true.
     *
     * @return array{executionSuccessful: bool, startTimeUtc?: string, endTimeUtc?: string}
     */
    private function invocation(ReportContext $context): array
    {
        $invocation = ['executionSuccessful' => true];

        if ($context->createdAt instanceof DateTimeImmutable) {
            $milliseconds = (int) round($context->runtime * 1000);

            $invocation['startTimeUtc'] = $context->createdAt->format('Y-m-d\TH:i:s.v\Z');
            $invocation['endTimeUtc']   = $context->createdAt
                ->modify(sprintf('+%d milliseconds', $milliseconds))
                ->format('Y-m-d\TH:i:s.v\Z');
        }

        return $invocation;
    }

    /**
     * @return list<array{id: string, name: string, shortDescription: array{text: string}}>
     */
    private function rules(): array
    {
        $rules = [];

        foreach (self::KINDS as $kind) {
            $rules[] = ['id' => $kind['ruleId'], 'name' => $kind['name'], 'shortDescription' => ['text' => $kind['description']]];
        }

        return $rules;
    }

    /**
     * @return array{ruleId: string, level: string, message: array{text: string}, locations: list<array{physicalLocation: array{artifactLocation: array{uri: string}, region?: array{startLine: int}}}>}
     */
    private function result(TestFinished $test): array
    {
        $kind     = $this->kind($test->outcome);
        $location = ['artifactLocation' => ['uri' => $test->test->file]];
        $frame    = FailureLocation::of($test);

        if ($frame instanceof \LucianoPereira\Crucible\Event\Frame) {
            $location['region'] = ['startLine' => $frame->line];
        }

        return [
            'ruleId'    => $kind['ruleId'],
            'level'     => $kind['level'],
            'message'   => ['text' => $test->failure?->message ?? $test->reason ?? $kind['fallback']],
            'locations' => [['physicalLocation' => $location]],
        ];
    }

    /**
     * @return array{ruleId: string, name: string, level: string, description: string, fallback: string}
     */
    private function kind(Outcome $outcome): array
    {
        return self::KINDS[$outcome->value] ?? throw new UnexpectedValueException('A passed test cannot be a problem.');
    }
}
