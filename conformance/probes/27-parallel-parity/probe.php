<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The same fixture run sequentially and in parallel, compared event by
 * event.
 *
 * Every other probe here runs sequentially, and the conformance harness
 * does too, so the worker half — which re-encodes every event as NDJSON,
 * ships it over a pipe and rebuilds it in the supervisor (D-022) — had
 * nothing checking that what comes out the other side is the same run.
 * The cost of that showed: the supervisor emitted run:start without the
 * plan size while the sequential runner had been fixed to carry it, and
 * a parallel run silently reported no test count to anything reading it.
 * Nothing failed, because nothing looked.
 *
 * A test's verdict cannot depend on how many processes ran it. What may
 * legitimately differ is dropped rather than compared:
 *
 * - ordering, so events are keyed by test id rather than position
 * - `duration`, `ts` and `seq`, which are per-run facts
 * - a trace below its first frame, because the frames under a test
 *   genuinely differ between an in-process run and a worker. The first
 *   frame is the throw site — the author's own line — and that must not
 *   move.
 *
 *     php conformance/probes/27-parallel-parity/compare.php
 */

/** Fields that are per-run rather than per-test, and so are not compared. */
const VOLATILE = ['duration', 'ts', 'seq'];
