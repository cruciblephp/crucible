<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension\Artifact;

/**
 * The thing a plugin presents for Crucible to test. A plugin never
 * judges: it hands over facts as a typed Artifact, and Crucible tests
 * them against a requirement, owning the outcome and the message.
 *
 * The kinds form a spectrum by information content — an `ExitStatus`
 * (one bit) is the leanest; a `Claim` carries the two operands a check
 * observed; richer kinds still (a `Measurement` value, a `Report` file)
 * land with their own consumers. This is the sealed vocabulary.
 *
 * @phpstan-sealed ExitStatus|Claim
 */
interface Artifact {}
