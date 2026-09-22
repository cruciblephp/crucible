<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Renderers;

use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\Blocks\ProblemList;
use LucianoPereira\Crucible\Reporting\Document\Document;
use UnexpectedValueException;

use function sprintf;

/**
 * Walks a `Document` to TestDox's plain-text form — only `ProblemList`
 * is implemented, since TestDox's distinctive per-file, per-test
 * listing (its whole point: every test's mark, at a glance) is a
 * fundamentally different kind of content than the aggregate Passed
 * section other formats show, and stays hand-written in
 * `TestDoxReporter` rather than forced through a block that doesn't
 * fit it (D-091's addendum). Any other block type is a genuine bug,
 * not silently ignored — the `default` arm throws.
 */
final readonly class TestDoxRenderer
{
    public function render(Document $document): string
    {
        $output = '';

        foreach ($document->blocks as $block) {
            $output .= $this->block($block);
        }

        return $output;
    }

    private function block(Block $block): string
    {
        return match (true) {
            $block instanceof ProblemList => $this->problemList($block),
            default                       => throw new UnexpectedValueException('Unknown block type: ' . $block::class),
        };
    }

    private function problemList(ProblemList $list): string
    {
        $out = '';

        foreach ($list->entries as $index => $entry) {
            $out .= sprintf("%d) %s [%s]\n", $index + 1, $entry->testName, $entry->outcome);

            if ($entry->detail !== null) {
                $out .= $entry->detail . "\n";
            }

            $out .= "\n";
        }

        return $out;
    }
}
