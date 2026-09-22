<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan;

use LucianoPereira\Crucible\Generated\GeneratedCode;

/*
 * A sixth generator: it uses the seam exactly as the approved five do,
 * which is the point — nothing about the call is wrong, and the corpus
 * would simply never know it existed. Analysed only by
 * ApprovedGeneratorRuleTest, through the real phpstan binary.
 */

final class UnapprovedGeneratorFixture
{
    public function declareIt(string $name): void
    {
        GeneratedCode::evaluate('class ' . $name . ' {}', 'a class nobody registered');
    }

    /** The same call reached through a variable, which a Name-only rule would miss. */
    public function declareItIndirectly(string $name): void
    {
        $seam = GeneratedCode::class;

        $seam::evaluate('class ' . $name . ' {}', 'a class nobody registered, spelled indirectly');
    }
}
