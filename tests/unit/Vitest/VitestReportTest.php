<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Vitest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Vitest\VitestReport;

/**
 * The Vitest JSON → test:finish translation (D-079): every
 * assertionResult in Vitest's Jest-compatible report becomes one
 * first-class Crucible event, file paths made relative, status mapped to an
 * outcome, ms folded to seconds, failure messages carried. The shape
 * mirrors a real `vitest run --reporter=json` capture.
 */
#[CoversClass(VitestReport::class)]
final class VitestReportTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private function report(): array
    {
        return [
            'numTotalTests' => 3,
            'success'       => false,
            'testResults'   => [
                [
                    'name'             => '/root/js/packages/UI/tests/coupon.test.js',
                    'status'           => 'failed',
                    'assertionResults' => [
                        ['fullName' => 'Coupon applies the code', 'title' => 'applies the code', 'status' => 'passed', 'duration' => 14.4, 'failureMessages' => []],
                        ['fullName' => 'Coupon rejects a bad code', 'title' => 'rejects a bad code', 'status' => 'failed', 'duration' => 2.0, 'failureMessages' => ['AssertionError: expected 1 to be 2']],
                        ['fullName' => 'Coupon handles the empty cart', 'title' => 'handles the empty cart', 'status' => 'todo', 'duration' => null, 'failureMessages' => []],
                    ],
                ],
            ],
        ];
    }

    public function testTranslatesEachAssertionResultToATestFinished(): void
    {
        $events = VitestReport::translate($this->report(), '/root/js');

        $this->assertCount(3, $events);

        // File paths land relative to the project root; the fullName is
        // the test name.
        $this->assertSame('packages/UI/tests/coupon.test.js', $events[0]->test->file);
        $this->assertSame('Coupon applies the code', $events[0]->test->name);
        $this->assertSame(Outcome::Passed, $events[0]->outcome);
        $this->assertEqualsWithDelta(0.0144, $events[0]->duration, 1e-9);
        $this->assertNull($events[0]->failure);
    }

    public function testAFailedTestCarriesTheFailureMessage(): void
    {
        $events = VitestReport::translate($this->report(), '/root/js');

        $this->assertSame(Outcome::Failed, $events[1]->outcome);
        $this->assertNotNull($events[1]->failure);
        $this->assertStringContainsString('expected 1 to be 2', $events[1]->failure->message);
    }

    public function testTodoMapsToIncompleteAndOtherStatusesToSkipped(): void
    {
        $events = VitestReport::translate($this->report(), '/root/js');

        $this->assertSame(Outcome::Incomplete, $events[2]->outcome);
        $this->assertSame(0.0, $events[2]->duration);

        $skipped = VitestReport::translate([
            'testResults' => [[
                'name'             => '/root/js/a.test.js',
                'assertionResults' => [['fullName' => 'a is pending', 'status' => 'pending']],
            ]],
        ], '/root/js');

        $this->assertSame(Outcome::Skipped, $skipped[0]->outcome);
    }

    public function testAFileOutsideTheBaseKeepsItsAbsolutePathSoItRoundTripsThroughRelated(): void
    {
        // A Vitest suite outside the working directory (the base passed
        // by VitestRunner, D-081): the path is not made relative, so
        // `--related` still resolves it when the watch loop re-feeds it.
        $events = VitestReport::translate([
            'testResults' => [[
                'name'             => '/elsewhere/app/foo.test.js',
                'assertionResults' => [['fullName' => 'foo works', 'status' => 'passed']],
            ]],
        ], '/root/js');

        $this->assertSame('/elsewhere/app/foo.test.js', $events[0]->test->file);
    }

    public function testACrashedFileWithNoAssertionsBecomesAFailedEvent(): void
    {
        // Vitest 5.1.1's JSON for a top-level throw: a failed file with a
        // message and no assertionResults. Dropping it would be a silent
        // pass.
        $events = VitestReport::translate([
            'testResults' => [[
                'name'             => '/root/js/tests/crash.test.js',
                'status'           => 'failed',
                'message'          => 'top-level crash',
                'assertionResults' => [],
            ]],
        ], '/root/js');

        $this->assertCount(1, $events);
        $this->assertSame(Outcome::Failed, $events[0]->outcome);
        $this->assertSame('tests/crash.test.js', $events[0]->test->file);
        $this->assertSame('test file', $events[0]->test->name);
        $this->assertNotNull($events[0]->failure);
        $this->assertStringContainsString('top-level crash', $events[0]->failure->message);
    }

    public function testASetupErrorAddsTheFileFailureBesideTheSkippedTest(): void
    {
        // A beforeAll that throws: the file failed, its only assertion was
        // never run and is reported as skipped. Both must fold in.
        $events = VitestReport::translate([
            'testResults' => [[
                'name'             => '/root/js/tests/setup.test.js',
                'status'           => 'failed',
                'message'          => 'setup boom',
                'assertionResults' => [
                    ['fullName' => 'never runs', 'status' => 'skipped'],
                ],
            ]],
        ], '/root/js');

        $this->assertCount(2, $events);
        $this->assertSame(Outcome::Skipped, $events[0]->outcome);
        $this->assertSame(Outcome::Failed, $events[1]->outcome);
        $this->assertSame('test file', $events[1]->test->name);
        $this->assertNotNull($events[1]->failure);
        $this->assertStringContainsString('setup boom', $events[1]->failure->message);
    }

    public function testAFailedAssertionDoesNotAlsoAddAFileFailure(): void
    {
        $events = VitestReport::translate([
            'testResults' => [[
                'name'             => '/root/js/tests/fail.test.js',
                'status'           => 'failed',
                'message'          => 'the file failed',
                'assertionResults' => [
                    ['fullName' => 'fails', 'status' => 'failed', 'failureMessages' => ['expected 1 to be 2']],
                ],
            ]],
        ], '/root/js');

        $this->assertCount(1, $events);
        $this->assertSame(Outcome::Failed, $events[0]->outcome);
        $this->assertSame('fails', $events[0]->test->name);
    }

    public function testMalformedEntriesAreSkippedNotFatal(): void
    {
        $events = VitestReport::translate([
            'testResults' => [
                'not-an-array',
                ['name' => '/root/js/ok.test.js', 'assertionResults' => [
                    'junk',
                    ['status'   => 'passed'],                       // no name → skipped
                    ['fullName' => 'ok runs', 'status' => 'passed'],
                ]],
            ],
        ], '/root/js');

        $this->assertCount(1, $events);
        $this->assertSame('ok runs', $events[0]->test->name);
    }
}
