<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

use Attribute;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;

/**
 * A known-flaky test (growth G4): it still runs and its result is
 * still reported, but its failures do not affect the exit code — the
 * suite stays green while the flake is being fixed, without deleting
 * the test or hiding its signal. Quarantined tests that pass are
 * named in the summary as release candidates.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Quarantined implements CrucibleAttribute
{
    /**
     * @param ?non-empty-string $reason why, ideally with an issue reference
     */
    public function __construct(
        public ?string $reason = null,
    ) {}
}
