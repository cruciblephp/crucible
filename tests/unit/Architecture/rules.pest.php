<?php

declare(strict_types=1);

/*
 * Crucible's own architecture, stated as rules (D-088). These run in the
 * ordinary suite: if the shape drifts, a test goes red like any other.
 */

\arch('event objects are immutable')
    ->expect('LucianoPereira\Crucible\Event')
    ->ignoring(\LucianoPereira\Crucible\Event\Emitter::class)
    ->toUseStrictTypes();

\arch('the impact tier never reaches the browser tier')
    ->expect('LucianoPereira\Crucible\Impact')
    ->toOnlyUse(
        'LucianoPereira\Crucible\Impact',
        'LucianoPereira\Crucible\Test',
        'LucianoPereira\Crucible\Vitest',
        'LucianoPereira\Crucible\Attributes',
        'LucianoPereira\Crucible\Filesystem',
    );

\arch('the retrigger endpoint stays inside the watch tier')
    ->expect(\LucianoPereira\Crucible\Watch\RetriggerEndpoint::class)
    ->toOnlyBeUsedIn('LucianoPereira\Crucible\Watch');
