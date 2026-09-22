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
use function count;
use function json_encode;

use const DATE_ATOM;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

/**
 * A modern, script-friendly JSON summary — tool/version/summary/
 * problems, sourced entirely from the same `Document` every other
 * format renders (only `FoldingTree` is walked; it is the one block
 * still holding the raw `TestFinished` events `ProblemList` already
 * flattened away). A pipeline or dashboard that doesn't want to
 * replay the NDJSON event stream gets one finished object instead.
 */
final readonly class JsonRenderer
{
    public function render(Document $document, ReportContext $context): string
    {
        $tree = $this->foldingTree($document);

        $passed   = $this->passedCount($tree);
        $problems = $tree->problems();

        $report = [
            'tool'    => 'crucible',
            'version' => Version::NUMBER,
            'title'   => $context->title,
            // Omitted, not null: a reproducible run with no
            // SOURCE_DATE_EPOCH has nothing true to say here, and
            // `"generatedAt": null` still asserts the field.
            ...$context->createdAt instanceof DateTimeImmutable
                ? ['generatedAt' => $context->createdAt->format(DATE_ATOM)]
                : [],
            'summary' => [
                'total'  => $passed + count($problems),
                'passed' => $passed,
                ...$this->outcomeTally($problems),
                'duration' => $context->runtime,
            ],
            'problems' => array_map($this->problem(...), $problems),
        ];

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
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

    private function passedCount(FoldingTree $tree): int
    {
        $passed = $tree->foldedTestCount;

        foreach ($tree->visible as $node) {
            $passed += $node->subtreePassedCount();
        }

        return $passed;
    }

    /**
     * @param list<TestFinished> $problems
     *
     * @return array{failed: int, errored: int, skipped: int, incomplete: int, risky: int}
     */
    private function outcomeTally(array $problems): array
    {
        $tally = ['failed' => 0, 'errored' => 0, 'skipped' => 0, 'incomplete' => 0, 'risky' => 0];

        foreach ($problems as $test) {
            $key = match ($test->outcome) {
                Outcome::Failed     => 'failed',
                Outcome::Errored    => 'errored',
                Outcome::Skipped    => 'skipped',
                Outcome::Incomplete => 'incomplete',
                Outcome::Risky      => 'risky',
                Outcome::Passed     => throw new UnexpectedValueException('A passed test cannot be a problem.'),
            };

            $tally[$key]++;
        }

        return $tally;
    }

    /**
     * @return array{test: string, file: string, line: ?int, outcome: string, message: ?string, quarantined: bool}
     */
    private function problem(TestFinished $test): array
    {
        return [
            'test'        => $test->test->toString(),
            'file'        => $test->test->file,
            'line'        => FailureLocation::of($test)?->line,
            'outcome'     => $test->outcome->value,
            'message'     => $test->failure?->message ?? $test->reason,
            'quarantined' => $test->quarantined,
        ];
    }
}
