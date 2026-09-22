<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace CrucibleFixtures\DialectDetection\UsesOnlyWithClass;

use LucianoPereira\Crucible\Framework\TestCase;

/*
 * A pest-shaped file that declares NO test: the only pest vocabulary is
 * uses(), which binds a base class rather than declaring anything, and
 * the tests it binds for are the class's own methods.
 *
 * Measured against spatie/schema-org on Pest 5.1.1, whose
 * tests/AnalysisTest.php has exactly this shape and yields 1,862 cases
 * from a data provider. The incumbent collects the class; Crucible used
 * to stop at the empty pest build and report OK having run none of them.
 */

final class UsesOnlyTest extends TestCase
{
    public function testTheClassIsStillCollected(): void
    {
        self::assertTrue(true);
    }

    public function testEvenThoughTheFileCallsUses(): void
    {
        self::assertTrue(true);
    }
}

uses(UsesOnlyTest::class);
