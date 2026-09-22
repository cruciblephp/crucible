<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan;

/*
 * A file that generates code at runtime from a path nobody argued for.
 * Analysed only by ApprovedEvalRuleTest, through the real phpstan
 * binary — never by the repository's own run, which does not have
 * tests/_fixtures in its paths.
 */

final class UnapprovedEvalFixture
{
    public function run(string $expression): mixed
    {
        return eval('return ' . $expression . ';');
    }
}
