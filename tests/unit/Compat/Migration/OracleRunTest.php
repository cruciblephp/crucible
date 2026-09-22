<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Compat\Migration;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\Migration\OracleRun;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_keys;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Keyed by relative file path, not FQCN: an earlier version of
 * OracleRun assumed Crucible's NDJSON `id` prefix was the fully-
 * qualified class name, matching conformance/run.php's own keying
 * (`method|dataset`, safe there only because every fixture is a
 * single class). Running the real Monolog 3.9.0 benchmark end-to-end
 * showed that assumption was wrong: Crucible identifies a test by its
 * SOURCE FILE, not its class — the earlier keying silently matched
 * nothing at all across an entire real suite (1153/1153 outcomes on
 * each side, 0 detected drift, despite 5 known real failures). Every
 * test here uses a `file` attribute and a working directory, matching
 * what real PHPUnit JUnit output and Crucible's real NDJSON both do.
 */
#[CoversClass(OracleRun::class)]
final class OracleRunTest extends TestCase
{
    private const string WORKDIR = '/project';

    /** @var non-empty-string */
    private string $file;

    /** @var non-empty-string */
    private string $project;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crucible-oracle-run-test-' . uniqid() . '.xml';

        // The pest shape is keyed off a path that must really exist —
        // that check is what separates a pest test's file from pest's
        // description of a classic class — so those cases need a real
        // tree rather than the notional /project the others use.
        $this->project = sys_get_temp_dir() . '/crucible-oracle-run-project-' . uniqid();
        mkdir($this->project . '/tests', 0o777, true);

        foreach (['ArithmeticTest.php', 'DatasetTest.php'] as $test) {
            file_put_contents($this->project . '/tests/' . $test, '<?php');
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        foreach (['ArithmeticTest.php', 'DatasetTest.php'] as $test) {
            @unlink($this->project . '/tests/' . $test);
        }

        if (is_dir($this->project . '/tests')) {
            rmdir($this->project . '/tests');
        }

        if (is_dir($this->project)) {
            rmdir($this->project);
        }
    }

    public function testKeysByRelativeFilePathAndMethodNotMethodAlone(): void
    {
        // Two different classes both declaring testConstruct(): a
        // method-only key (conformance/run.php's own keying, safe only
        // because every fixture there is a single class) would collide.
        $this->write(<<<'XML'
            <?xml version="1.0"?>
            <testsuites>
              <testsuite>
                <testcase name="testConstruct" file="/project/tests/FormatterTest.php" class="App\FormatterTest" classname="App.FormatterTest" />
                <testcase name="testConstruct" file="/project/tests/HandlerTest.php" class="App\HandlerTest" classname="App.HandlerTest" />
              </testsuite>
            </testsuites>
            XML);

        $outcomes = OracleRun::parse($this->file, new WorkingDirectory(self::WORKDIR))['outcomes'];

        $this->assertArrayHasKey('tests/FormatterTest.php::testConstruct', $outcomes);
        $this->assertArrayHasKey('tests/HandlerTest.php::testConstruct', $outcomes);
        $this->assertCount(2, $outcomes);
    }

    public function testOutcomeClassification(): void
    {
        $this->write(<<<'XML'
            <?xml version="1.0"?>
            <testsuites>
              <testsuite>
                <testcase name="testPass" file="/project/tests/T.php" class="App\T" classname="App.T" />
                <testcase name="testFail" file="/project/tests/T.php" class="App\T" classname="App.T"><failure>boom</failure></testcase>
                <testcase name="testError" file="/project/tests/T.php" class="App\T" classname="App.T"><error type="RuntimeException">boom</error></testcase>
                <testcase name="testRisky" file="/project/tests/T.php" class="App\T" classname="App.T"><error type="PHPUnit\Framework\RiskyTestError">no assertions</error></testcase>
                <testcase name="testSkip" file="/project/tests/T.php" class="App\T" classname="App.T"><skipped /></testcase>
              </testsuite>
            </testsuites>
            XML);

        $outcomes = OracleRun::parse($this->file, new WorkingDirectory(self::WORKDIR))['outcomes'];

        $this->assertSame('pass', $outcomes['tests/T.php::testPass']);
        $this->assertSame('fail', $outcomes['tests/T.php::testFail']);
        $this->assertSame('error', $outcomes['tests/T.php::testError']);
        $this->assertSame('risky', $outcomes['tests/T.php::testRisky']);
        $this->assertSame('skip', $outcomes['tests/T.php::testSkip']);
    }

    public function testDatasetSuffixIsAppendedToTheKey(): void
    {
        $this->write(<<<'XML'
            <?xml version="1.0"?>
            <testsuites>
              <testsuite>
                <testcase name="testThing with data set #0" file="/project/tests/T.php" class="App\T" classname="App.T" />
                <testcase name="testThing with data set &quot;named&quot;" file="/project/tests/T.php" class="App\T" classname="App.T" />
              </testsuite>
            </testsuites>
            XML);

        $outcomes = OracleRun::parse($this->file, new WorkingDirectory(self::WORKDIR))['outcomes'];

        $this->assertArrayHasKey('tests/T.php::testThing#0', $outcomes);
        $this->assertArrayHasKey('tests/T.php::testThing#named', $outcomes);
    }

    public function testFileOutsideTheWorkingDirectoryStaysAbsolute(): void
    {
        $this->write(<<<'XML'
            <?xml version="1.0"?>
            <testsuites>
              <testsuite>
                <testcase name="testIt" file="/elsewhere/T.php" class="App\T" classname="App.T" />
              </testsuite>
            </testsuites>
            XML);

        $outcomes = OracleRun::parse($this->file, new WorkingDirectory(self::WORKDIR))['outcomes'];

        $this->assertArrayHasKey('/elsewhere/T.php::testIt', $outcomes);
    }

    public function testPestReportsTheIdItselfInTheFileAttribute(): void
    {
        // ✓ Measured against pest 5.1.1: for a pest-declared test the
        // `file` attribute is "<relative file>::<test name>" and `name`
        // is the bare name — so the attribute already spells Crucible's
        // id, and the pest branch exists to stop key() appending the
        // name a second time.
        $this->write(<<<'XML'
            <?xml version="1.0"?>
            <testsuites>
              <testsuite>
                <testcase name="addition" file="tests/ArithmeticTest.php::addition" class="Tests\ArithmeticTest" classname="Tests.ArithmeticTest" />
                <testcase name="it slugs" file="tests/ArithmeticTest.php::it slugs" class="Tests\ArithmeticTest" classname="Tests.ArithmeticTest" />
              </testsuite>
            </testsuites>
            XML);

        $outcomes = OracleRun::parse($this->file, new WorkingDirectory($this->project))['outcomes'];

        $this->assertSame(
            ['tests/ArithmeticTest.php::addition', 'tests/ArithmeticTest.php::it slugs'],
            array_keys($outcomes),
        );
    }

    public function testAPositionalPestDatasetIsCountedAndANamedOneIsRead(): void
    {
        // Pest labels a positional dataset with its VALUES — "(1)" —
        // where Crucible's id holds the ordinal, which that label never
        // contains. JUnit lists testcases in execution order and the
        // ordinal counts in the same order, so they are counted as they
        // arrive. A named set does carry its name, and is read.
        $this->write(<<<'XML'
            <?xml version="1.0"?>
            <testsuites>
              <testsuite>
                <testcase name="doubles with data set &quot;(1)&quot;" file="tests/DatasetTest.php::doubles with data set &quot;(1)&quot;" class="Tests\DatasetTest" classname="Tests.DatasetTest" />
                <testcase name="doubles with data set &quot;(2)&quot;" file="tests/DatasetTest.php::doubles with data set &quot;(2)&quot;" class="Tests\DatasetTest" classname="Tests.DatasetTest" />
                <testcase name="named sets with data set &quot;dataset &quot;short&quot;&quot;" file="tests/DatasetTest.php::named sets with data set &quot;dataset &quot;short&quot;&quot;" class="Tests\DatasetTest" classname="Tests.DatasetTest" />
              </testsuite>
            </testsuites>
            XML);

        $outcomes = OracleRun::parse($this->file, new WorkingDirectory($this->project))['outcomes'];

        $this->assertSame(
            [
                'tests/DatasetTest.php::doubles#0',
                'tests/DatasetTest.php::doubles#1',
                'tests/DatasetTest.php::named sets#short',
            ],
            array_keys($outcomes),
        );
    }

    public function testAClassicClassInsideAPestRunIsReportedUnmappedNotGuessed(): void
    {
        // ✓ Measured: pest puts its own DESCRIPTION of a classic
        // PHPUnit class where the file goes — "Classic (Tests\Classic)"
        // — and its teamcity locationHint says the same. No file, no
        // id. Such a row leaves the comparison and is named, because
        // quietly dropping it would shrink the suite under comparison
        // without saying so.
        $this->write(<<<'XML'
            <?xml version="1.0"?>
            <testsuites>
              <testsuite>
                <testcase name="addition" file="tests/ArithmeticTest.php::addition" class="Tests\ArithmeticTest" classname="Tests.ArithmeticTest" />
                <testcase name="Plain assertion" file="Classic (Tests\Classic)::Plain assertion" class="Tests\ClassicTest" classname="Tests.ClassicTest" />
              </testsuite>
            </testsuites>
            XML);

        $this->assertSame(
            ['tests/ArithmeticTest.php::addition'],
            array_keys(OracleRun::parse($this->file, new WorkingDirectory($this->project))['outcomes']),
        );
        $this->assertSame(
            ['Plain assertion'],
            OracleRun::parse($this->file, new WorkingDirectory($this->project))['unmapped'],
        );
    }

    public function testTheOracleBinaryIsPestWhereverPestIsInstalled(): void
    {
        // Not a preference: measured, vendor/bin/phpunit cannot run a
        // pest suite at all, so on a pest project it is an empty oracle
        // rather than a narrower one (D-114).
        mkdir($this->project . '/vendor/bin', 0o777, true);
        file_put_contents($this->project . '/vendor/bin/phpunit', '#!/usr/bin/env php');

        $this->assertSame($this->project . '/vendor/bin/phpunit', OracleRun::binaryIn(new WorkingDirectory($this->project)));

        file_put_contents($this->project . '/vendor/bin/pest', '#!/usr/bin/env php');

        $this->assertSame($this->project . '/vendor/bin/pest', OracleRun::binaryIn(new WorkingDirectory($this->project)));

        foreach (['pest', 'phpunit'] as $binary) {
            unlink($this->project . '/vendor/bin/' . $binary);
        }

        $this->assertNull(OracleRun::binaryIn(new WorkingDirectory($this->project)));

        rmdir($this->project . '/vendor/bin');
        rmdir($this->project . '/vendor');
    }

    private function write(string $xml): void
    {
        file_put_contents($this->file, $xml);
    }
}
