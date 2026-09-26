<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\ApprovedEvalRule;

use function file_get_contents;
use function json_decode;

#[CoversClass(PhpstanNeon::class)]
final class PhpstanNeonTest extends TestCase
{
    private const string INCLUDE_LINE = 'vendor/cruciblephp/crucible/phpstan/extension.neon';

    public function testAFreshFileWiresTheExtensionAndTheProjectPaths(): void
    {
        $neon = PhpstanNeon::create(self::INCLUDE_LINE, ['src', 'tests/unit']);

        self::assertStringContainsString("includes:\n    - " . self::INCLUDE_LINE, $neon);
        self::assertStringContainsString("    paths:\n        - src\n        - tests/unit", $neon);
        self::assertStringContainsString('level: max', $neon);
    }

    public function testWiringInsertsIntoAnExistingIncludesSection(): void
    {
        $existing = <<<'NEON'
            includes:
                - vendor/phpstan/phpstan-strict-rules/rules.neon

            parameters:
                level: 6
            NEON;

        $wired = PhpstanNeon::wire($existing, self::INCLUDE_LINE);

        self::assertNotNull($wired);
        // Inserted directly under the section, with the neighbours' indent.
        self::assertStringContainsString(
            "includes:\n    - " . self::INCLUDE_LINE . "\n    - vendor/phpstan/phpstan-strict-rules/rules.neon",
            $wired,
        );
    }

    public function testWiringPrependsASectionWhenNoneExists(): void
    {
        $existing = "parameters:\n    level: 6\n";

        $wired = PhpstanNeon::wire($existing, self::INCLUDE_LINE);

        self::assertNotNull($wired);
        self::assertStringStartsWith("includes:\n    - " . self::INCLUDE_LINE . "\n\n", $wired);
        self::assertStringContainsString("parameters:\n    level: 6", $wired);
    }

    public function testAlreadyWiredIsDetected(): void
    {
        $existing = "includes:\n    - " . self::INCLUDE_LINE . "\n";

        self::assertTrue(PhpstanNeon::alreadyWired($existing, self::INCLUDE_LINE));
        self::assertFalse(PhpstanNeon::alreadyWired("parameters:\n    level: 6\n", self::INCLUDE_LINE));
    }

    public function testTheInlineListShapeIsRefusedNotMangled(): void
    {
        self::assertNull(PhpstanNeon::wire("includes: [a.neon, b.neon]\n", self::INCLUDE_LINE));
    }

    public function testCommentsAndUnusualIndentationSurvive(): void
    {
        $existing = <<<'NEON'
            # hand-written config, two-space indent
            includes:
              - vendor/phpstan/phpstan-strict-rules/rules.neon
            NEON;

        $wired = PhpstanNeon::wire($existing, self::INCLUDE_LINE);

        self::assertNotNull($wired);
        self::assertStringContainsString('# hand-written config, two-space indent', $wired);
        self::assertStringContainsString("includes:\n  - " . self::INCLUDE_LINE, $wired);
    }

    public function testTheIndentIsTheNextEntrysEvenOnTheFirstLine(): void
    {
        $wired = PhpstanNeon::wire("includes:\n  - a.neon\n", self::INCLUDE_LINE);

        self::assertSame("includes:\n  - " . self::INCLUDE_LINE . "\n  - a.neon\n", $wired);
    }

    public function testAPrependedSectionLeavesTheFileEndingInOneNewline(): void
    {
        $section = "includes:\n    - " . self::INCLUDE_LINE . "\n\n";

        self::assertSame($section, PhpstanNeon::wire('', self::INCLUDE_LINE));
        self::assertSame($section . "parameters:\n    level: 6\n", PhpstanNeon::wire("parameters:\n    level: 6", self::INCLUDE_LINE));
        self::assertSame($section . "parameters:\n    level: 6\n", PhpstanNeon::wire("parameters:\n    level: 6\n", self::INCLUDE_LINE));
    }

    public function testAnAnalysisConfigurationIsWrittenAsGiven(): void
    {
        // What Crucible writes for its own runs (D-137): the includes, each
        // parameter, a list as a neon list, an empty list left out (PHPStan
        // reads `paths:` alone as null), then the rules.
        self::assertSame(
            "includes:\n    - a.neon\n    - b.neon\n\nparameters:\n    level: max\n    paths:\n        - src\n    tmpDir: /tmp/x\n\nrules:\n    - " . ApprovedEvalRule::class . "\n",
            PhpstanNeon::analysis(['a.neon', 'b.neon'], ['level' => 'max', 'paths' => ['src'], 'scanFiles' => [], 'tmpDir' => '/tmp/x'], [ApprovedEvalRule::class]),
        );

        // Nothing to include (the installer loads the extension) and no rules.
        self::assertSame("parameters:\n    level: 5\n", PhpstanNeon::analysis([], ['level' => '5']));
    }

    public function testAFreshFileLeavesTheIncludeToTheInstaller(): void
    {
        // phpstan/extension-installer loads the extension from Crucible's
        // composer.json (D-127); writing the include too would load it
        // twice, and PHPStan refuses a file included twice.
        $neon = PhpstanNeon::create(null, ['src']);

        self::assertStringNotContainsString('includes:', $neon);
        self::assertStringStartsWith('parameters:', $neon);
    }

    public function testCrucibleDeclaresItsExtensionToTheInstaller(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);

        self::assertIsArray($manifest);
        self::assertIsArray($manifest['extra'] ?? null);
        self::assertIsArray($manifest['extra']['phpstan'] ?? null);
        self::assertSame(['phpstan/extension.neon'], $manifest['extra']['phpstan']['includes'] ?? null);
        self::assertFileExists(__DIR__ . '/../../../phpstan/extension.neon');
    }
}
