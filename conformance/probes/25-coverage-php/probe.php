<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * --coverage-php, which is the one report where comparing against the
 * incumbent is the wrong question.
 *
 * The incumbent's file embeds a serialized
 * SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData — its
 * own object, with its own private properties. Matching that shape
 * would mean writing objects under another library's class names so
 * that library's unserialize() accepts them, which is not compatibility;
 * it is impersonation, and it breaks the day a private property is
 * renamed. Every other format in these probes is an interchange format
 * with outside readers. This one is a library talking to itself.
 *
 * So the probe asserts what can honestly be asserted about Crucible's
 * own file, and both properties are ones a consumer actually relies on:
 *
 * - it is plain data. No classes are loaded to read it, so it survives
 *   being handed to a process that has never heard of Crucible — which
 *   is the whole reason to dump coverage to a file.
 * - it agrees with the other reports of the same run. Two serializations
 *   of one truth that disagree mean one of them is lying, and the value
 *   probe cannot catch it because it never reads this format.
 *
 *     php -d xdebug.mode=coverage conformance/probes/25-coverage-php/compare.php
 */

/** The format identifier the file announces, so a reader can version-check it. */
const FORMAT = 'crucible-coverage-1';

/**
 * The keys the document promises. A consumer reads these by name, so
 * losing one is a breaking change even though nothing fails to parse.
 */
const REQUIRED = [
    'buildInformation.timestamp',
    'buildInformation.runtime.name',
    'buildInformation.runtime.version',
    'buildInformation.crucible.format',
    'buildInformation.crucible.driver',
    'coverage.lines',
    'coverage.tests',
    'coverage.branches',
];
