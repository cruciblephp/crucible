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
 * A checked claim (D-078): the two operands a plugin observed — what it
 * found ($actual) against what it required ($expected) — plus optional
 * human detail for the failure message. Crucible tests identity and owns
 * the verdict, keeping the artifact-model rule that a plugin presents
 * facts and never judges: phpcpd presents `(clones found, 0, the
 * locations)`, and Crucible is the one that tests `3 === 0`.
 */
final readonly class Claim implements Artifact
{
    /**
     * @param ?non-empty-string $detail failure detail printed beneath the reason (e.g. the offending locations)
     */
    public function __construct(
        public mixed $actual,
        public mixed $expected,
        public ?string $detail = null,
    ) {}
}
