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
use LucianoPereira\Crucible\PHPStan\ApprovedEvalRule;

use function implode;

/**
 * The eval allowlist, proven through the real phpstan binary.
 *
 * Two claims in one run, because one without the other proves nothing:
 * a file that generates code from an unapproved path is reported, and
 * an approved one is not. A rule that reported everything would pass
 * the first assertion alone.
 */
#[CoversClass(ApprovedEvalRule::class)]
#[Group('phpstan-blackbox')]
final class ApprovedEvalRuleTest extends TestCase
{
    public function testAnUnapprovedEvalIsReportedAndAnApprovedOneIsNot(): void
    {
        $root = Analysis::root();

        $messages = Analysis::rendered(Analysis::run([
            $root . '/tests/_fixtures/phpstan/UnapprovedEvalFixture.php',
            // The one approved site, analysed in the same run: the
            // rule must stay silent about the place that carries a
            // reason, or a rule reporting everything would pass.
            $root . '/src/Generated/GeneratedCode.php',
        ], rules: [ApprovedEvalRule::class]));

        self::assertCount(1, $messages, 'Unexpected analysis output: ' . implode(' | ', $messages));
        self::assertStringStartsWith('crucible.evalNotApproved: ', $messages[0]);
        self::assertStringContainsString('invisible to every gate', $messages[0]);
    }
}
