<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function basename;
use function htmlspecialchars;
use function implode;
use function ksort;
use function max;
use function sprintf;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * The Clover XML emitter (D-041) — CI interop only, string-built like
 * the JUnit writer, output-only (the no-XML rule bans XML inputs;
 * opt-in emitters for CI consumers are the sanctioned exception).
 *
 * The document is grouped the way Clover's consumers read it: packages
 * hold files, files declare their classes, and each method contributes
 * a `type="method"` line beside the statement lines. That structure is
 * the report — a document carrying only statement lines parses fine and
 * renders as a project with no methods and no code, which is how this
 * emitter used to fail: it reported loc="0" for every file it described.
 * The counts come from the same source analysis the XML report's class
 * view uses, so the two reports cannot disagree about a file.
 */
final readonly class CloverWriter
{
    /**
     * @param bool $openClover the spec's --coverage-openclover: the same document in the
     *                         maintained fork's framing — the file's basename beside its
     *                         path, a short class name, a method line carrying its
     *                         signature, and complexity in every metrics block
     */
    public function __construct(private bool $openClover = false) {}

    /**
     * @param int<0, max> $timestamp
     */
    public function write(CoverageData $data, int $timestamp): string
    {
        $lines = $data->lines;

        ksort($lines);

        $packages = [];
        $totals   = $this->zero();

        foreach ($lines as $file => $values) {
            if ($file === '') {
                continue;
            }

            ksort($values);

            $analysis = SourceAnalysis::of($file);
            $package  = '';
            $classes  = '';
            $rows     = '';
            $file_    = $this->zero();

            $file_['loc']     = $analysis->lines['total'];
            $file_['ncloc']   = $analysis->lines['total'] - $analysis->lines['comments'];
            $file_['classes'] = 0;

            // Method lines first in document order is not required; what
            // is required is that every method has one, since a Clover
            // reader counts methods by reading them.
            $methodRows = [];

            foreach ($analysis->classes as $qualified => $class) {
                $package = $package === '' ? $class->namespace : $package;
                $unit    = $this->zero();

                foreach ($class->methods as $method) {
                    $own = $this->counts($values, $method->startLine, $method->endLine);

                    $unit['complexity'] += $method->complexity;
                    $unit['methods']++;

                    // Covered means the body ran, not that the
                    // signature line did — a declaration is not an
                    // executable line, so keying on it reports every
                    // method uncovered while the shapes still match.
                    $covered = $own['executed'] > 0;

                    if ($covered) {
                        $unit['coveredmethods']++;
                    }

                    $unit['statements'] += $own['executable'];
                    $unit['coveredstatements'] += $own['executed'];

                    $methodRows[$method->startLine] = $this->openClover
                        ? sprintf(
                            "        <line num=\"%d\" type=\"method\" complexity=\"%d\" count=\"%d\" signature=\"%s\" visibility=\"%s\"/>\n",
                            $method->startLine,
                            $method->complexity,
                            $covered ? 1 : 0,
                            $this->escape($method->signature()),
                            $this->escape($method->visibility),
                        )
                        : sprintf(
                            "        <line num=\"%d\" type=\"method\" name=\"%s\" visibility=\"%s\" complexity=\"%d\" crap=\"%s\" count=\"%d\"/>\n",
                            $method->startLine,
                            $this->escape($method->name),
                            $this->escape($method->visibility),
                            $method->complexity,
                            $this->number($method->crap($values)),
                            $covered ? 1 : 0,
                        );
                }

                $unit['elements']        = $unit['statements'] + $unit['methods'];
                $unit['coveredelements'] = $unit['coveredstatements'] + $unit['coveredmethods'];

                $classes .= $this->openClover
                    ? sprintf(
                        "        <class name=\"%s\">\n          %s\n        </class>\n",
                        $this->escape($class->name),
                        $this->metrics($unit, ['complexity', 'elements', 'coveredelements', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'methods', 'coveredmethods']),
                    )
                    : sprintf(
                        "        <class name=\"%s\" namespace=\"%s\">\n          %s\n        </class>\n",
                        $this->escape($qualified),
                        $this->escape($class->namespace),
                        $this->metrics($unit, ['complexity', 'methods', 'coveredmethods', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'elements', 'coveredelements']),
                    );

                $file_['classes']++;
                $file_['complexity'] += $unit['complexity'];
                $file_['methods'] += $unit['methods'];
                $file_['coveredmethods'] += $unit['coveredmethods'];
            }

            foreach ($values as $line => $value) {
                if ($value === -2 || isset($methodRows[$line])) {
                    continue;
                }

                $count = max($value, 0);

                $file_['statements']++;

                if ($count > 0) {
                    $file_['coveredstatements']++;
                }

                $rows .= sprintf("        <line num=\"%d\" type=\"stmt\" count=\"%d\"/>\n", $line, $count);
            }

            ksort($methodRows);

            // The conditionals metric is the branch analysis (D-062);
            // without it the counts stay honestly zero.
            $branches                     = CoverageData::branchCounts($data->branches[$file] ?? []);
            $file_['conditionals']        = $branches['total'];
            $file_['coveredconditionals'] = $branches['covered'];
            $file_['elements']            = $file_['statements'] + $file_['methods'] + $branches['total'];
            $file_['coveredelements']     = $file_['coveredstatements'] + $file_['coveredmethods'] + $branches['covered'];

            $escaped    = $this->escape($file);
            $attributes = $this->openClover
                ? sprintf('name="%s" path="%s"', $this->escape(basename($file)), $escaped)
                : sprintf('name="%s"', $escaped);

            $fileMetrics = $this->openClover
                ? $this->metrics($file_, ['loc', 'ncloc', 'classes', 'complexity', 'elements', 'coveredelements', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'methods', 'coveredmethods'])
                : $this->metrics($file_, ['loc', 'ncloc', 'classes', 'methods', 'coveredmethods', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'elements', 'coveredelements']);

            $body = $this->openClover
                ? sprintf("      <file %s>\n        %s\n%s%s%s      </file>\n", $attributes, $fileMetrics, $classes, implode('', $methodRows), $rows)
                : sprintf("      <file %s>\n%s%s%s        %s\n      </file>\n", $attributes, $classes, implode('', $methodRows), $rows, $fileMetrics);

            $packages[$package][] = $body;

            foreach ($file_ as $key => $value) {
                $totals[$key] += $value;
            }

            $totals['files']++;
        }

        $rendered = '';

        foreach ($packages as $name => $bodies) {
            $rendered .= $this->openClover
                ? sprintf(
                    "    <package name=\"%s\">\n      %s\n%s    </package>\n",
                    $this->escape($name),
                    $this->metrics($totals, ['complexity', 'elements', 'coveredelements', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'methods', 'coveredmethods']),
                    implode('', $bodies),
                )
                : sprintf("    <package name=\"%s\">\n%s    </package>\n", $this->escape($name), implode('', $bodies));
        }

        $projectMetrics = $this->openClover
            ? $this->metrics($totals, ['files', 'loc', 'ncloc', 'classes', 'complexity', 'elements', 'coveredelements', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'methods', 'coveredmethods'])
            : $this->metrics($totals, ['files', 'loc', 'ncloc', 'classes', 'methods', 'coveredmethods', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'elements', 'coveredelements']);

        return $this->openClover
            ? sprintf(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage clover=\"3.2.0\" generated=\"%d\">\n  <project timestamp=\"%d\" name=\"OpenClover Coverage\">\n    %s\n%s  </project>\n</coverage>\n",
                $timestamp,
                $timestamp,
                $projectMetrics,
                $rendered,
            )
            : sprintf(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage generated=\"%d\">\n  <project timestamp=\"%d\" name=\"Clover Coverage\">\n%s    %s\n  </project>\n</coverage>\n",
                $timestamp,
                $timestamp,
                $rendered,
                $projectMetrics,
            );
    }

    /**
     * @return array<string, int>
     */
    private function zero(): array
    {
        return [
            'files'      => 0, 'loc' => 0, 'ncloc' => 0, 'classes' => 0, 'complexity' => 0,
            'methods'    => 0, 'coveredmethods' => 0, 'conditionals' => 0, 'coveredconditionals' => 0,
            'statements' => 0, 'coveredstatements' => 0, 'elements' => 0, 'coveredelements' => 0,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @param list<string>       $keys
     */
    private function metrics(array $counts, array $keys): string
    {
        $attributes = '';

        foreach ($keys as $key) {
            $attributes .= sprintf(' %s="%d"', $key, $counts[$key] ?? 0);
        }

        return '<metrics' . $attributes . '/>';
    }

    /**
     * @param array<int, int> $values
     *
     * @return array{executable: int, executed: int}
     */
    private function counts(array $values, int $from, int $to): array
    {
        $executable = 0;
        $executed   = 0;

        for ($line = $from; $line <= $to; $line++) {
            if (!isset($values[$line]) || $values[$line] === -2) {
                continue;
            }

            $executable++;

            if ($values[$line] > 0) {
                $executed++;
            }
        }

        return ['executable' => $executable, 'executed' => $executed];
    }

    private function number(float $value): string
    {
        return sprintf('%.2F', $value);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
