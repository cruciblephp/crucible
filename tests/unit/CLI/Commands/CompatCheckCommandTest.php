<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\CLI\Commands;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\Commands\CompatCheckCommand;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_string;
use function json_encode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * `execute()`'s full compare-and-fix flow needs a real phpunit/phpunit
 * install and a real crucible binary as a child process — neither is
 * available inside Crucible's own dev suite (it deliberately does not
 * depend on phpunit/phpunit; see composer.json), so that path is
 * proven end-to-end against the real Monolog benchmark instead. What
 * IS safe and worth covering here: the paths that need neither — the
 * oracle-missing early exit (always true in this repo's own
 * environment), --revert (pure file I/O via FixManifest), and the
 * vacuous-comparison guard, which is a pure function of the two
 * outcome maps precisely so it can be proven without either engine.
 */
#[CoversClass(CompatCheckCommand::class)]
final class CompatCheckCommandTest extends TestCase
{
    /** @var non-empty-string */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crucible-compat-check-cmd-test-' . uniqid();
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . '/a.php', $this->dir . '/.crucible.cache/compat-fixes.json'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->dir . '/.crucible.cache')) {
            rmdir($this->dir . '/.crucible.cache');
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testFailsFastWhenPhpUnitIsNotInstalled(): void
    {
        // Crucible's own dev suite never has phpunit/phpunit installed
        // (composer.json has no such require-dev) — this path is
        // reliably exercised here, unlike the full compare flow.
        $options = $this->parse(['compat-check']);

        ob_start();
        $exit   = (new CompatCheckCommand())->execute($options, ['crucible', 'compat-check'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        // 2, not 1: the run could not be performed, which HELP.md's
        // table separates from a finding about the code. A CI job acts
        // on "fix your configuration" and "the engines disagree"
        // differently, so they must not share an exit code.
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('phpunit/phpunit', $output);
    }

    public function testAnOracleThatRanNothingIsNotAgreement(): void
    {
        // The verdict is spelled with two empty lists, and an oracle
        // that collected nothing hands over the same pair — so the
        // vacuous case and the real one printed the same green. A
        // parity tool may report "they agree" or "nobody asked", never
        // the first when it means the second.
        $vacuous = CompatCheckCommand::nothingToCompare([], ['tests/AT.php::testOne#0' => 'passed']);

        self::assertIsString($vacuous);
        self::assertStringContainsString('no tests at all', $vacuous);
        self::assertStringContainsString('is not "no drift"', $vacuous);
        self::assertStringContainsString('Crucible ran 1', $vacuous);

        // Both sides empty is the same vacuum, not a special case.
        self::assertIsString(CompatCheckCommand::nothingToCompare([], []));

        // And an oracle that ran something is compared, whatever
        // Crucible did with it — the missing-test guard owns that side.
        self::assertNull(CompatCheckCommand::nothingToCompare(['tests/AT.php::testOne#0' => 'passed'], []));
    }

    public function testTheCandidateFilesAreScannedForClassesIncludingPestShapedOnes(): void
    {
        // The set is implied by the two runs: every file Crucible ran,
        // minus every file the oracle keyed. A pest-shaped file is NOT
        // excluded — it was, while recovery meant handing the file to
        // phpunit, and that exclusion is what capped a real suite's
        // comparison at 3.3% (D-117), because a classic class living in
        // a pest-shaped file is exactly the case worth recovering.
        // Reading the run's own event log loads nothing, so the guard
        // guards nothing.
        mkdir($this->dir . '/tests', 0o777, true);
        file_put_contents(
            $this->dir . '/tests/Mixed.php',
            "<?php\n\nnamespace Suite;\n\nclass Mixed extends \\PHPUnit\\Framework\\TestCase {}\n\nuses(Mixed::class);\n",
        );
        file_put_contents($this->dir . '/tests/Keyed.php', "<?php\n\nnamespace Suite;\n\nclass Keyed {}\n");

        $classes = CompatCheckCommand::classesInCandidateFiles(
            ['tests/Keyed.php::testKeyed' => 'pass'],
            [
                'tests/Mixed.php::testB'     => 'pass', // the oracle owes an answer about this one
                'tests/Keyed.php::testKeyed' => 'pass', // already keyed by the junit pass
                'tests/Ghost.php::testC'     => 'pass', // no such file: nothing to scan
            ],
            new WorkingDirectory($this->dir),
        );

        self::assertSame(['Suite\Mixed' => 'tests/Mixed.php'], $classes);

        foreach (['Mixed.php', 'Keyed.php'] as $file) {
            unlink($this->dir . '/tests/' . $file);
        }

        rmdir($this->dir . '/tests');
    }

    public function testRevertRestoresFilesFromAnExistingManifest(): void
    {
        $file = $this->dir . '/a.php';
        file_put_contents($file, 'rewritten content');

        mkdir($this->dir . '/.crucible.cache', 0o777, true);
        file_put_contents(
            $this->dir . '/.crucible.cache/compat-fixes.json',
            (string) json_encode([$file => 'original content']),
        );

        $options = $this->parse(['compat-check', '--revert']);

        ob_start();
        $exit   = (new CompatCheckCommand())->execute($options, ['crucible', 'compat-check', '--revert'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString($file, $output);
        $this->assertSame('original content', file_get_contents($file));
        $this->assertFalse(is_file($this->dir . '/.crucible.cache/compat-fixes.json'));
    }

    public function testRevertWithNothingToRevertIsStillSuccessful(): void
    {
        $options = $this->parse(['compat-check', '--revert']);

        ob_start();
        $exit   = (new CompatCheckCommand())->execute($options, ['crucible', 'compat-check', '--revert'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to revert', $output);
    }

    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): CliOptions
    {
        $options = CliOptions::fromArgv(['crucible', ...$argv]);

        if (is_string($options)) {
            self::fail('Unexpected parse error: ' . $options);
        }

        return $options;
    }
}
