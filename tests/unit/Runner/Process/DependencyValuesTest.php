<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\Process\DependencyValues;

use function array_keys;

/**
 * The #[Depends] payload's ride across the process boundary: what one
 * unit returns, the unit that depends on it receives.
 */
#[CoversClass(DependencyValues::class)]
final class DependencyValuesTest extends TestCase
{
    public function testOnlyWantedValuesAreCarried(): void
    {
        $values = new DependencyValues(wanted: ['testProducer']);

        $values->capture('testProducer', ['token' => 42]);
        $values->capture('testNobodyDependsOn', 'ignored');

        $this->assertSame(['testProducer'], array_keys($values->provided()));
    }

    public function testAValueRoundTripsIntoTheRunnerResultsMap(): void
    {
        $producer = new DependencyValues(wanted: ['testProducer']);
        $producer->capture('testProducer', ['token' => 42]);

        $consumer = new DependencyValues($producer->provided());

        $this->assertSame(
            ['testProducer' => ['passed' => true, 'value' => ['token' => 42]]],
            $consumer->seed(),
        );
    }

    public function testTheSupervisorCanAskAfterTheRunnerWasBuilt(): void
    {
        $values = new DependencyValues();

        $values->capture('testProducer', 'too early');
        $this->assertSame([], $values->provided());

        $values->want(['testProducer']);
        $values->capture('testProducer', 'now wanted');

        $this->assertSame('now wanted', (new DependencyValues($values->provided()))->seed()['testProducer']['value']);
    }

    public function testAnUnserializableValueIsDroppedRatherThanFatal(): void
    {
        $values = new DependencyValues(wanted: ['testProducer']);

        $values->capture('testProducer', static fn(): int => 1);

        // The dependent then reports untested, exactly as it did before
        // any value crossed the boundary at all.
        $this->assertSame([], $values->provided());
    }

    public function testGarbageFromTheArtifactIsIgnoredNotTrusted(): void
    {
        $this->assertSame([], DependencyValues::fromArray(['' => 'x', 'ok' => 5, 3 => 'y']));
        $this->assertSame(['ok' => 'x'], DependencyValues::fromArray(['ok' => 'x']));
        $this->assertSame([], (new DependencyValues(['name' => 'not base64 serialized !!']))->seed());
    }
}
