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
use function htmlspecialchars;
use function ksort;
use function max;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * The Cobertura XML emitter (D-062) — the schema GitLab/Jenkins/Azure
 * coverage visualizers consume; output-only like Clover and JUnit
 * (the no-XML rule bans XML inputs, not opt-in emitters). One class
 * element per source file, packaged by directory, paths relative to
 * the project root declared in <sources>. Branch data (when the run
 * collected it) lands as line-level condition-coverage — the
 * attribute the visualizers render.
 */
final readonly class CoberturaWriter
{
    /**
     * @param non-empty-string $root absolute project root
     * @param int<0, max>      $timestamp
     */
    public function write(CoverageData $data, string $root, int $timestamp): string
    {
        $prefix   = $root . '/';
        $packages = [];

        $lines = $data->lines;

        ksort($lines);

        $totalCovered    = 0;
        $totalValid      = 0;
        $totalComplexity = 0;
        $branchTotals    = $data->branchTotals();

        foreach ($lines as $file => $values) {
            ksort($values);

            $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
            $package  = dirname($relative);

            // Branches keyed by the line they start on — the shape
            // cobertura's condition-coverage attribute wants.
            $branchesOnLine = [];

            foreach ($data->branches[$file] ?? [] as $branch) {
                $branchesOnLine[$branch['line']]['total'] = ($branchesOnLine[$branch['line']]['total'] ?? 0) + 1;

                if ($branch['hit'] > 0) {
                    $branchesOnLine[$branch['line']]['covered'] = ($branchesOnLine[$branch['line']]['covered'] ?? 0) + 1;
                }
            }

            $covered = 0;
            $valid   = 0;
            $rows    = '';

            foreach ($values as $line => $value) {
                if ($value === -2) {
                    continue;
                }

                $valid++;

                if ($value > 0) {
                    $covered++;
                }

                $condition = $branchesOnLine[$line] ?? null;

                if ($condition !== null) {
                    $conditionCovered = $condition['covered'] ?? 0;

                    $rows .= sprintf(
                        "            <line number=\"%d\" hits=\"%d\" branch=\"true\" condition-coverage=\"%d%% (%d/%d)\"/>\n",
                        $line,
                        max($value, 0),
                        (int) (100 * $conditionCovered / $condition['total']),
                        $conditionCovered,
                        $condition['total'],
                    );
                } else {
                    $rows .= sprintf("            <line number=\"%d\" hits=\"%d\"/>\n", $line, max($value, 0));
                }
            }

            $fileBranches = CoverageData::branchCounts($data->branches[$file] ?? []);

            // The methods a reader drills into, and the complexity it
            // ranks by — an empty <methods/> parses fine and renders as
            // a class nobody can open.
            $methods    = '';
            $complexity = 0;
            $name       = $relative;

            foreach ($file === '' ? [] : SourceAnalysis::of($file)->classes as $qualified => $class) {
                $name = $qualified;

                foreach ($class->methods as $method) {
                    $complexity += $method->complexity;
                    $ownRows    = '';
                    $ownCovered = 0;
                    $ownValid   = 0;

                    for ($line = $method->startLine; $line <= $method->endLine; $line++) {
                        if (!isset($values[$line]) || $values[$line] === -2) {
                            continue;
                        }

                        $ownValid++;

                        if ($values[$line] > 0) {
                            $ownCovered++;
                        }

                        $ownRows .= sprintf("                <line number=\"%d\" hits=\"%d\"/>\n", $line, max($values[$line], 0));
                    }

                    $methods .= sprintf(
                        "            <method name=\"%s\" signature=\"%s\" line-rate=\"%s\" branch-rate=\"%s\" complexity=\"%d\">\n              <lines>\n%s              </lines>\n            </method>\n",
                        htmlspecialchars($method->name, ENT_XML1 | ENT_QUOTES),
                        htmlspecialchars($method->signature(), ENT_XML1 | ENT_QUOTES),
                        $this->rate($ownCovered, $ownValid),
                        $this->rate(0, 0),
                        $method->complexity,
                        $ownRows,
                    );
                }
            }

            $packages[$package] ??= ['classes' => '', 'covered' => 0, 'valid' => 0, 'complexity' => 0, 'branchesCovered' => 0, 'branchesTotal' => 0];
            $packages[$package]['classes'] .= sprintf(
                "        <class name=\"%s\" filename=\"%s\" line-rate=\"%s\" branch-rate=\"%s\" complexity=\"%d\">\n          <methods>\n%s          </methods>\n          <lines>\n%s          </lines>\n        </class>\n",
                htmlspecialchars($name, ENT_XML1 | ENT_QUOTES),
                htmlspecialchars($relative, ENT_XML1 | ENT_QUOTES),
                $this->rate($covered, $valid),
                $this->rate($fileBranches['covered'], $fileBranches['total']),
                $complexity,
                $methods,
                $rows,
            );

            $packages[$package]['covered'] += $covered;
            $packages[$package]['valid'] += $valid;
            $packages[$package]['complexity'] += $complexity;
            $packages[$package]['branchesCovered'] += $fileBranches['covered'];
            $packages[$package]['branchesTotal'] += $fileBranches['total'];

            $totalComplexity += $complexity;
            $totalCovered += $covered;
            $totalValid += $valid;
        }

        ksort($packages);

        $body = '';

        foreach ($packages as $name => $package) {
            $body .= sprintf(
                "    <package name=\"%s\" line-rate=\"%s\" branch-rate=\"%s\" complexity=\"%d\">\n      <classes>\n%s      </classes>\n    </package>\n",
                htmlspecialchars($name, ENT_XML1 | ENT_QUOTES),
                $this->rate($package['covered'], $package['valid']),
                $this->rate($package['branchesCovered'], $package['branchesTotal']),
                $package['complexity'],
                $package['classes'],
            );
        }

        return sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage line-rate=\"%s\" branch-rate=\"%s\" lines-covered=\"%d\" lines-valid=\"%d\" branches-covered=\"%d\" branches-valid=\"%d\" complexity=\"%d\" version=\"crucible\" timestamp=\"%d\">\n  <sources>\n    <source>%s</source>\n  </sources>\n  <packages>\n%s  </packages>\n</coverage>\n",
            $this->rate($totalCovered, $totalValid),
            $this->rate($branchTotals['covered'], $branchTotals['total']),
            $totalCovered,
            $totalValid,
            $branchTotals['covered'],
            $branchTotals['total'],
            $totalComplexity,
            $timestamp,
            htmlspecialchars($root, ENT_XML1 | ENT_QUOTES),
            $body,
        );
    }

    /**
     * @return non-empty-string cobertura rates are 0..1 decimals
     */
    private function rate(int $covered, int $total): string
    {
        return $total > 0 ? sprintf('%.4f', $covered / $total) : '0';
    }
}
