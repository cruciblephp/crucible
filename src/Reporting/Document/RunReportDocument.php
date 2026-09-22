<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Badge;
use LucianoPereira\Crucible\Reporting\Document\Blocks\BulletList;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Heading;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Paragraph;
use LucianoPereira\Crucible\Reporting\Document\Blocks\ProblemList;
use LucianoPereira\Crucible\Reporting\Document\Blocks\ProportionBar;
use LucianoPereira\Crucible\Reporting\Document\Blocks\RankedList;
use LucianoPereira\Crucible\Reporting\Document\Inline\Strong;
use LucianoPereira\Crucible\Reporting\Document\Inline\Text;
use LucianoPereira\Crucible\Reporting\PrettyName;
use LucianoPereira\Crucible\Reporting\RunModel;
use LucianoPereira\Crucible\Version;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function sprintf;

/**
 * Builds the one comprehensive `Document` every registered report
 * format renders: title, verdict badge, summary strip, a proportion
 * bar, problems grouped by severity, the untested-by-reason section
 * (D-075), the slowest tests, and the full passed/folded directory
 * tree. Ported from `PdfWriter`'s pre-existing assembly (the richer of
 * the two legacy writers) and now shared across every format instead
 * of duplicated per writer — the report-format extension system needs
 * exactly one canonical Document to hand any registered format, built
 * once per run regardless of how many formats are selected.
 */
final class RunReportDocument
{
    /**
     * @param ?non-empty-string $timingNotice why the durations below cannot be taken at face
     *                                        value, passed in rather than read from the
     *                                        environment: a persisted report that varies with
     *                                        the ini of whoever rendered it is not a report
     */
    public static function build(RunModel $model, RunFinished $event, bool $flaky, string $title, ?string $timingNotice = null): Document
    {
        $blocks   = [new Heading(1, [new Text($title)])];
        $blocks[] = Badge::forRun($event->summary, $flaky);
        $blocks[] = new Paragraph([new Text(sprintf('Crucible %s by %s', Version::NUMBER, Version::AUTHOR))]);
        $blocks[] = new Paragraph([new Text(self::strip($event))]);
        $blocks[] = self::proportionBar($event);

        foreach (self::problemBlocks($model->problems) as $block) {
            $blocks[] = $block;
        }

        foreach (self::untestedBlocks($model->untestedByReason) as $block) {
            $blocks[] = $block;
        }

        $slowest = RankedList::build($model->sections, $event->duration);

        if ($slowest->entries !== []) {
            $blocks[] = new Heading(2, [new Text('Slowest tests')]);

            if ($timingNotice !== null) {
                $blocks[] = new Paragraph([new Text($timingNotice)]);
            }

            $blocks[] = $slowest;
        }

        $blocks[] = new Heading(2, [new Text('Results')]);
        $blocks[] = FoldingTree::build($model->sections, $event->duration);

        return new Document($blocks);
    }

    private static function strip(RunFinished $event): string
    {
        $summary = $event->summary;
        $flagged = $summary->total() - $summary->passed - $summary->untested;

        $strip = sprintf('%d tests · %d passed', $summary->total(), $summary->passed);

        if ($flagged > 0) {
            $strip .= sprintf(' · %d flagged', $flagged);
        }

        if ($summary->untested > 0) {
            $strip .= sprintf(' · %d untested', $summary->untested);
        }

        return $strip . sprintf(' · %.3fs', $event->duration);
    }

    private static function proportionBar(RunFinished $event): ProportionBar
    {
        $summary = $event->summary;

        return new ProportionBar([
            new ProportionShare($summary->passed, Tone::Success),
            new ProportionShare($summary->skipped, Tone::Muted),
            new ProportionShare($summary->incomplete + $summary->risky, Tone::Caution),
            new ProportionShare($summary->failed + $summary->errored, Tone::Danger),
        ]);
    }

    /**
     * @param  list<TestFinished>  $problems
     * @return list<Block>
     */
    private static function problemBlocks(array $problems): array
    {
        if ($problems === []) {
            return [];
        }

        $blocks = [new Heading(2, [new Text('Problems')])];

        $order = [
            [Outcome::Errored, 'Errors', Tone::Danger],
            [Outcome::Failed, 'Failures', Tone::Danger],
            [Outcome::Risky, 'Risky', Tone::Caution],
            [Outcome::Incomplete, 'Incomplete', Tone::Caution],
            [Outcome::Skipped, 'Skipped', Tone::Muted],
        ];

        foreach ($order as [$outcome, $groupTitle, $tone]) {
            $entries = array_values(array_filter(
                $problems,
                static fn(TestFinished $problem): bool => $problem->outcome === $outcome,
            ));

            if ($entries === []) {
                continue;
            }

            $blocks[] = new Heading(3, [new Text(sprintf('%s (%d)', $groupTitle, count($entries)))]);
            $blocks[] = new ProblemList(array_map(
                static fn(TestFinished $problem): ProblemEntry => new ProblemEntry(
                    $problem->test->toString(),
                    $problem->outcome->value,
                    $problem->failure->message ?? $problem->reason,
                    $problem->quarantined,
                ),
                $entries,
            ), $tone);
        }

        return $blocks;
    }

    /**
     * @param  array<string, list<TestFinished>>  $untestedByReason
     * @return list<Block>
     */
    private static function untestedBlocks(array $untestedByReason): array
    {
        if ($untestedByReason === []) {
            return [];
        }

        $blocks   = [];
        $blocks[] = new Heading(2, [new Text('Untested')]);
        $blocks[] = new Paragraph([new Text('Tests that could not run — a missing requirement or dependency.')]);

        foreach ($untestedByReason as $reason => $events) {
            $blocks[] = new Paragraph([new Strong($reason)]);
            $blocks[] = new BulletList(array_map(
                static fn(TestFinished $event): array => [new Text(PrettyName::ofTest($event->test))],
                $events,
            ));
        }

        return $blocks;
    }
}
