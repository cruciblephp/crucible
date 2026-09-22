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
use LucianoPereira\Crucible\Compat\Migration\PhpUnitEventLog;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The log that answers where JUnit cannot. ✓ Measured against pest
 * 5.1.1: pest passes PHPUnit's event stream through untouched, so a
 * classic class reports its real class, its real method and the dataset
 * in the `#name` form Crucible's ids already use — while the same test
 * in pest's JUnit is a prettified description with no file in it.
 */
#[CoversClass(PhpUnitEventLog::class)]
final class PhpUnitEventLogTest extends TestCase
{
    /** @var non-empty-string */
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crucible-event-log-test-' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testItReadsTheClassBasedRowsAndLeavesPestsOwnToJunit(): void
    {
        // Pest's own tests are self-labelling here, and mangled:
        // "it slugs" arrives as __pest_evaluable_it_slugs, and undoing
        // that is guesswork — was the name "it slugs" or "it_slugs"?
        // JUnit carries their file exactly, so they belong to it. Each
        // log is read only where it is exact.
        $this->write(<<<'LOG'
            Test Prepared (P\Tests\ArithmeticTest::__pest_evaluable_it_slugs)
            Test Passed (P\Tests\ArithmeticTest::__pest_evaluable_it_slugs)
            Test Prepared (Tests\ClassicTest::testPlainAssertion)
            Test Passed (Tests\ClassicTest::testPlainAssertion)
            Test Passed (Tests\ClassicTest::testWithAProvider#one)
            LOG);

        self::assertSame(
            [
                'Tests\ClassicTest::testPlainAssertion'    => 'pass',
                'Tests\ClassicTest::testWithAProvider#one' => 'pass',
            ],
            PhpUnitEventLog::parse($this->file),
        );
    }

    public function testTheVerdictIsByPrecedenceNotByArrival(): void
    {
        // ✓ Measured: PHPUnit emits "Test Passed" and THEN "Test
        // Considered Risky" for the same test, so a reader taking the
        // last line would call a failed-and-risky test risky. Ordering
        // is the log's business; the verdict is not.
        $this->write(<<<'LOG'
            Test Passed (Tests\T::testRisky)
            Test Considered Risky (Tests\T::testRisky)
            Test Considered Risky (Tests\T::testFailed)
            Test Failed (Tests\T::testFailed)
            Test Errored (Tests\T::testErrored)
            Test Skipped (Tests\T::testSkipped)
            LOG);

        self::assertSame(
            [
                'Tests\T::testRisky'   => 'risky',
                'Tests\T::testFailed'  => 'fail',
                'Tests\T::testErrored' => 'error',
                'Tests\T::testSkipped' => 'skip',
            ],
            PhpUnitEventLog::parse($this->file),
        );
    }

    public function testAClassIsPlacedByTheFileThatDeclaresItAndNeverByResemblance(): void
    {
        $placed = PhpUnitEventLog::keyedByFile(
            [
                'Tests\ClassicTest::testPlainAssertion'    => 'pass',
                'Tests\ClassicTest::testWithAProvider#one' => 'fail',
                'Vendor\SomewhereElse::testOrphan'         => 'pass',
            ],
            ['Tests\ClassicTest' => 'tests/ClassicTest.php'],
        );

        // The join lands on Crucible's own id shape, verbatim.
        self::assertSame(
            [
                'tests/ClassicTest.php::testPlainAssertion'    => 'pass',
                'tests/ClassicTest.php::testWithAProvider#one' => 'fail',
            ],
            $placed['outcomes'],
        );

        // A class nothing declares is returned, not guessed at: a
        // fabricated key can collide with a real one.
        self::assertSame(['Vendor\SomewhereElse::testOrphan'], $placed['unlocated']);
    }

    private function write(string $log): void
    {
        file_put_contents($this->file, $log);
    }
}
