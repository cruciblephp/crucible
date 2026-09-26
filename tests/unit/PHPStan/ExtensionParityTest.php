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
use LucianoPereira\Crucible\PHPStan\AssertConditions;
use LucianoPereira\Crucible\PHPStan\ExpectationChainReturnTypeExtension;
use LucianoPereira\Crucible\PHPStan\ExpectFunctionReturnTypeExtension;

use function analyseFixtures;
use function dirname;
use function is_file;

/**
 * Probe 30's first check, in the suite (D-128): what the extension tells
 * the analyser after each assertion and matcher, line for line against
 * the record of what phpstan-phpunit and pest-plugin-phpstan tell it.
 * Every difference is either absent or named in divergences.php; the
 * record itself is re-proved against the live incumbents by
 * `php conformance/probes/30-extension-types/compare.php`.
 */
#[CoversClass(AssertConditions::class)]
#[CoversClass(ExpectationChainReturnTypeExtension::class)]
#[CoversClass(ExpectFunctionReturnTypeExtension::class)]
#[Group('phpstan-blackbox')]
final class ExtensionParityTest extends TestCase
{
    public function testTheExtensionAgreesWithTheIncumbentsLineForLine(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }

        require_once $root . '/conformance/probes/30-extension-types/probe.php';

        /** @var array<string, string> $record */
        $record = require $root . '/conformance/probes/30-extension-types/record.php';

        /** @var array<string, array{crucible: string, cause: string}> $divergences */
        $divergences = require $root . '/conformance/probes/30-extension-types/divergences.php';

        $ours        = analyseFixtures('crucible')['types'];
        $unexplained = [];

        foreach ($record as $label => $incumbent) {
            $type = $ours[$label] ?? '(no type)';

            if ($type !== $incumbent && ($divergences[$label]['crucible'] ?? null) !== $type) {
                $unexplained[$label] = ['incumbent' => $incumbent, 'crucible' => $type];
            }
        }

        self::assertSame([], $unexplained);
    }
}
