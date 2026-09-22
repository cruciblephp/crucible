<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The five XML report formats other tools actually parse — Clover,
 * OpenClover, Cobertura, Crap4J and JUnit — compared against the
 * incumbent's own writers, shape by shape.
 *
 * These matter more than --coverage-xml, which had the only probe until
 * now: Jenkins and Sonar read Clover, GitLab and Codecov read Cobertura,
 * and every CI on earth reads JUnit. A missing element here is a chart
 * that silently renders empty in someone's pipeline, and the writers'
 * own unit tests cannot catch it — they assert what Crucible emits,
 * which is the question already answered.
 *
 *     php -d xdebug.mode=coverage conformance/probes/22-report-formats/compare.php
 *
 * Exits non-zero when the divergence differs from what is recorded
 * below — in either direction, so a gap that closes has to be recorded
 * too.
 */

require __DIR__ . '/../../vocabulary.php';

/**
 * Per format: the option both engines take, and whether producing it
 * needs coverage collected.
 */
const FORMATS = [
    'clover'     => ['option' => '--coverage-clover',     'coverage' => true],
    'openclover' => ['option' => '--coverage-openclover', 'coverage' => true],
    'cobertura'  => ['option' => '--coverage-cobertura',  'coverage' => true],
    'crap4j'     => ['option' => '--coverage-crap4j',     'coverage' => true],
    'junit'      => ['option' => '--log-junit',           'coverage' => false],
];

/**
 * Shapes the incumbent emits and Crucible does not, per format, each
 * with the reason it is not simply a bug.
 */
const ORACLE_ONLY = [
    'clover'     => [],
    'openclover' => [],
    'cobertura'  => [],
    'crap4j'     => [],
    'junit'      => [],
];

/**
 * Shapes Crucible emits that the incumbent does not. Extra information
 * is safe for a reader that ignores unknown attributes, which is what
 * every XML consumer does.
 */
const CRUCIBLE_ONLY = [
    'clover'     => [],
    'openclover' => [],
    'cobertura'  => [],
    'crap4j'     => [],
    'junit'      => [],
];
