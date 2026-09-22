<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Subscriber;

use DateTimeImmutable;
use DOMDocument;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\OtrSubscriber;
use LucianoPereira\Crucible\Test\TestId;

use function dirname;
use function file_get_contents;
use function getmypid;
use function implode;
use function is_file;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function sys_get_temp_dir;
use function trim;
use function unlink;

/**
 * Well-formed is not the same as conformant. Open Test Reporting has a
 * published schema, and the oracle's reference checkout carries a copy,
 * so the one interop format Crucible can be *proved* correct against is
 * proved rather than eyeballed.
 *
 * Skipped rather than failed when that checkout is absent: an oracle
 * that is not installed cannot disagree with anything.
 */
#[CoversClass(OtrSubscriber::class)]
final class OtrSchemaTest extends TestCase
{
    public function testAMixedRunValidatesAgainstTheOpenTestReportingSchema(): void
    {
        $schema = dirname(__DIR__, 4) . '/phpunit-main/src/Logging/OpenTestReporting/schema/otr.xsd';

        if (!is_file($schema)) {
            self::markTestSkipped('The OTR schema ships with the oracle reference install (phpunit-main/), which is not present.');
        }

        $file = sys_get_temp_dir() . '/crucible-otr-schema-' . getmypid() . '.xml';

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
        $emitter->subscribe(new OtrSubscriber($file));

        $emitter->emit(new RunStarted());

        // One of every status the schema has to carry, including the
        // two that write a <reason> and the escaping that needs.
        $cases = [
            ['passes', Outcome::Passed, null, null],
            ['fails', Outcome::Failed, new Failure('Failed asserting that "a" is identical to "b".'), null],
            ['errors', Outcome::Errored, new Failure('Undefined method <script>'), null],
            ['skips', Outcome::Skipped, null, 'needs a database'],
            ['incomplete', Outcome::Incomplete, null, 'later'],
            ['risky', Outcome::Risky, null, 'This test did not perform any assertions.'],
        ];

        foreach ($cases as [$name, $outcome, $failure, $reason]) {
            $id = new TestId('tests/OtrFixture.php', $name);

            $emitter->emit(new TestStarted($id));
            $emitter->emit(new TestFinished($id, $outcome, 0.001, $failure, reason: $reason));
        }

        $emitter->emit(new RunFinished(new RunSummary(passed: 1, failed: 1, errored: 1, skipped: 1, incomplete: 1, risky: 1), 0.01));

        libxml_use_internal_errors(true);

        $document = new DOMDocument();

        self::assertTrue($document->loadXML((string) file_get_contents($file)), 'the OTR log is not well-formed XML');

        $valid = $document->schemaValidate($schema);

        $messages = [];

        foreach (libxml_get_errors() as $error) {
            $messages[] = trim($error->message);
        }

        unlink($file);

        self::assertTrue($valid, "the OTR log does not satisfy otr.xsd:\n  " . implode("\n  ", $messages));
    }
}
