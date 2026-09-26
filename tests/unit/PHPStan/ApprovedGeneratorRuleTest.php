<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\ApprovedGeneratorRule;

use function implode;

/**
 * The generator allowlist, proven through the real phpstan binary.
 *
 * Two claims in one run, because one without the other proves nothing:
 * a file reaching the seam from an unapproved path is reported, and an
 * approved generator is not. A rule that reported everything would pass
 * the first assertion alone.
 */
#[CoversClass(ApprovedGeneratorRule::class)]
#[Group('phpstan-blackbox')]
final class ApprovedGeneratorRuleTest extends TestCase
{
    public function testAnUnregisteredGeneratorIsReportedAndARegisteredOneIsNot(): void
    {
        $root = Analysis::root();

        $messages = Analysis::rendered(Analysis::run([
            $root . '/tests/_fixtures/phpstan/UnapprovedGeneratorFixture.php',
            // An approved generator, analysed in the same run: the rule
            // must stay silent about a file that carries a reason, or a
            // rule reporting everything would pass.
            $root . '/src/Double/Generator.php',
        ], rules: [ApprovedGeneratorRule::class]));

        // Both spellings in the fixture: the direct call and the one
        // reached through a variable holding the class name.
        self::assertCount(2, $messages, 'Unexpected analysis output: ' . implode(' | ', $messages));

        foreach ($messages as $message) {
            self::assertStringStartsWith('crucible.generatorNotApproved: ', $message);
            self::assertStringContainsString('never analysed', $message);
        }
    }
}
