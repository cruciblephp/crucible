<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The shapes SnapshotIdentityWrapper generates a class for. APPEND
 * ONLY.
 *
 * spatie/phpunit-snapshot-assertions is not a dependency of the engine,
 * and the wrapper returns early when its trait is absent — which is why
 * this site went unexercised. The trait is declared here IF MISSING, so
 * the wrapper's real path can be reached; where the package is
 * installed, the real trait is used untouched.
 */

namespace Spatie\Snapshots {
    if (!\trait_exists(MatchesSnapshots::class)) {
        trait MatchesSnapshots
        {
            protected int $snapshotIncrementor = 0;

            protected function getSnapshotDirectory(): string
            {
                return __DIR__;
            }

            protected function getSnapshotId(?string $id = null): string
            {
                return 'stand-in';
            }
        }
    }
}

namespace LucianoPereira\Crucible\Tests\Fixtures\SnapshotShapes {
    use LucianoPereira\Crucible\Framework\TestCase;
    use Spatie\Snapshots\MatchesSnapshots;

    class Snapshotting extends TestCase
    {
        use MatchesSnapshots;
    }

    /** Ordinary style, and impossible to extend. */
    final class FinalSnapshotting extends TestCase
    {
        use MatchesSnapshots;
    }

    /** Does not use the trait: the wrapper must leave it alone. */
    class NotSnapshotting extends TestCase {}

    /** Inherits the trait from a base — class_uses() on this returns []. */
    class InheritedSnapshotting extends Snapshotting {}

    trait ComposesSnapshots
    {
        use MatchesSnapshots;
    }

    /** Reaches the trait through another trait, not directly. */
    class ComposedSnapshotting extends TestCase
    {
        use ComposesSnapshots;
    }
}
