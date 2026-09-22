<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The two coverage reports a person reads — --coverage-text and
 * --coverage-html — which had no probe because comparing them to the
 * incumbent's *layout* is the wrong question. Those are presentations,
 * and Crucible's is deliberately its own: a per-file list with a total,
 * against the incumbent's Classes/Methods/Lines summary.
 *
 * What is not a presentation choice is the number in them. A developer
 * reads the percentage in the terminal and believes it, and until D-101
 * that number was wrong — 63.6% where the incumbent said 42.9%, in every
 * report at once. Probe 23 compares the machine-readable formats; these
 * two are the ones a human acts on, and nothing checked them.
 *
 * Two properties, both of which would have caught D-101's bug:
 *
 * - every report of one run states the same coverage. Text, HTML and
 *   Clover come out of one CoverageData, so a disagreement means one of
 *   them computes rather than reports.
 * - that figure matches the incumbent's, counted the same way, to within
 *   the rounding each chooses (the incumbent truncates where Crucible
 *   rounds: 42.85% against 42.86% for 3 of 7).
 *
 *     php -d xdebug.mode=coverage conformance/probes/26-human-coverage/compare.php
 */

/** Covered and total executable lines, from the text report each engine writes. */
const READ = [
    'crucible' => '/Total:\s+[\d.]+%\s+\((\d+) of (\d+) executable lines\)/',
    'oracle'   => '/Lines:\s+[\d.]+%\s+\(\s*(\d+)\/\s*(\d+)\)/',
];
