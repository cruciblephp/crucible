<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

/**
 * Which grammar's dispatch semantics govern a double — the one state
 * brain, two spec-pinned strategies (D-017's invariant extended by
 * D-060):
 *
 *   - LatestWins (the PHPUnit spec): the newest matching configuration
 *     handles the call; exceeded maxima fail AT CALL TIME.
 *   - FirstDeclared (the Mockery spec, oracle-pinned): declaration
 *     order wins, count exhaustion falls through to the next match,
 *     and every count violation settles at close.
 */
enum DispatchStrategy
{
    case LatestWins;

    case FirstDeclared;
}
