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
use JsonSchema\Validator;
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

use function dirname;
use function implode;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function sprintf;

/**
 * The renderer declares `$schema` pointing at SARIF 2.1.0. Nothing was
 * checking that the document it produces satisfies it, which made the
 * declaration a promise rather than a fact — and the consumer that
 * matters, GitHub Code Scanning, rejects an upload that does not
 * validate. A format is not "supported" until something proves it.
 *
 * The XML side already worked this way (OtrSchemaTest against otr.xsd);
 * this is the same check for the one JSON format with a normative
 * schema, and it is where the *other* half of a report is exercised:
 * every outcome the renderer maps a rule for, so the rules table and
 * the results are validated together rather than one shape at a time.
 *
 * Skipped rather than failed when the schema is absent: an oracle that
 * is not installed cannot disagree with anything.
 */
#[CoversClass(SarifRenderer::class)]
final class SarifSchemaTest extends TestCase
{
    public function testAMixedRunValidatesAgainstTheSarifSchema(): void
    {
        $schema = dirname(__DIR__, 5) . '/sarif-schema/sarif-schema-2.1.0.json';

        if (!is_file($schema)) {
            self::markTestSkipped('The SARIF schema is fetched as an oracle (sarif-schema/), which is not present.');
        }

        $sarif = (new SarifRenderer())->render($this->mixedRun(), $this->context());

        $validator = new Validator();
        $document  = json_decode($sarif);

        $validator->validate($document, (object) ['$ref' => 'file://' . $schema]);

        $messages = [];

        foreach ($validator->getErrors() as $error) {
            if (!is_array($error)) {
                continue;
            }

            $property = $error['property'] ?? '';
            $message  = $error['message'] ?? 'unknown violation';

            $messages[] = sprintf(
                '%s: %s',
                is_string($property) && $property !== '' ? $property : '(root)',
                is_string($message) ? $message : 'unknown violation',
            );
        }

        self::assertSame([], $messages, "The SARIF report does not satisfy sarif-schema-2.1.0.json:\n  " . implode("\n  ", $messages));
    }

    /**
     * One run carrying every outcome the renderer has a rule for, so the
     * rules table and the results that cite it are proved together — a
     * result naming a ruleId the driver never declared is exactly the
     * kind of break a single-outcome fixture would miss.
     */
    private function mixedRun(): Document
    {
        $failure = new Failure('Failed asserting that 4 is identical to 5.', trace: [
            new Frame('/project/tests/DemoTest.php', 12, 'assertSame'),
        ]);

        return new Document([FoldingTree::build([
            'tests/DemoTest.php' => [
                new TestFinished(new TestId('tests/DemoTest.php', 'testFails'), Outcome::Failed, 0.002, $failure),
                new TestFinished(new TestId('tests/DemoTest.php', 'testErrors'), Outcome::Errored, 0.001, new Failure('Boom.')),
                new TestFinished(new TestId('tests/DemoTest.php', 'testSkips'), Outcome::Skipped, 0.0, null, 1, 'needs a database'),
                new TestFinished(new TestId('tests/DemoTest.php', 'testIncomplete'), Outcome::Incomplete, 0.0, null, 1, 'not written yet'),
                new TestFinished(new TestId('tests/DemoTest.php', 'testRisky'), Outcome::Risky, 0.003),
                new TestFinished(new TestId('tests/DemoTest.php', 'testPasses'), Outcome::Passed, 0.004),
            ],
        ], 12.5)]);
    }

    private function context(): ReportContext
    {
        return new ReportContext('Test report', 'Author', 'Crucible 0.1.0', new DateTimeImmutable('2026-08-01T10:00:00+00:00'), 12.5);
    }
}
