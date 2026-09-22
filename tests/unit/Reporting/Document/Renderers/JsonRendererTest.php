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
use LucianoPereira\Crucible\Reporting\Document\Renderers\JsonRenderer;
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportContext;
use LucianoPereira\Crucible\Test\TestId;

use function json_decode;

#[CoversClass(JsonRenderer::class)]
final class JsonRendererTest extends TestCase
{
    public function testSummaryCountsAndProblemsMatchTheRun(): void
    {
        $document = $this->document([
            'tests/DemoTest.php' => [
                new TestFinished(new TestId('tests/DemoTest.php', 'testPasses'), Outcome::Passed, 0.001),
                new TestFinished(
                    new TestId('tests/DemoTest.php', 'testFailsOnPurpose'),
                    Outcome::Failed,
                    0.002,
                    new Failure('Failed asserting that 4 is identical to 5.', trace: [
                        new Frame('/vendor/crucible/src/Assert/Assert.php', 86, 'evaluate'),
                        new Frame('/project/tests/DemoTest.php', 12, 'assertSame'),
                    ]),
                ),
                new TestFinished(
                    new TestId('tests/DemoTest.php', 'testSkipsOnPurpose'),
                    Outcome::Skipped,
                    0.001,
                    reason: 'demo skip',
                ),
            ],
        ]);

        $json = (new JsonRenderer())->render($document, $this->context());

        /**
         * @var array{
         *     tool: string,
         *     summary: array{total: int, passed: int, failed: int, errored: int, skipped: int, incomplete: int, risky: int, duration: float},
         *     problems: list<array{test: string, line: ?int, outcome: string, message: ?string}>,
         * } $decoded
         */
        $decoded = json_decode($json, true);

        $this->assertSame('crucible', $decoded['tool']);
        $this->assertSame(['total' => 3, 'passed' => 1, 'failed' => 1, 'errored' => 0, 'skipped' => 1, 'incomplete' => 0, 'risky' => 0, 'duration' => 12.5], $decoded['summary']);
        $this->assertCount(2, $decoded['problems']);
        $this->assertSame('tests/DemoTest.php::testFailsOnPurpose', $decoded['problems'][0]['test']);
        $this->assertSame(12, $decoded['problems'][0]['line']);
        $this->assertSame('fail', $decoded['problems'][0]['outcome']);
        $this->assertNull($decoded['problems'][1]['line']);
        $this->assertSame('demo skip', $decoded['problems'][1]['message']);
    }

    public function testAllPassingRunHasAnEmptyProblemsList(): void
    {
        $document = $this->document([
            'tests/DemoTest.php' => [
                new TestFinished(new TestId('tests/DemoTest.php', 'testPasses'), Outcome::Passed, 0.001),
            ],
        ]);

        $json = (new JsonRenderer())->render($document, $this->context());

        /**
         * @var array{problems: list<mixed>, summary: array{passed: int, failed: int}} $decoded
         */
        $decoded = json_decode($json, true);

        $this->assertSame([], $decoded['problems']);
        $this->assertSame(1, $decoded['summary']['passed']);
        $this->assertSame(0, $decoded['summary']['failed']);
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
