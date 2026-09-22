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
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\Process\EventParser;
use LucianoPereira\Crucible\Runner\Process\WorkerManifest;
use LucianoPereira\Crucible\Runner\Process\WorkUnit;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_map;
use function json_encode;

#[CoversClass(WorkerManifest::class)]
#[CoversClass(EventParser::class)]
final class WorkerProtocolTest extends TestCase
{
    public function testTestIdRoundTripsThroughItsStringForm(): void
    {
        foreach (['tests/FooTest.php::testA', 'tests/FooTest.php::testB#row one'] as $id) {
            $this->assertSame($id, TestId::fromString($id)?->toString());
        }
    }

    public function testMalformedTestIdStringsParseToNull(): void
    {
        $this->assertNull(TestId::fromString('no-separator'));
        $this->assertNull(TestId::fromString('::nameOnly'));
        $this->assertNull(TestId::fromString('file.php::name#'));
    }

    public function testManifestRoundTripsThroughJson(): void
    {
        $manifest = new WorkerManifest(
            '/project/crucible.php',
            [new WorkUnit('tests/FooTest.php', ['testA', 'testB']), new WorkUnit('tests/BarTest.php')],
            ExecutionOrder::Reversed,
            seed: 42,
        );

        $decoded = WorkerManifest::fromJson($manifest->toJson());

        self::assertInstanceOf(WorkerManifest::class, $decoded, 'The manifest did not survive its JSON round trip.');

        $this->assertSame('/project/crucible.php', $decoded->configuration);
        $this->assertSame(ExecutionOrder::Reversed, $decoded->order);
        $this->assertSame(42, $decoded->seed);
        $this->assertSame(['testA', 'testB'], $decoded->units[0]->tests);
        $this->assertSame('tests/BarTest.php', $decoded->units[1]->file);
        $this->assertNull($decoded->units[1]->tests);
    }

    public function testOverridesSurviveTheManifestWithFalseDistinctFromAbsent(): void
    {
        $manifest = new WorkerManifest(
            '/project/crucible.php',
            [new WorkUnit('tests/FooTest.php')],
            overrides: new Overrides(
                bootstrap: '/project/boot.php',
                diffContext: 0,
                baselineFile: '/project/baseline.json',
                ignoreBaseline: true,
                resolveDependencies: false,
            ),
        );

        $decoded = WorkerManifest::fromJson($manifest->toJson());

        self::assertInstanceOf(WorkerManifest::class, $decoded, 'The manifest did not survive its JSON round trip.');

        $this->assertSame('/project/boot.php', $decoded->overrides->bootstrap);
        $this->assertSame(0, $decoded->overrides->diffContext);
        $this->assertSame('/project/baseline.json', $decoded->overrides->baselineFile);
        $this->assertTrue($decoded->overrides->ignoreBaseline);

        // The whole point of the tri-state: a worker that read `false` as
        // "absent" would run the repair pass its parent skipped.
        $this->assertFalse($decoded->overrides->resolveDependencies);
        $this->assertNull($decoded->overrides->backupGlobals, 'an override nobody set must stay unset');
    }

    public function testGarbageManifestsParseToNull(): void
    {
        $this->assertNull(WorkerManifest::fromJson('not json'));
        $this->assertNull(WorkerManifest::fromJson('{"configuration": ""}'));
        $this->assertNull(WorkerManifest::fromJson('{"configuration": "crucible.php", "order": "default", "seed": 0, "units": [{"file": ""}]}'));
    }

    /**
     * @param non-empty-string  $file
     * @param non-empty-string  $name
     * @param ?non-empty-string $dataset
     */
    private function definition(string $file, string $name, ?string $dataset = null): TestDefinition
    {
        return new TestDefinition(
            new TestId($file, $name, $dataset),
            static fn(array $values): mixed => null,
            MetadataCollection::from(),
        );
    }

    public function testManifestFilterKeepsOnlyNamedTestsAndTheirDatasetRows(): void
    {
        $groups = [
            new TestGroup('Foo', [
                $this->definition('tests/FooTest.php', 'testA'),
                $this->definition('tests/FooTest.php', 'testRows', 'x'),
                $this->definition('tests/FooTest.php', 'testRows', 'y'),
            ]),
            new TestGroup('Bar', [$this->definition('tests/BarTest.php', 'testUnrelated')]),
        ];

        $manifest = new WorkerManifest('/project/crucible.php', [new WorkUnit('tests/FooTest.php', ['testRows'])]);

        $filtered = $manifest->filter($groups);

        $this->assertCount(1, $filtered);
        $this->assertSame(
            ['tests/FooTest.php::testRows#x', 'tests/FooTest.php::testRows#y'],
            array_map(static fn(TestDefinition $test): string => $test->id->toString(), $filtered[0]->tests),
        );
    }

    public function testParserReconstructsAFinishedTestWithItsFailure(): void
    {
        $line = (string) json_encode([
            'ver'      => 1,
            'event'    => 'test:finish',
            'id'       => 'tests/FooTest.php::testBoom',
            'outcome'  => 'fail',
            'duration' => 0.25,
            'error'    => [
                'message' => 'It went boom.',
                'class'   => 'RuntimeException',
                'trace'   => [['file' => '/src/Boom.php', 'line' => 12, 'function' => 'explode']],
                'diff'    => ['expected' => "'calm'", 'actual' => "'boom'"],
            ],
        ]);

        $event = (new EventParser())->parse($line);

        self::assertInstanceOf(TestFinished::class, $event, 'Expected a reconstructed test:finish event.');

        $this->assertSame(Outcome::Failed, $event->outcome);
        $this->assertSame(0.25, $event->duration);

        $failure = $event->failure;

        self::assertInstanceOf(Failure::class, $failure, 'Expected the failure detail to be reconstructed.');

        $this->assertSame('It went boom.', $failure->message);
        $this->assertSame('RuntimeException', $failure->throwableClass);
        $this->assertSame('/src/Boom.php', $failure->trace[0]->file);
        $this->assertSame("'boom'", $failure->actual);
    }

    public function testParserRoundTripsThePruningSignals(): void
    {
        // The D-071 fields must cross the worker boundary intact —
        // garbage entries degrade to omissions, never to a crash.
        $line = (string) json_encode([
            'event'         => 'test:finish',
            'id'            => 'tests/FooTest.php::testClean',
            'outcome'       => 'pass',
            'duration'      => 0.05,
            'propertyClean' => ['tests/FooTest.php::testClean#property 0', '', 7],
            'snapshotKeys'  => ['testClean » #1', 3],
        ]);

        $event = (new EventParser())->parse($line);

        self::assertInstanceOf(TestFinished::class, $event, 'Expected a reconstructed test:finish event.');

        $this->assertSame(['tests/FooTest.php::testClean#property 0'], $event->propertyClean);
        $this->assertSame(['testClean » #1'], $event->snapshotKeys);
    }

    public function testParserHandlesStartRunFinishAndForeignLines(): void
    {
        $parser = new EventParser();

        $started = $parser->parse('{"event":"test:start","id":"tests/FooTest.php::testA"}');
        $this->assertInstanceOf(TestStarted::class, $started);

        $this->assertTrue($parser->isRunFinished('{"event":"run:finish","duration":0.1,"counts":{}}'));
        $this->assertFalse($parser->isRunFinished('{"event":"test:start","id":"x::y"}'));

        // A worker whose stdout was polluted must degrade, not crash.
        $this->assertNull($parser->parse('user code printed this'));
        $this->assertNull($parser->parse('{"event":"run:start"}'));
        $this->assertNull($parser->parse('{"event":"test:finish","id":"tests/FooTest.php::testA","outcome":"not-an-outcome","duration":0.1}'));
    }
}
