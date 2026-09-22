<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

/**
 * The upstream integration trait's name, satisfied natively (D-066):
 * suites `use MockeryPHPUnitIntegration;` so the host settles their
 * expectations as test FAILURES instead of raw tearDown errors —
 * in Crucible that settlement is built in (verifyTestDoubles, D-060),
 * so the trait needs no behavior; existing under the aliased name is
 * its whole job. The conformance lane pins the shared posture: an
 * unmet expectation is a failed test on both sides.
 */
trait MockeryPHPUnitIntegration {}
