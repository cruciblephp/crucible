<?php

declare(strict_types=1);

namespace CrucibleConformance\StopOn;

use PHPUnit\Framework\TestCase;

/*
 * Run with --stop-on-failure on both runners (options.json). Both
 * must execute exactly [testPassesFirst, testFailsSecond] and stop;
 * if a runner keeps going, testNeverRunsThird appears in its results
 * and the outcome maps drift.
 */
final class StopOnFailureTest extends TestCase
{
    public function testPassesFirst(): void
    {
        $this->assertTrue(true);
    }

    public function testFailsSecond(): void
    {
        $this->assertSame(1, 2);
    }

    public function testNeverRunsThird(): void
    {
        $this->assertTrue(true);
    }
}
