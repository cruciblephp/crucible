<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * TeamCity service messages, compared against the incumbent's own.
 *
 * Not XML, so probe 22's vocabulary cannot read it, but the same
 * question applies and the same answer shape works: every message is a
 * name and a set of keys, and a consumer recognises the document by
 * those. TeamCity is a real reader — it groups tests into suites, links
 * a failure to its source line, and interleaves parallel flows — and
 * each of those is a key it looks for.
 *
 *     php conformance/probes/24-teamcity/compare.php
 *
 * Values are not compared: durations, paths and flow ids legitimately
 * differ between two runs. The shape is the contract.
 */

/**
 * Messages the incumbent emits and Crucible does not, each with the
 * reason it is not simply a bug.
 */
const ORACLE_ONLY = [];

/**
 * Messages Crucible emits that the incumbent does not.
 */
const CRUCIBLE_ONLY = [];

/**
 * @return list<string> every "message[key,key]" shape in a service-message log
 */
function messages(string $file): array
{
    if (!\is_file($file)) {
        return [];
    }

    $shapes = [];

    foreach (\explode("\n", (string) \file_get_contents($file)) as $line) {
        if (!\str_starts_with($line, '##teamcity[')) {
            continue;
        }

        if (\preg_match('/^##teamcity\[(\w+)/', $line, $name) !== 1) {
            continue;
        }

        // A value runs to the next unescaped quote; TeamCity escapes
        // with a pipe, so |' is a quote inside the value rather than
        // the end of it.
        \preg_match_all("/(\w+)='((?:[^'|]|\|.)*)'/", $line, $pairs);

        $keys = $pairs[1];
        \sort($keys);

        $shapes[$name[1] . ($keys === [] ? '' : '[' . \implode(',', $keys) . ']')] = true;
    }

    $shapes = \array_keys($shapes);
    \sort($shapes);

    return $shapes;
}
