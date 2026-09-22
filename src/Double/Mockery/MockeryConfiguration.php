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
 * Mockery::getConfiguration() — the two probed toggles (spec §15).
 * Everything else on the upstream configuration surface is a named
 * error until a conformance scenario demands it (never a silent
 * no-op), except the reflection-cache pair, which upstream documents
 * as observable-nothing.
 */
final class MockeryConfiguration
{
    private bool $allowMockingNonExistentMethods = true;

    private ?QuickDefinitionsConfiguration $quickDefinitions = null;

    public function allowMockingNonExistentMethods(bool $allow): void
    {
        $this->allowMockingNonExistentMethods = $allow;
    }

    public function allowsMockingNonExistentMethods(): bool
    {
        return $this->allowMockingNonExistentMethods;
    }

    public function getQuickDefinitions(): QuickDefinitionsConfiguration
    {
        return $this->quickDefinitions ??= new QuickDefinitionsConfiguration();
    }

    public function quickDefinitionsExpectAtLeastOnce(): bool
    {
        return $this->getQuickDefinitions()->definesAtLeastOnce();
    }

    /** Upstream-internal knob with nothing observable: accepted no-op. */
    public function disableReflectionCache(): void {}

    /** Upstream-internal knob with nothing observable: accepted no-op. */
    public function enableReflectionCache(): void {}

    public function setConstantsMap(): never
    {
        throw new MockeryException('setConstantsMap() is alias-mock surface (spec/mockery-api.md §15) — reference tier, built only if free.');
    }

    public function setInternalClassMethodParamMap(): never
    {
        throw new MockeryException('setInternalClassMethodParamMap() is reference-tier surface (spec/mockery-api.md §15) — built only if free.');
    }
}
