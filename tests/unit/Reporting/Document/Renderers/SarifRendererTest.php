<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document\Renderers;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Frame;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\Renderers\SarifRenderer;
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportContext;
use LucianoPereira\Crucible\Test\TestId;

use function array_column;
use function json_decode;

#[CoversClass(SarifRenderer::class)]
final class SarifRendererTest extends TestCase
{
    public function testFailedTestBecomesAnErrorLevelResultAtTheRealCallSite(): void
    {
        $document = $this->document([
            'tests/DemoTest.php' => [
                new TestFinished(
                    new TestId('tests/DemoTest.php', 'testFailsOnPurpose'),
                    Outcome::Failed,
                    0.002,
                    new Failure('Failed asserting that 4 is identical to 5.', trace: [
                        new Frame('/vendor/crucible/src/Assert/Assert.php', 86, 'evaluate'),
                        new Frame('/project/tests/DemoTest.php', 12, 'assertSame'),
                    ]),
                ),
            ],
        ]);

        $decoded = $this->decode((new SarifRenderer())->render($document, $this->context()));

        $this->assertSame('2.1.0', $decoded['version']);

        $result = $decoded['runs'][0]['results'][0];
        $this->assertSame('test-failed', $result['ruleId']);
        $this->assertSame('error', $result['level']);
        $this->assertSame('Failed asserting that 4 is identical to 5.', $result['message']['text']);
        $this->assertSame('tests/DemoTest.php', $result['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        $this->assertSame(12, $result['locations'][0]['physicalLocation']['region']['startLine'] ?? null);
    }

    public function testSkippedTestOmitsARegionWhenThereIsNoTraceFrame(): void
    {
        $document = $this->document([
            'tests/DemoTest.php' => [
                new TestFinished(
                    new TestId('tests/DemoTest.php', 'testSkipsOnPurpose'),
                    Outcome::Skipped,
                    0.001,
                    reason: 'demo skip',
                ),
            ],
        ]);

        $decoded = $this->decode((new SarifRenderer())->render($document, $this->context()));
        $result  = $decoded['runs'][0]['results'][0];

        $this->assertSame('test-skipped', $result['ruleId']);
        $this->assertSame('note', $result['level']);
        $this->assertSame('demo skip', $result['message']['text']);
        $this->assertArrayNotHasKey('region', $result['locations'][0]['physicalLocation']);
    }

    public function testDeclaresBothRulesUpFrontRegardlessOfWhatFired(): void
    {
        $document = $this->document([
            'tests/DemoTest.php' => [
                new TestFinished(new TestId('tests/DemoTest.php', 'testPasses'), Outcome::Passed, 0.001),
            ],
        ]);

        $decoded = $this->decode((new SarifRenderer())->render($document, $this->context()));
        $ruleIds = array_column($decoded['runs'][0]['tool']['driver']['rules'], 'id');

        $this->assertContains('test-failed', $ruleIds);
        $this->assertContains('test-errored', $ruleIds);
        $this->assertContains('test-skipped', $ruleIds);
        $this->assertSame([], $decoded['runs'][0]['results']);
    }

    public function testInvocationCarriesTheRunsRealStartAndEndTime(): void
    {
        $document = $this->document([]);

        $decoded    = $this->decode((new SarifRenderer())->render($document, $this->context()));
        $invocation = $decoded['runs'][0]['invocations'][0];

        $this->assertTrue($invocation['executionSuccessful']);
        $this->assertSame('2026-08-01T10:00:00.000Z', $invocation['startTimeUtc'] ?? null);
        $this->assertSame('2026-08-01T10:00:12.500Z', $invocation['endTimeUtc'] ?? null);
    }

    /**
     * @return array{
     *     version: string,
     *     runs: list<array{
     *         tool: array{driver: array{rules: list<array{id: string}>}},
     *         invocations: list<array{executionSuccessful: bool, startTimeUtc?: string, endTimeUtc?: string}>,
     *         results: list<array{
     *             ruleId: string,
     *             level: string,
     *             message: array{text: string},
     *             locations: list<array{physicalLocation: array{artifactLocation: array{uri: string}, region?: array{startLine: int}}}>,
     *         }>,
     *     }>,
     * }
     */
    private function decode(string $sarif): array
    {
        /**
         * @var array{
         *     version: string,
         *     runs: list<array{
         *         tool: array{driver: array{rules: list<array{id: string}>}},
         *         invocations: list<array{executionSuccessful: bool, startTimeUtc?: string, endTimeUtc?: string}>,
         *         results: list<array{
         *             ruleId: string,
         *             level: string,
         *             message: array{text: string},
         *             locations: list<array{physicalLocation: array{artifactLocation: array{uri: string}, region?: array{startLine: int}}}>,
         *         }>,
         *     }>,
         * } $decoded
         */
        $decoded = json_decode($sarif, true);

        return $decoded;
    }

    /**
     * @param array<non-empty-string, list<TestFinished>> $sections
     */
    private function document(array $sections): Document
    {
        return new Document([FoldingTree::build($sections, 12.5)]);
    }

    private function context(): ReportContext
    {
        return new ReportContext('Test report', 'Author', 'Crucible 0.1.0', new DateTimeImmutable('2026-08-01T10:00:00+00:00'), 12.5);
    }
}
