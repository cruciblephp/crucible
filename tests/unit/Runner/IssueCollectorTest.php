<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\IssueCollector;

use function dirname;
use function trigger_error;

use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;

#[CoversClass(IssueCollector::class)]
final class IssueCollectorTest extends TestCase
{
    /**
     * @param list<non-empty-string> $baseline
     */
    private function collector(array $baseline = []): IssueCollector
    {
        // This test file's directory counts as project code.
        return new IssueCollector([__DIR__ . '/'], new WorkingDirectory(dirname(__DIR__, 3)), $baseline);
    }

    public function testCapturesAllThreeKindsWithoutDisturbingTheTest(): void
    {
        $collector = $this->collector();
        $collector->install();

        trigger_error('going away', E_USER_DEPRECATED);
        trigger_error('heads up', E_USER_NOTICE);
        trigger_error('watch out', E_USER_WARNING);

        $issues = $collector->drain();

        $this->assertCount(3, $issues);
        $this->assertSame(IssueKind::Deprecation, $issues[0]->kind);
        $this->assertSame(IssueKind::Notice, $issues[1]->kind);
        $this->assertSame(IssueKind::Warning, $issues[2]->kind);
        $this->assertSame('going away', $issues[0]->message);
        $this->assertSame(
            ['deprecations' => 1, 'notices' => 1, 'warnings' => 1],
            $collector->tallies(),
        );
    }

    public function testSilencedErrorsAreNotCounted(): void
    {
        $collector = $this->collector();
        $collector->install();

        @trigger_error('nobody should see this', E_USER_DEPRECATED);

        $this->assertCount(0, $collector->drain());
    }

    public function testDeprecationsTriggeredInProjectCodeAreSelf(): void
    {
        $collector = $this->collector();
        $collector->install();

        trigger_error('own code deprecation', E_USER_DEPRECATED);

        $issues = $collector->drain();

        $this->assertSame(DeprecationScope::Self_, $issues[0]->scope);
    }

    public function testBaselinedDeprecationsAreSuppressedAtCapture(): void
    {
        $file = 'tests/unit/Runner/IssueCollectorTest.php';

        $collector = $this->collector([$file . '|acknowledged legacy']);
        $collector->install();

        trigger_error('acknowledged legacy', E_USER_DEPRECATED);
        trigger_error('brand new problem', E_USER_DEPRECATED);

        $issues = $collector->drain();

        $this->assertCount(1, $issues);
        $this->assertSame('brand new problem', $issues[0]->message);
    }

    public function testDrainResetsPerTestButTalliesAccumulate(): void
    {
        $collector = $this->collector();

        $collector->install();
        trigger_error('first test', E_USER_NOTICE);
        $collector->drain();

        $collector->install();
        trigger_error('second test', E_USER_NOTICE);

        $this->assertCount(1, $collector->drain());
        $this->assertSame(2, $collector->tallies()['notices']);

        $collector->resetTallies();

        $this->assertSame(0, $collector->tallies()['notices']);
    }
}
