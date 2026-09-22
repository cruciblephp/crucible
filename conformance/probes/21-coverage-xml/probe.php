<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * --coverage-xml has no schema anywhere — neither PHPUnit nor
 * php-code-coverage ships one — so "is this document right" cannot be
 * answered the way the OTR log's can. What can be answered is whether a
 * consumer of the incumbent's XML would recognise Crucible's: run both
 * over the same fixture and compare the *vocabulary*, every element with
 * the attributes it carries.
 *
 * Values are deliberately not compared. Two runs legitimately differ on
 * timings, paths and percentages; the shape is the contract.
 *
 *     php conformance/probes/21-coverage-xml/probe.php
 *
 * Exits non-zero when the divergence differs from what is recorded here
 * — in either direction, so a gap that closes has to be recorded too.
 */

require __DIR__ . '/../20-cli-surface/probe.php';

/**
 * Shapes the incumbent emits and Crucible does not, each with the reason
 * it is not simply a bug. Recorded rather than fixed, and asserted, so
 * the list cannot grow quietly.
 *
 * Empty: the six that used to sit here were closed rather than excused.
 * Five were genuinely missing work — the tokenized <source> dump, and
 * the per-test outcomes in the index, which coverage alone cannot know.
 * The sixth was the build stamp's phpunit attribute, and it is the one
 * that needed a decision rather than code: the document already declares
 * phpunit's schema, so the attribute states which release's format it
 * conforms to. Version::COMPATIBILITY_SERIES, the compatibility
 * statement Crucible already makes, not a claim of lineage.
 */
const ORACLE_ONLY = [];

/**
 * Shapes Crucible emits that the incumbent does not. Extra information
 * is safe for a reader that ignores unknown attributes, which is what
 * every XML consumer does.
 *
 * Empty too, now that a covered line carries its counts on its <covered>
 * children where the incumbent puts them. Crucible still writes a count
 * on a line no test claims, since there are no children to carry it
 * there and the count is then the only hit information the document
 * has — this fixture has no such line, so nothing records it. If one
 * ever appears it will arrive here as unrecorded, which is the check
 * asking for a reason rather than the check being wrong.
 */
const CRUCIBLE_ONLY = [];

/*
 * vocabulary() moved to conformance/vocabulary.php when the other report
 * formats grew probes of their own: five documents asking the same
 * question deserve one implementation of it.
 */

require __DIR__ . '/../../vocabulary.php';
