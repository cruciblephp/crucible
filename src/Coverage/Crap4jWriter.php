<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function date;
use function htmlspecialchars;
use function ksort;
use function round;
use function sprintf;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * The Crap4J XML emitter: CRAP (Change Risk Anti-Patterns) per method,
 * the metric that pairs cyclomatic complexity with coverage so a
 * complicated method nobody tests scores worse than either number says
 * alone. Method boundaries and complexity come from {@see SourceAnalysis}.
 */
final readonly class Crap4jWriter
{
    /**
     * @param int<1, max> $threshold the CRAP value at which a method counts as crappy
     */
    public function __construct(private int $threshold = 30) {}

    /**
     * @param non-empty-string $root absolute project root
     */
    public function write(CoverageData $data, string $root): string
    {
        $lines = $data->lines;

        ksort($lines);

        $methods     = '';
        $methodCount = 0;
        $crapCount   = 0;
        $totalCrap   = 0.0;
        $totalLoad   = 0.0;

        foreach ($lines as $file => $values) {
            if ($file === '') {
                continue;
            }

            foreach (SourceAnalysis::of($file)->classes as $qualified => $class) {
                foreach ($class->methods as $method) {
                    $crap     = $method->crap($values);
                    $coverage = $method->coverage($values);

                    $methodCount++;
                    $totalCrap += $crap;

                    if ($crap >= $this->threshold) {
                        $crapCount++;
                        $totalLoad += $method->complexity * (1.0 - $coverage / 100) + $method->complexity / $this->threshold;
                    }

                    $methods .= sprintf(
                        "      <method>\n        <package>%s</package>\n        <className>%s</className>\n        <methodName>%s</methodName>\n        <methodSignature>%s</methodSignature>\n        <fullMethod>%s</fullMethod>\n        <crap>%s</crap>\n        <complexity>%d</complexity>\n        <coverage>%s</coverage>\n        <crapLoad>%d</crapLoad>\n      </method>\n",
                        $this->escape($class->namespace),
                        $this->escape($qualified),
                        $this->escape($method->name),
                        $this->escape($method->signature()),
                        $this->escape($method->signature()),
                        $this->number($crap),
                        $method->complexity,
                        $this->number($coverage),
                        (int) round($crap >= $this->threshold ? $method->complexity * (1.0 - $coverage / 100) + $method->complexity / $this->threshold : 0.0),
                    );
                }
            }
        }

        return sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<crap_result>\n  <project>%s</project>\n  <timestamp>%s</timestamp>\n  <stats>\n    <name>Method Crap Stats</name>\n    <methodCount>%d</methodCount>\n    <crapMethodCount>%d</crapMethodCount>\n    <crapLoad>%d</crapLoad>\n    <totalCrap>%s</totalCrap>\n    <crapMethodPercent>%s</crapMethodPercent>\n  </stats>\n  <methods>\n%s  </methods>\n</crap_result>\n",
            $this->escape($root),
            date('Y-m-d H:i:s'),
            $methodCount,
            $crapCount,
            (int) round($totalLoad),
            $this->number($totalCrap),
            $this->number($methodCount === 0 ? 0.0 : 100 * $crapCount / $methodCount),
            $methods,
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }

    private function number(float $value): string
    {
        return sprintf('%01.2F', round($value, 2));
    }
}
