<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Property;

use Random\Randomizer;

use function count;
use function min;

/**
 * The choice stream generators draw from (growth G5, the Hypothesis
 * architecture): every random decision is one recorded non-negative
 * integer. Shrinking never touches values — it edits this recorded
 * sequence and replays it, which is why shrinking survives map() and
 * suchThat() untouched. The invariant that makes shrinking work:
 * a smaller choice always means a simpler value, and an exhausted
 * stream yields 0, the simplest choice of all.
 */
final class ChoiceSource
{
    /** @var list<int> */
    private array $consumed = [];

    private int $position = 0;

    /**
     * @param ?Randomizer     $random fresh draws; null replays only
     * @param array<int, int> $script choices to replay before (or instead of) random
     *                                ones, indexed contiguously from 0
     */
    public function __construct(
        private readonly ?Randomizer $random = null,
        private readonly array $script = [],
    ) {}

    /**
     * One choice in [0, $bound], inclusive. A non-positive bound and
     * negative or out-of-bound replayed choices all clamp — an edited
     * sequence stays valid, it just means something simpler.
     */
    public function draw(int $bound): int
    {
        if ($bound <= 0) {
            $choice = 0;
        } elseif ($this->position < count($this->script)) {
            $scripted = $this->script[$this->position];
            $choice   = $scripted < 0 ? 0 : min($scripted, $bound);
        } elseif ($this->random instanceof Randomizer) {
            $choice = $this->random->getInt(0, $bound);
        } else {
            $choice = 0;
        }

        $this->position++;
        $this->consumed[] = $choice;

        return $choice;
    }

    /**
     * The choices actually used — the shrinkable representation of
     * whatever was generated.
     *
     * @return list<int>
     */
    public function choices(): array
    {
        return $this->consumed;
    }
}
