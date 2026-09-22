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
use LucianoPereira\Crucible\Compat\Migration\CrucibleRun;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_filter;
use function array_search;
use function array_slice;
use function file_put_contents;
use function implode;
use function reset;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(CrucibleRun::class)]
final class CrucibleRunTest extends TestCase
{
    /** @var non-empty-string */
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crucible-crucible-run-test-' . uniqid() . '.ndjson';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testTheChildCarriesThePestVocabularyPrelude(): void
    {
        // compat-check needs BOTH engines live, and on a pest project
        // they could not be: pest publishes its globals through
        // Composer's files autoload and PHP has no function_alias(), so
        // Crucible's dialect stood down and the comparison came back
        // empty. The prelude asks Composer not to load pest's two
        // function files, so the child can run the dialect while the
        // real pest is still installed (D-111).
        $command = CrucibleRun::command('/bin/crucible', ['--filter', 'x'], '/tmp/events.ndjson');

        $prelude = array_filter(
            $command,
            static fn(string $part): bool => str_starts_with($part, 'auto_prepend_file='),
        );

        self::assertCount(1, $prelude, 'the child must be given the prelude');

        $flagValue = reset($prelude);
        self::assertIsString($flagValue);
        self::assertStringEndsWith('/Dialect/Pest/vocabulary-prelude.php', $flagValue);
        self::assertFileExists(substr($flagValue, strlen('auto_prepend_file=')));

        // -d must immediately precede it, or php reads it as a script.
        $flag = array_search('-d', $command, true);
        self::assertIsInt($flag);
        self::assertSame($flagValue, $command[$flag + 1]);

        // and the real arguments still survive, in order
        self::assertSame(
            ['/bin/crucible', '--filter', 'x', '--log-events-json', '/tmp/events.ndjson'],
            array_slice($command, $flag + 2),
        );
    }

    public function testKeepsOnlyTestFinishEventsKeyedById(): void
    {
        $this->write([
            '{"event":"run:start"}',
            '{"event":"test:finish","id":"App\\\\FormatterTest::testConstruct","outcome":"pass"}',
            '{"event":"test:finish","id":"App\\\\HandlerTest::testConstruct","outcome":"fail"}',
            '{"event":"run:finish"}',
        ]);

        $outcomes = CrucibleRun::parseEvents($this->file);

        $this->assertSame([
            'App\FormatterTest::testConstruct' => 'pass',
            'App\HandlerTest::testConstruct'   => 'fail',
        ], $outcomes);
    }

    public function testMalformedLinesAreIgnored(): void
    {
        $this->write([
            'not json at all',
            '{"event":"test:finish","id":"App\\\\T::testIt","outcome":"pass"}',
            '{"event":"test:finish","id":"App\\\\T::missingOutcome"}',
            '{"event":"test:finish","outcome":"pass"}',
        ]);

        $outcomes = CrucibleRun::parseEvents($this->file);

        $this->assertSame(['App\T::testIt' => 'pass'], $outcomes);
    }

    public function testNoEventsFileYieldsNoOutcomes(): void
    {
        $this->assertSame([], CrucibleRun::parseEvents($this->file));
    }

    /**
     * @param list<non-empty-string> $lines
     */
    private function write(array $lines): void
    {
        file_put_contents($this->file, implode("\n", $lines) . "\n");
    }
}
