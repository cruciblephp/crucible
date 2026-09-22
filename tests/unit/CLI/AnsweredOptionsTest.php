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
use LucianoPereira\Crucible\CLI\Application;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Configuration\Crucible;
use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_column;
use function array_values;
use function dirname;
use function file_get_contents;
use function glob;
use function ob_get_clean;
use function ob_start;
use function preg_match;

/**
 * Some options are *answered* rather than performed: Crucible accepts
 * them because its own behaviour already satisfies what they ask for,
 * or because the thing they would toggle does not exist here.
 *
 * That is a defensible answer only while it stays true, and each of
 * these claims rests on an absence — no coverage-ignore metadata, no
 * configuration-level test selection, no framework-internal issues.
 * Absences are exactly what rots silently: add the missing concept
 * later and the option becomes a lie that nothing reports.
 *
 * So each claim is pinned here against the thing it depends on. A test
 * failing in this file does not mean the new feature is wrong — it
 * means an option that used to be honestly inert now has something to
 * do, and needs wiring.
 */
#[CoversClass(CliOptions::class)]
final class AnsweredOptionsTest extends TestCase
{
    private function parse(string ...$arguments): CliOptions
    {
        $options = CliOptions::fromArgv(array_values(['crucible', ...$arguments]));

        self::assertInstanceOf(CliOptions::class, $options, 'the parser rejected the option');

        return $options;
    }

    public function testDisableCoverageIgnoreHasNothingToDisable(): void
    {
        // The claim: Crucible honours no coverage-ignore metadata, so
        // its default already *is* the spec's behaviour under this
        // switch. Nothing reads the flag, which is only correct while
        // no such metadata exists.
        $this->assertTrue($this->parse('--disable-coverage-ignore')->disableCoverageIgnore);

        $handling = [];

        $sources = glob(dirname(__DIR__, 3) . '/src/Coverage/*.php');

        foreach ($sources === false ? [] : $sources as $file) {
            if (preg_match('/codeCoverageIgnore/i', (string) file_get_contents($file)) === 1) {
                $handling[] = $file;
            }
        }

        $this->assertSame([], $handling, 'coverage-ignore metadata is honoured now, so --disable-coverage-ignore must actually disable it');

        // The attribute surface says the same thing from the other side.
        $attributes = glob(dirname(__DIR__, 3) . '/src/Attributes/*CoverageIgnore*.php');

        $this->assertSame([], $attributes === false ? [] : $attributes);
    }

    public function testAllHasNoConfigurationLevelSelectionToIgnore(): void
    {
        // The claim: every configured suite runs unless --testsuite or
        // --exclude-testsuite narrows it, and those are command line,
        // not configuration. So there is nothing for --all to ignore.
        $this->assertTrue($this->parse('--all')->all);

        $configuration = Crucible::configure()
            ->testSuite('first', __DIR__)
            ->testSuite('second', __DIR__)
            ->build();

        $this->assertSame(
            ['first', 'second'],
            array_column($configuration->testSuites, 'name'),
            'a configuration that could mark one suite as the default would give --all something to ignore',
        );
    }

    public function testCheckVersionAnswersAndExitsWithoutRunningAnything(): void
    {
        ob_start();
        $exit   = (new Application())->run(['crucible', '--check-version']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no update channel', $output);

        // Answered, not performed: no suite ran on the way past.
        $this->assertStringNotContainsString('Tests:', $output);
    }

    public function testThePhpunitIssueFamilyHasNoIssueToJudge(): void
    {
        // The claim: Crucible raises no framework-internal issues, so
        // every --fail-on-phpunit-* and --display-phpunit-* switch is
        // accepted and inert. What makes that true is the issue
        // vocabulary itself — there is no kind, and no deprecation
        // scope, that means "the framework's own".
        foreach ([
            '--fail-on-phpunit-deprecation',
            '--fail-on-phpunit-notice',
            '--fail-on-phpunit-warning',
            '--do-not-fail-on-phpunit-deprecation',
            '--do-not-fail-on-phpunit-notice',
            '--do-not-fail-on-phpunit-warning',
            '--display-phpunit-deprecations',
            '--display-phpunit-notices',
        ] as $option) {
            $this->parse($option);
        }

        $this->assertSame(
            ['deprecation', 'notice', 'warning'],
            array_column(IssueKind::cases(), 'value'),
            'a new issue kind may be one the phpunit family should judge',
        );

        $this->assertSame(
            ['self', 'direct', 'indirect'],
            array_column(DeprecationScope::cases(), 'value'),
            'a framework-internal scope would give --fail-on-phpunit-deprecation something to judge',
        );
    }
}
