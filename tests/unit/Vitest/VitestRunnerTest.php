<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Vitest;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\RequiresOperatingSystem;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\NameFilter;
use LucianoPereira\Crucible\Runner\OutcomeLog;
use LucianoPereira\Crucible\Vitest\VitestRunner;
use LucianoPereira\Crucible\Vitest\VitestSuite;

use function array_column;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use function var_export;

use const PHP_BINARY;

/**
 * A name filter reaches a named Vitest suite as Vitest's own
 * `--testNamePattern` (D-125), and the tests it leaves out — which
 * Vitest reports as skipped — are not reported at all. Proven against a
 * stand-in binary that records its argv and answers with a fixed report,
 * so no Node install is needed.
 *
 * Skipped on Windows: the stand-in is a `#!` script, which Windows cannot
 * execute. A real Windows install is a .cmd and runs through VitestRunner
 * unchanged.
 */
#[CoversClass(VitestRunner::class)]
#[CoversClass(VitestSuite::class)]
#[CoversClass(NameFilter::class)]
#[RequiresOperatingSystem('^(?!WIN)')]
final class VitestRunnerTest extends TestCase
{
    /** @var non-empty-string */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crucible-vitest-runner-' . uniqid();

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o777, true);
        }

        $report = [
            'testResults' => [[
                'name'             => $this->directory . '/cart.test.js',
                'assertionResults' => [
                    ['fullName' => 'cart adds an item', 'status' => 'passed'],
                    ['fullName' => 'cart is skipped on purpose', 'status' => 'skipped'],
                    ['fullName' => 'totals', 'status' => 'skipped'],
                ],
            ]],
        ];

        // The stand-in: records argv, writes the report where asked.
        file_put_contents($this->directory . '/vitest', '#!' . PHP_BINARY . "\n<?php\n"
            . 'file_put_contents(__DIR__ . "/argv.json", json_encode(array_slice($argv, 1)));' . "\n"
            . 'foreach ($argv as $arg) { if (str_starts_with($arg, "--outputFile=")) { file_put_contents(substr($arg, 13), ' . var_export(json_encode($report), true) . '); } }' . "\n");
        chmod($this->directory . '/vitest', 0o755);
    }

    /**
     * @return array{list<string>, OutcomeLog}
     */
    private function run(VitestSuite $suite): array
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-09-25T12:00:00+00:00')));
        $emitter->subscribe($log = new OutcomeLog());

        (new VitestRunner($emitter))->run([$suite], new WorkingDirectory($this->directory));

        /** @var list<string> $argv */
        $argv = json_decode((string) file_get_contents($this->directory . '/argv.json'), true);

        return [$argv, $log];
    }

    public function testAnUnfilteredSuiteRunsWholeAndReportsEverySkip(): void
    {
        [$argv, $log] = $this->run(new VitestSuite($this->directory, $this->directory . '/vitest'));

        self::assertSame('run', $argv[0]);
        self::assertStringNotContainsString('--testNamePattern', implode(' ', $argv));
        self::assertCount(2, $log->of(Outcome::Skipped));
    }

    public function testAFilterBecomesTheTestNamePatternAndItsLeftoversAreNotReported(): void
    {
        [$argv, $log] = $this->run((new VitestSuite($this->directory, $this->directory . '/vitest'))->filtered('cart'));

        self::assertContains('--testNamePattern=[cC][aA][rR][tT]', $argv);
        self::assertSame(['cart.test.js::cart adds an item'], array_column($log->of(Outcome::Passed), 'id'));

        // The it.skip the filter names stays skipped; the test the
        // filter left out is gone.
        self::assertSame(['cart.test.js::cart is skipped on purpose'], array_column($log->of(Outcome::Skipped), 'id'));
    }

    public function testASubstringFilterKeepsItsWildcardAndEscapesTheRest(): void
    {
        self::assertSame('[aA]\.[bB].*[cC]', (new NameFilter('a.b*c'))->vitestPattern());
    }

    public function testARegularExpressionFilterPassesItsBodyThrough(): void
    {
        self::assertSame('^cart (adds|removes)', (new NameFilter('/^cart (adds|removes)/i'))->vitestPattern());
        self::assertSame('x+', (new NameFilter('{x+}'))->vitestPattern());
    }
}
