<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The other half of the report question: not "would a consumer parse
 * this", which probe 22 answers, but "would it read the same numbers".
 *
 * These are different questions and the first does not imply the
 * second. Cobertura once scored 9 of 11 shapes while hardcoding
 * complexity="0" in every class; a Clover document with the right
 * elements and loc="0" renders as a project containing no code. A
 * coverage number decides whether someone's build passes, so a report
 * that is structurally perfect and numerically wrong is worse than one
 * that fails to parse — it is believed.
 *
 *     php -d xdebug.mode=coverage conformance/probes/23-report-values/compare.php
 *
 * Values are compared numerically, so 1 and 1.00 agree: formatting is
 * probe 22's business, not this one's. Timings, paths and timestamps
 * are excluded — they legitimately differ between two runs.
 *
 * Exits non-zero when the divergence differs from what is recorded
 * below, in either direction.
 */

/**
 * Per format, the facts worth agreeing on: a label, and the XPath that
 * reads it from either document.
 */
const FACTS = [
    'clover' => [
        'option'   => '--coverage-clover',
        'coverage' => true,
        'read'     => [
            'loc'               => 'string(/coverage/project/metrics/@loc)',
            'ncloc'             => 'string(/coverage/project/metrics/@ncloc)',
            'classes'           => 'string(/coverage/project/metrics/@classes)',
            'methods'           => 'string(/coverage/project/metrics/@methods)',
            'coveredmethods'    => 'string(/coverage/project/metrics/@coveredmethods)',
            'statements'        => 'string(/coverage/project/metrics/@statements)',
            'coveredstatements' => 'string(/coverage/project/metrics/@coveredstatements)',
            'elements'          => 'string(/coverage/project/metrics/@elements)',
            'coveredelements'   => 'string(/coverage/project/metrics/@coveredelements)',
        ],
    ],
    'cobertura' => [
        'option'   => '--coverage-cobertura',
        'coverage' => true,
        'read'     => [
            'lines-valid'      => 'string(/coverage/@lines-valid)',
            'lines-covered'    => 'string(/coverage/@lines-covered)',
            'branches-valid'   => 'string(/coverage/@branches-valid)',
            'branches-covered' => 'string(/coverage/@branches-covered)',
            'complexity'       => 'string(/coverage/@complexity)',
            'line-rate'        => 'string(/coverage/@line-rate)',
        ],
    ],
    'crap4j' => [
        'option'   => '--coverage-crap4j',
        'coverage' => true,
        'read'     => [
            'methodCount'       => 'string(/crap_result/stats/methodCount)',
            'crapMethodCount'   => 'string(/crap_result/stats/crapMethodCount)',
            'totalCrap'         => 'string(/crap_result/stats/totalCrap)',
            'crapLoad'          => 'string(/crap_result/stats/crapLoad)',
            'crapMethodPercent' => 'string(/crap_result/stats/crapMethodPercent)',
        ],
    ],
    'junit' => [
        'option'   => '--log-junit',
        'coverage' => false,
        'read'     => [
            'tests'      => 'string(/testsuites/testsuite/@tests)',
            'assertions' => 'string(/testsuites/testsuite/@assertions)',
            'failures'   => 'string(/testsuites/testsuite/@failures)',
            'errors'     => 'string(/testsuites/testsuite/@errors)',
            'skipped'    => 'string(/testsuites/testsuite/@skipped)',
        ],
    ],
];

/**
 * Numbers the two engines genuinely disagree about, each with the reason
 * it is a decision rather than a defect.
 *
 * Empty, and it did not start that way. The first run of this probe put
 * eleven entries here, and every one turned out to be a defect wearing a
 * reason — three causes, all in coverage rather than in any writer:
 *
 * - a file's last line was not counted when the file ended in a newline
 * - a method's closing brace was counted as a statement, because xdebug
 *   reports it and nothing filtered it back out
 * - a method's range stopped at its last statement rather than its
 *   brace, so a body that is only a comment reported itself untested
 *
 * and one rule that was simply absent: a test that did not finish —
 * skipped, incomplete, errored — still contributed its hits, where the
 * incumbent counts only tests that ran to a verdict. A failure still
 * counts; it reached its assertion and disagreed with it.
 *
 * Together those made a coverage ratio read 63.6% where the incumbent
 * read 42.9% over the same code, which is the difference between a gate
 * passing and failing. An entry here now would have to argue that a
 * number CI reads may legitimately differ, which is a high bar.
 */
const RECORDED = [];
