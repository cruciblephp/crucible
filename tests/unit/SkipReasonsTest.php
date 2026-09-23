<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests;

use Drift;
use FilesystemIterator;
use LucianoPereira\Crucible\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function dirname;
use function file_get_contents;
use function implode;
use function preg_match_all;
use function sort;
use function str_ends_with;

/**
 * A skipped test is indistinguishable from a passing one in a summary
 * line, which is exactly why every skip here has to be a *capability*
 * that is missing rather than a test somebody switched off.
 *
 * The suite's own skips are therefore recorded, with what each one
 * needs. The check runs both ways: a new reason fails until it is
 * written down, and a recorded reason that no longer appears fails too,
 * so the list cannot silently outlive the skip it described.
 *
 * Adding an entry is not meant to be hard — it is meant to be a
 * decision, taken once, in the open.
 */
final class SkipReasonsTest extends TestCase
{
    protected function setUp(): void
    {
        // conformance/ is tooling, not autoloaded with the package.
        require_once dirname(__DIR__, 2) . '/conformance/drift.php';
    }

    /**
     * Reason => the capability whose absence justifies it.
     *
     * @var array<string, string>
     */
    private const array RECOGNISED = [
        'No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).'  => 'the Playwright oracle (ORACLES.md)',
        'No Playwright install available.'                                                              => 'the Playwright oracle (ORACLES.md)',
        'No axe-core install available beside Playwright (npm install axe-core in the browser oracle).' => 'axe-core, the second package the browser oracle installs',
        'No Livewire oracle installed — see ORACLES.md.'                                                => 'the Livewire oracle application',
        'No Laravel install available (livewire-oracle missing).'                                       => 'a real laravel/framework, which the engine deliberately does not depend on',
        'The Livewire oracle did not start listening.'                                                  => 'the Livewire oracle, which serves over an ephemeral port',
        'Could not start the Livewire oracle.'                                                          => 'the Livewire oracle, which serves over an ephemeral port',
        'No free port for the Livewire oracle (set CRUCIBLE_LIVEWIRE_PORT to choose one).'              => 'a free TCP port, since the oracle binds one the OS hands out',
        'No phpcpd-next install available — see ORACLES.md.'                                            => 'the phpcpd-next oracle, which the duplication check runs against',
        'phpcpd-next is installed, so its absence cannot be observed.'                                  => 'the phpcpd-next oracle being ABSENT, which is the one thing an install cannot provide',
        'No Inertia oracle built — see ORACLES.md.'                                                     => 'the built Inertia bundle',
        'The OTR schema ships with the oracle reference install (phpunit-main/), which is not present.' => 'the PHPUnit oracle, which carries the OTR schema',
        'The SARIF schema is fetched as an oracle (sarif-schema/), which is not present.'               => 'the OASIS SARIF schema, which is normative and not ours to vendor',
        'xdebug is loaded without coverage mode.'                                                       => 'xdebug in coverage mode (-d xdebug.mode=coverage)',
        'xdebug is in debug mode, so a clean child mode is not available.'                              => 'an xdebug that is not running a debugger — the supervisor leaves an attached step-debugging session alone in the child',
        'phpstan is not installed (require-dev).'                                                       => 'the phpstan dev dependency',
        'the prelude needs pcov or xdebug in the child.'                                                => 'a coverage driver the CHILD can load, since the prelude collects in a process this one only spawns',
        'node is not installed; the shipped producer cannot be executed.'                               => 'a node runtime',
        'the process ignores mode bits, so an unreadable path cannot be observed.'                      => 'a filesystem the process is not exempt from — root reads and writes regardless of mode',
        'the process ignores mode bits, so an unwritable path cannot be observed.'                      => 'a filesystem the process is not exempt from — root reads and writes regardless of mode',
        'the browser could not paint a frame: '                                                         => 'a renderer able to produce a frame — ✓ measured 2026-09-16 as Chromium answering "Unable to capture screenshot" through a LIVE session, not the dead driver this entry used to claim; Page::screenshot() retries it and this is the backstop',
    ];

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testEverySkipNamesAMissingCapability(): void
    {
        $found = [];

        foreach ($this->sources() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/markTestSkipped\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

            foreach ($matches[1] as $reason) {
                $found[$reason] = true;
            }
        }

        $drift = Drift::between(array_keys($found), self::RECOGNISED);

        $this->assertSame(
            [],
            $drift->unrecorded,
            "A skip reason nobody recorded. If it names a missing capability, add it to RECOGNISED with what it needs; if it is a test switched off, that is what this check exists to catch:\n"
            . $drift->report('skip reasons', 'a skip nobody justified'),
        );

        $this->assertSame(
            [],
            $drift->stale,
            "A recorded skip reason no longer appears — prune it, or the record outlives the skip:\n"
            . $drift->report('skip reasons', 'a skip nobody justified'),
        );
    }

    public function testNoSkipHidesItsReasonBehindAVariable(): void
    {
        // A reason built at runtime cannot be reviewed by the check
        // above, so the check would pass while saying nothing.
        $dynamic = [];

        foreach ($this->sources() as $file) {
            if ($file === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($file);

            // Any markTestSkipped( not immediately followed by a
            // single-quoted literal.
            preg_match_all("/markTestSkipped\\(\\s*(?!')./", $source, $matches);

            if ($matches[0] !== []) {
                $dynamic[] = $file;
            }
        }

        $this->assertSame([], $dynamic, "A skip reason that is not a literal cannot be reviewed:\n  " . implode("\n  ", $dynamic));
    }
}
