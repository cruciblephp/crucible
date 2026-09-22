<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function htmlspecialchars;
use function is_dir;
use function is_file;
use function ksort;
use function mkdir;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;

use const ENT_QUOTES;

/**
 * The HTML coverage report (D-062) — Crucible-native presentation, no
 * parity constraint: a self-contained static directory (inline CSS,
 * zero scripts, zero requests) with an index of per-file bars and one
 * annotated source page per file. Line classes follow the driver's
 * value convention; branch analysis, when the run collected it, adds
 * per-line branch badges and the index column.
 */
final readonly class HtmlReport
{
    /**
     * @param bool $withoutClassView the spec's --without-class-view: drop the per-class table
     * @param bool $withoutFileView  the spec's --without-file-view: drop the annotated source pages
     */
    public function __construct(
        private bool $withoutClassView = false,
        private bool $withoutFileView = false,
    ) {}

    private const string STYLE = <<<'CSS'
        body { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; margin: 2rem; color: #1a1a1a; background: #fff; }
        h1 { font-size: 1.2rem; } h1 a { color: inherit; }
        table { border-collapse: collapse; width: 100%; max-width: 72rem; }
        th, td { text-align: left; padding: .35rem .75rem; border-bottom: 1px solid #e5e5e5; font-size: .85rem; }
        th { border-bottom: 2px solid #1a1a1a; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .bar { display: inline-block; width: 8rem; height: .6rem; background: #f3d6d6; vertical-align: baseline; }
        .bar span { display: block; height: 100%; background: #7bc47f; }
        pre { font-size: .8rem; line-height: 1.45; margin: 0; }
        .ln { display: inline-block; width: 3.5rem; text-align: right; padding-right: .75rem; color: #999; user-select: none; }
        .covered { background: #e6f4e6; }
        .missed { background: #fbe3e3; }
        .dead { color: #b0b0b0; }
        .badge { font-size: .7rem; padding: 0 .3rem; border-radius: .3rem; margin-left: .5rem; }
        .badge.full { background: #cdeccd; } .badge.partial { background: #ffe2b8; } .badge.none { background: #f6c6c6; }
        .total { font-weight: 700; }
        CSS;

    /**
     * @param non-empty-string $root      absolute project root
     * @param non-empty-string $directory output directory
     * @param non-empty-string $driver
     */
    public function write(CoverageData $data, string $root, string $directory, string $driver): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $prefix = $root . '/';
        $lines  = $data->lines;

        ksort($lines);

        $rows         = '';
        $classRows    = '';
        $withBranches = $data->branches !== [];

        foreach ($lines as $file => $values) {
            $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

            $covered    = 0;
            $executable = 0;

            foreach ($values as $value) {
                if ($value === -2) {
                    continue;
                }

                $executable++;

                if ($value > 0) {
                    $covered++;
                }
            }

            $branches = CoverageData::branchCounts($data->branches[$file] ?? []);
            $percent  = $executable > 0 ? 100 * $covered / $executable : 0.0;

            if (!$this->withoutFileView) {
                $this->writeFilePage($directory, $relative, $file, $values, $data->branches[$file] ?? []);
            }

            if (!$this->withoutClassView && $file !== '') {
                $classRows .= $this->classRows($file, $relative, $values);
            }

            // Without the file view there is no page to link to, so the
            // name is plain text rather than a link that 404s.
            $escaped = htmlspecialchars($relative, ENT_QUOTES);
            $label   = $this->withoutFileView
                ? $escaped
                : sprintf('<a href="files/%s.html">%s</a>', $escaped, $escaped);

            $rows .= sprintf(
                "<tr><td>%s</td><td><span class=\"bar\"><span style=\"width:%.0f%%\"></span></span></td><td class=\"num\">%.2f%%</td><td class=\"num\">%d/%d</td>%s</tr>\n",
                $label,
                $percent,
                $percent,
                $covered,
                $executable,
                $withBranches
                    ? sprintf('<td class="num">%s</td>', $branches['total'] > 0 ? sprintf('%d/%d', $branches['covered'], $branches['total']) : '—')
                    : '',
            );
        }

        $totals       = $data->totals();
        $branchTotals = $data->branchTotals();
        $totalPercent = $totals['executable'] > 0 ? 100 * $totals['covered'] / $totals['executable'] : 0.0;

        $index = sprintf(
            "<!DOCTYPE html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><title>Crucible coverage</title><style>%s</style></head>\n<body>\n<h1>Crucible coverage <small>(%s)</small></h1>\n<table>\n<tr><th>File</th><th></th><th>Lines</th><th>Covered</th>%s</tr>\n%s<tr class=\"total\"><td>Total</td><td><span class=\"bar\"><span style=\"width:%.0f%%\"></span></span></td><td class=\"num\">%.2f%%</td><td class=\"num\">%d/%d</td>%s</tr>\n</table>\n</body>\n</html>\n",
            self::STYLE,
            htmlspecialchars($driver, ENT_QUOTES),
            $withBranches ? '<th>Branches</th>' : '',
            $rows,
            $totalPercent,
            $totalPercent,
            $totals['covered'],
            $totals['executable'],
            $withBranches
                ? sprintf('<td class="num">%d/%d</td>', $branchTotals['covered'], $branchTotals['total'])
                : '',
        );

        if ($classRows !== '') {
            $index = str_replace(
                '</body>',
                sprintf(
                    "<h1>By method</h1>\n<table>\n<tr><th>Class</th><th>Method</th><th>Complexity</th><th>CRAP</th><th></th><th>Covered</th><th>File</th></tr>\n%s</table>\n</body>",
                    $classRows,
                ),
                $index,
            );
        }

        file_put_contents($directory . '/index.html', $index);
    }

    /**
     * The per-class table: what a file-level bar cannot show, which is
     * which class inside a covered file is the uncovered one. CRAP sits
     * beside the percentage because a complicated class at 60% is a
     * different problem from a simple one at the same number.
     *
     * @param non-empty-string $file
     * @param array<int, int>  $values
     */
    private function classRows(string $file, string $relative, array $values): string
    {
        $rows = '';

        foreach (SourceAnalysis::of($file)->classes as $qualified => $class) {
            foreach ($class->methods as $method) {
                $coverage = $method->coverage($values);

                $rows .= sprintf(
                    "<tr><td>%s</td><td>%s</td><td class=\"num\">%d</td><td class=\"num\">%.2f</td><td><span class=\"bar\"><span style=\"width:%.0f%%\"></span></span></td><td class=\"num\">%.2f%%</td><td>%s</td></tr>\n",
                    htmlspecialchars($qualified, ENT_QUOTES),
                    htmlspecialchars($method->name, ENT_QUOTES),
                    $method->complexity,
                    $method->crap($values),
                    $coverage,
                    $coverage,
                    htmlspecialchars($relative, ENT_QUOTES),
                );
            }
        }

        return $rows;
    }

    /**
     * One annotated source page, mirrored under files/ so relative
     * links survive any nesting depth.
     *
     * @param non-empty-string                           $directory
     * @param array<int, int>                            $values
     * @param array<string, array{line: int, hit: int}> $branches
     */
    private function writeFilePage(string $directory, string $relative, string $file, array $values, array $branches): void
    {
        $target = $directory . '/files/' . $relative . '.html';
        $parent = dirname($target);

        if (!is_dir($parent)) {
            mkdir($parent, 0o777, true);
        }

        $source = is_file($file) ? file_get_contents($file) : false;

        if ($source === false) {
            // The source moved since collection — an index entry
            // without a page beats a fatal at report time.
            return;
        }

        $branchesOnLine = [];

        foreach ($branches as $branch) {
            $branchesOnLine[$branch['line']]['total'] = ($branchesOnLine[$branch['line']]['total'] ?? 0) + 1;

            if ($branch['hit'] > 0) {
                $branchesOnLine[$branch['line']]['covered'] = ($branchesOnLine[$branch['line']]['covered'] ?? 0) + 1;
            }
        }

        $body = '';

        foreach (explode("\n", $source) as $index => $code) {
            $line  = $index + 1;
            $value = $values[$line] ?? null;

            $class = match (true) {
                $value === null => '',
                $value === -2   => 'dead',
                $value > 0      => 'covered',
                default         => 'missed',
            };

            $badge     = '';
            $condition = $branchesOnLine[$line] ?? null;

            if ($condition !== null) {
                $conditionCovered = $condition['covered'] ?? 0;

                $badge = sprintf(
                    '<span class="badge %s">%d/%d branches</span>',
                    $conditionCovered === $condition['total'] ? 'full' : ($conditionCovered > 0 ? 'partial' : 'none'),
                    $conditionCovered,
                    $condition['total'],
                );
            }

            $body .= sprintf(
                "<span class=\"%s\"><span class=\"ln\">%d</span>%s%s</span>\n",
                $class,
                $line,
                htmlspecialchars($code, ENT_QUOTES),
                $badge,
            );
        }

        // files/ pages link home through their own depth.
        $home = str_repeat('../', substr_count($relative, '/') + 1) . 'index.html';

        file_put_contents($target, sprintf(
            "<!DOCTYPE html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><title>%s — Crucible coverage</title><style>%s</style></head>\n<body>\n<h1><a href=\"%s\">Crucible coverage</a> / %s</h1>\n<pre>%s</pre>\n</body>\n</html>\n",
            htmlspecialchars($relative, ENT_QUOTES),
            self::STYLE,
            $home,
            htmlspecialchars($relative, ENT_QUOTES),
            $body,
        ));
    }
}
