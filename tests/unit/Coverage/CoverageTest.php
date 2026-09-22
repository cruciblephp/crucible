<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Coverage;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
use LucianoPereira\Crucible\Coverage\CloverWriter;
use LucianoPereira\Crucible\Coverage\CoberturaWriter;
use LucianoPereira\Crucible\Coverage\CoverageCollector;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\CoverageDriver;
use LucianoPereira\Crucible\Coverage\CoverageMap;
use LucianoPereira\Crucible\Coverage\CoverageWindow;
use LucianoPereira\Crucible\Coverage\Crap4jWriter;
use LucianoPereira\Crucible\Coverage\DriverFactory;
use LucianoPereira\Crucible\Coverage\HtmlReport;
use LucianoPereira\Crucible\Coverage\PhpWriter;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Coverage\TextReport;
use LucianoPereira\Crucible\Coverage\XmlReport;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Test\TestId;

use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match;
use function rmdir;
use function sha1_file;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(CoverageCollector::class)]
#[CoversClass(CoverageData::class)]
#[CoversClass(CoverageMap::class)]
#[CoversClass(TextReport::class)]
#[CoversClass(PhpWriter::class)]
#[CoversClass(Crap4jWriter::class)]
#[CoversClass(XmlReport::class)]
#[CoversClass(CloverWriter::class)]
#[CoversClass(CoberturaWriter::class)]
#[CoversClass(HtmlReport::class)]
final class CoverageTest extends TestCase
{
    /**
     * A driver that replays scripted collections, one per window.
     *
     * @param list<CoverageWindow> $windows
     */
    private function driver(array $windows): CoverageDriver
    {
        return new class ($windows) implements CoverageDriver {
            private int $window = 0;

            /**
             * @param list<CoverageWindow> $windows
             */
            public function __construct(
                private readonly array $windows,
            ) {}

            public function name(): string
            {
                return 'scripted';
            }

            public function start(): void {}

            public function stop(): CoverageWindow
            {
                return $this->windows[$this->window++] ?? new CoverageWindow();
            }
        };
    }

    public function testTheCollectorFiltersToScopeAndAttributesExecutionOnly(): void
    {
        $collector = new CoverageCollector(
            $this->driver([new CoverageWindow(
                [
                    '/project/src/Math.php'     => [3 => 1, 4 => -1],
                    '/project/src/Loaded.php'   => [7 => -1],           // loaded, never executed
                    '/project/vendor/dep.php'   => [1 => 1],            // out of scope
                    '/project/tests/MyTest.php' => [9 => 1],            // out of scope
                ],
                [
                    '/project/src/Math.php'   => ['sum@0' => ['line' => 3, 'hit' => 1]],
                    '/project/vendor/dep.php' => ['f@0' => ['line' => 1, 'hit' => 1]],  // out of scope
                ],
            )]),
            ['/project/src/'],
        );

        $collector->begin();
        $window = $collector->end();
        $collector->record(new TestId('tests/MyTest.php', 'adds'), $window, $window);

        $data = $collector->data();

        self::assertSame([3 => 1, 4 => -1], $data->lines['/project/src/Math.php'] ?? null);
        self::assertSame([7 => -1], $data->lines['/project/src/Loaded.php'] ?? null);
        $this->assertArrayNotHasKey('/project/vendor/dep.php', $data->lines);

        // Executed files only, with the executed lines per file
        // (D-047) — a loaded file with no run lines is not this
        // test's dependency.
        self::assertSame(['/project/src/Math.php' => [3]], $data->tests['tests/MyTest.php::adds'] ?? null);

        // Branch entries obey the same scope (D-062).
        self::assertSame(['sum@0' => ['line' => 3, 'hit' => 1]], $data->branches['/project/src/Math.php'] ?? null);
        $this->assertArrayNotHasKey('/project/vendor/dep.php', $data->branches);
    }

    public function testBranchesMergeByAnyHitAndRoundTrip(): void
    {
        $first = new CoverageData(branches: ['/p/a.php' => [
            'f@0' => ['line' => 3, 'hit' => 0],
            'f@4' => ['line' => 5, 'hit' => 1],
        ]]);
        $first->merge(new CoverageData(branches: ['/p/a.php' => [
            'f@0' => ['line' => 3, 'hit' => 1],
            'f@6' => ['line' => 8, 'hit' => 0],
        ]]));

        self::assertSame(1, $first->branches['/p/a.php']['f@0']['hit']); // any hit wins
        self::assertSame(['covered' => 2, 'total' => 3], $first->branchTotals());

        $roundTripped = CoverageData::fromJson($first->toJson());

        self::assertSame($first->branches, $roundTripped->branches);
    }

    public function testDataMergesByMaxAndTotals(): void
    {
        $first = new CoverageData(['/p/a.php' => [1 => -1, 2 => 1, 3 => -2]]);
        $first->merge(new CoverageData(['/p/a.php' => [1 => 1, 4 => -1], '/p/b.php' => [1 => 1]]));

        self::assertSame(1, $first->lines['/p/a.php'][1]); // -1 upgraded by execution
        self::assertSame(['covered' => 3, 'executable' => 4], $first->totals()); // the dead line is excluded

        $roundTripped = CoverageData::fromJson($first->toJson());

        self::assertSame($first->lines, $roundTripped->lines);
        self::assertSame($first->tests, $roundTripped->tests);
    }

    public function testTheTextReportRendersRelativePathsAndTotals(): void
    {
        $data = new CoverageData(['/p/src/A.php' => [1 => 1, 2 => -1, 3 => -2]]);

        $report = (new TextReport())->render($data, '/p', 'scripted');

        self::assertStringContainsString('Coverage (scripted):', $report);
        self::assertStringContainsString('50.00%  src/A.php  (1/2)', $report);
        self::assertStringContainsString('Total: 50.00% (1 of 2 executable lines)', $report);
    }

    public function testTheTextReportElidesUncoveredFilesUnlessAsked(): void
    {
        $data = new CoverageData([
            '/p/src/Covered.php' => [1 => 1],
            '/p/src/Never.php'   => [1 => 0, 2 => 0],
        ]);

        $default = (new TextReport())->render($data, '/p', 'scripted');

        self::assertStringContainsString('src/Covered.php', $default);
        $this->assertStringNotContainsString('src/Never.php', $default);
        self::assertStringContainsString('1 file(s) with no covered line omitted', $default);

        $shown = (new TextReport(showUncovered: true))->render($data, '/p', 'scripted');

        self::assertStringContainsString('0.00%  src/Never.php  (0/2)', $shown);
        $this->assertStringNotContainsString('omitted', $shown);

        // Either way the totals count every executable line: eliding a
        // row from a listing must never change the number it sums to.
        foreach ([$default, $shown] as $report) {
            self::assertStringContainsString('Total: 33.33% (1 of 3 executable lines)', $report);
        }
    }

    public function testTheTextReportSummaryDropsTheRowsAndKeepsTheTotals(): void
    {
        $data   = new CoverageData(['/p/src/A.php' => [1 => 1, 2 => 0]]);
        $report = (new TextReport(onlySummary: true))->render($data, '/p', 'scripted');

        $this->assertStringNotContainsString('src/A.php', $report);
        self::assertStringContainsString('Total: 50.00% (1 of 2 executable lines)', $report);
    }

    public function testTheOpenCloverVariantNamesTheFileAndStampsTheRoot(): void
    {
        $data = new CoverageData(['/p/src/A.php' => [1 => 2, 2 => -1]]);

        $clover = (new CloverWriter())->write($data, 1234);
        $open   = (new CloverWriter(openClover: true))->write($data, 1234);

        // Both dialects stamp the root; what separates them is how the
        // file is named and which framing the root carries — Clover
        // names the path, OpenClover the basename beside it.
        self::assertStringContainsString('<file name="/p/src/A.php">', $clover);
        self::assertStringContainsString('<coverage generated="1234">', $clover);
        $this->assertStringNotContainsString('clover=', $clover);

        self::assertStringContainsString('<file name="A.php" path="/p/src/A.php">', $open);
        self::assertStringContainsString('<coverage clover="3.2.0" generated="1234">', $open);

        // Same document otherwise: the metrics a CI gate reads must not
        // depend on which of the two spellings produced the file.
        self::assertStringContainsString('statements="2" coveredstatements="1"', $clover);
        self::assertStringContainsString('statements="2" coveredstatements="1"', $open);
    }

    public function testTheSerializedPhpReportReturnsTheDataItWasGiven(): void
    {
        $data = new CoverageData(['/p/src/A.php' => [1 => 2, 2 => -1]], branches: []);
        $file = sys_get_temp_dir() . '/crucible-coverage-php-' . getmypid() . '.php';

        file_put_contents($file, (new PhpWriter())->write($data, 'scripted'));

        /** @var array{buildInformation: array{crucible: array{driver: string}}, coverage: array{lines: array<string, array<int, int>>}} $loaded */
        $loaded = require $file;
        unlink($file);

        $this->assertSame(['/p/src/A.php' => [1 => 2, 2 => -1]], $loaded['coverage']['lines']);
        $this->assertSame('scripted', $loaded['buildInformation']['crucible']['driver']);
    }

    public function testPathEntriesTravelBesideBranchesAndTallySeparately(): void
    {
        $data = new CoverageData(
            ['/p/src/A.php' => [1 => 1]],
            branches: ['/p/src/A.php' => ['f@0' => ['line' => 1, 'hit' => 1], 'f@1' => ['line' => 2, 'hit' => 0]]],
            paths: ['/p/src/A.php' => ['f#0' => ['line' => 1, 'hit' => 1], 'f#1' => ['line' => 1, 'hit' => 0], 'f#2' => ['line' => 1, 'hit' => 0]]],
        );

        $this->assertSame(['covered' => 1, 'total' => 2], $data->branchTotals());
        $this->assertSame(['covered' => 1, 'total' => 3], $data->pathTotals());

        // The worker artifact is JSON, so paths have to survive it or a
        // --parallel run silently reports fewer paths than it walked.
        $decoded = CoverageData::fromJson($data->toJson());

        $this->assertSame(['covered' => 1, 'total' => 3], $decoded->pathTotals());

        // And any hit wins on merge, exactly as a branch does.
        $other = new CoverageData(paths: ['/p/src/A.php' => ['f#1' => ['line' => 1, 'hit' => 4]]]);
        $decoded->merge($other);

        $this->assertSame(['covered' => 2, 'total' => 3], $decoded->pathTotals());

        $report = (new TextReport())->render($decoded, '/p', 'scripted');

        self::assertStringContainsString('Paths: 66.67% (2 of 3)', $report);
    }

    public function testTheTextReportOmitsPathsWhenNoneWereCollected(): void
    {
        $data = new CoverageData(
            ['/p/src/A.php' => [1 => 1]],
            branches: ['/p/src/A.php' => ['f@0' => ['line' => 1, 'hit' => 1]]],
        );

        $report = (new TextReport())->render($data, '/p', 'scripted');

        self::assertStringContainsString('Branches:', $report);
        $this->assertStringNotContainsString('Paths:', $report);
    }

    public function testTheCrap4jWriterPairsComplexityWithCoverage(): void
    {
        $file = sys_get_temp_dir() . '/crucible-crap-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace N;\nclass C {\n    function m(\$a) {\n        if (\$a) { return 1; }\n        return 2;\n    }\n}\n");

        // Line 5 ran, line 6 did not: half of a complexity-2 method.
        $xml = (new Crap4jWriter())->write(new CoverageData([$file => [5 => 1, 6 => 0]]), '/p');

        unlink($file);

        self::assertStringContainsString('<className>N\\C</className>', $xml);
        self::assertStringContainsString('<methodName>m</methodName>', $xml);
        self::assertStringContainsString('<complexity>2</complexity>', $xml);
        self::assertStringContainsString('<coverage>50.00</coverage>', $xml);

        // 2^2 * (1 - 0.5)^3 + 2 = 2.5
        self::assertStringContainsString('<crap>2.50</crap>', $xml);
        self::assertStringContainsString('<methodCount>1</methodCount>', $xml);
    }

    public function testTheXmlReportWritesAnIndexAndOneDocumentPerFile(): void
    {
        $file = sys_get_temp_dir() . '/crucible-xmlcov-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace N;\nclass C {\n    function m() {\n        return 1;\n    }\n}\n");

        $directory = sys_get_temp_dir() . '/crucible-xmlcov-out-' . getmypid();
        $root      = sys_get_temp_dir();
        $data      = new CoverageData([$file => [5 => 1]], ['tests/T.php::testOne' => [$file => [5]]]);

        self::assertNotSame('', $root);

        (new XmlReport())->write($data, $root, $directory, 'scripted');

        $index = (string) file_get_contents($directory . '/index.xml');

        self::assertStringContainsString('<build time=', $index);
        self::assertStringContainsString('<driver name="scripted"', $index);

        // The loc and unit metrics a consumer of the incumbent's XML
        // reads; probe 21 pins the vocabulary against the real writer.
        self::assertStringContainsString('<lines total=', $index);
        self::assertStringContainsString('<methods count="1"', $index);
        self::assertStringContainsString('<classes count="1"', $index);

        self::assertSame(1, preg_match('/href="([a-f0-9]+\.xml)"/', $index, $match), 'the index must name the file document');

        $document = (string) file_get_contents($directory . '/' . $match[1]);

        self::assertStringContainsString('<class name="N\\C"', $document);
        self::assertStringContainsString('<method name="m"', $document);

        // Which test reached the line, not just that something did.
        self::assertStringContainsString('<covered by="tests/T.php::testOne" count="1"/>', $document);

        // The file's own hash, so a consumer can tell a stale report
        // from a current one — the incumbent's attribute, same value.
        self::assertStringContainsString('hash="' . sha1_file($file) . '"', $document);
        self::assertStringContainsString('<source>', $document);

        // --exclude-source-from-xml-coverage drops exactly that element.
        (new XmlReport(excludeSource: true))->write($data, $root, $directory, 'scripted');
        $this->assertStringNotContainsString('<source>', (string) file_get_contents($directory . '/' . $match[1]));

        $written = glob($directory . '/*.xml');

        foreach ($written === false ? [] : $written as $path) {
            unlink($path);
        }

        rmdir($directory);
        unlink($file);
    }

    public function testTheCloverWriterEmitsStatementMetrics(): void
    {
        $xml = (new CloverWriter())->write(new CoverageData(['/p/src/A.php' => [1 => 2, 2 => -1, 3 => -2]]), 1234);

        // The root and project carry what a Clover reader expects to
        // find there — conformance/probes/22-report-formats compares
        // the whole vocabulary against the incumbent's own writer.
        self::assertStringContainsString('<coverage generated="1234">', $xml);
        self::assertStringContainsString('<project timestamp="1234" name="Clover Coverage">', $xml);
        self::assertStringContainsString('<package name="">', $xml);
        self::assertStringContainsString('<file name="/p/src/A.php">', $xml);
        self::assertStringContainsString('<line num="1" type="stmt" count="2"/>', $xml);
        self::assertStringContainsString('<line num="2" type="stmt" count="0"/>', $xml);
        $this->assertStringNotContainsString('num="3"', $xml); // dead code is not a statement
        self::assertStringContainsString('statements="2" coveredstatements="1"', $xml);
    }

    public function testTheMapDerivesRelativeEdgesAndRoundTrips(): void
    {
        $data = new CoverageData(
            tests: [
                'tests/AlphaTest.php::one' => ['/p/src/A.php' => [3, 4], '/p/src/B.php' => [8]],
                'tests/AlphaTest.php::two' => ['/p/src/A.php' => [3]],
            ],
        );

        $edges = CoverageMap::fromData($data, '/p');

        self::assertSame(['tests/AlphaTest.php' => ['src/A.php', 'src/B.php']], $edges);

        $file = sys_get_temp_dir() . '/crucible-covmap-' . uniqid() . '.json';

        try {
            CoverageMap::save($file, $edges);

            self::assertSame($edges, CoverageMap::load($file));

            file_put_contents($file, 'not json');

            self::assertSame([], CoverageMap::load($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheLineMapAnswersTheMutationQueryAndRoundTrips(): void
    {
        $data = new CoverageData(
            tests: [
                'tests/AlphaTest.php::one' => ['/p/src/A.php' => [3, 4], '/p/src/B.php' => [8]],
                'tests/AlphaTest.php::two' => ['/p/src/A.php' => [3]],
            ],
        );

        $map = TestLineMap::fromData($data, '/p');

        // The mutation query: which tests execute the mutated line?
        self::assertSame(
            ['tests/AlphaTest.php::one', 'tests/AlphaTest.php::two'],
            $map->testsCovering('src/A.php', 3),
        );
        self::assertSame(['tests/AlphaTest.php::one'], $map->testsCovering('src/A.php', 4));
        self::assertSame([], $map->testsCovering('src/A.php', 99));
        self::assertSame([], $map->testsCovering('src/Missing.php', 1));

        $file = sys_get_temp_dir() . '/crucible-covlines-' . uniqid() . '.json';

        try {
            $map->save($file);

            self::assertSame($map->tests, TestLineMap::load($file)->tests);
            self::assertSame(['tests/AlphaTest.php::one'], TestLineMap::load($file)->testsCovering('src/B.php', 8));

            file_put_contents($file, 'not json');

            self::assertSame([], TestLineMap::load($file)->tests);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheTextReportRendersBranchTotalsWhenCollected(): void
    {
        $data = new CoverageData(
            ['/p/src/A.php' => [1 => 1, 2 => -1]],
            branches: ['/p/src/A.php' => [
                'f@0' => ['line' => 1, 'hit' => 1],
                'f@4' => ['line' => 2, 'hit' => 0],
            ]],
        );

        $report = (new TextReport())->render($data, '/p', 'xdebug');

        self::assertStringContainsString('src/A.php  (1/2)  branches 50.00% (1/2)', $report);
        self::assertStringContainsString('Branches: 50.00% (1 of 2)', $report);
    }

    public function testTheCloverWriterFillsConditionalsFromBranches(): void
    {
        $xml = (new CloverWriter())->write(new CoverageData(
            ['/p/src/A.php' => [1 => 1, 2 => -1]],
            branches: ['/p/src/A.php' => [
                'f@0' => ['line' => 1, 'hit' => 1],
                'f@4' => ['line' => 2, 'hit' => 0],
            ]],
        ), 1234);

        self::assertStringContainsString('conditionals="2" coveredconditionals="1"', $xml);
        self::assertStringContainsString('elements="4" coveredelements="2"', $xml);
    }

    public function testTheCoberturaWriterEmitsRatesPackagesAndConditionCoverage(): void
    {
        $xml = (new CoberturaWriter())->write(new CoverageData(
            [
                '/p/src/A.php'      => [1 => 2, 2 => -1, 3 => -2],
                '/p/src/Deep/B.php' => [5 => 1],
            ],
            branches: ['/p/src/A.php' => [
                'f@0' => ['line' => 1, 'hit' => 1],
                'f@4' => ['line' => 1, 'hit' => 0],
            ]],
        ), '/p', 1234);

        self::assertStringContainsString('<coverage line-rate="0.6667" branch-rate="0.5000" lines-covered="2" lines-valid="3" branches-covered="1" branches-valid="2"', $xml);
        self::assertStringContainsString('<source>/p</source>', $xml);
        // A package carries its own rates, not just its name: a reader
        // ranks packages by them before opening any class.
        self::assertStringContainsString('<package name="src" line-rate="0.5000" branch-rate="0.5000"', $xml);
        self::assertStringContainsString('<package name="src/Deep" line-rate="1.0000"', $xml);
        self::assertStringContainsString('<class name="src/A.php" filename="src/A.php" line-rate="0.5000" branch-rate="0.5000"', $xml);
        self::assertStringContainsString('<line number="1" hits="2" branch="true" condition-coverage="50% (1/2)"/>', $xml);
        self::assertStringContainsString('<line number="2" hits="0"/>', $xml);
        $this->assertStringNotContainsString('number="3"', $xml); // dead code is not a line
    }

    public function testTheHtmlReportWritesIndexAndAnnotatedPages(): void
    {
        $root      = sys_get_temp_dir() . '/crucible-html-' . uniqid();
        $sourceDir = $root . '/src';
        $output    = $root . '/report';

        mkdir($sourceDir, 0o777, true);
        file_put_contents($sourceDir . '/A.php', "<?php\nfunction f(bool \$b): int {\n    return \$b ? 1 : 2;\n}\n");

        $data = new CoverageData(
            [$sourceDir . '/A.php' => [2 => 1, 3 => 1]],
            branches: [$sourceDir . '/A.php' => [
                'f@0' => ['line' => 3, 'hit' => 1],
                'f@4' => ['line' => 3, 'hit' => 0],
            ]],
        );

        try {
            (new HtmlReport())->write($data, $root, $output, 'xdebug');

            $index = (string) file_get_contents($output . '/index.html');

            self::assertStringContainsString('Crucible coverage', $index);
            self::assertStringContainsString('href="files/src/A.php.html"', $index);
            self::assertStringContainsString('<th>Branches</th>', $index);

            $page = (string) file_get_contents($output . '/files/src/A.php.html');

            self::assertStringContainsString('class="covered"', $page);
            self::assertStringContainsString('1/2 branches', $page);
            self::assertStringContainsString('href="../../index.html"', $page);
            self::assertStringContainsString('$b ? 1 : 2', $page);
        } finally {
            foreach ([$output . '/files/src/A.php.html', $output . '/index.html', $sourceDir . '/A.php'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            foreach ([$output . '/files/src', $output . '/files', $output, $sourceDir, $root] as $dir) {
                if (is_dir($dir)) {
                    rmdir($dir);
                }
            }
        }
    }

    #[RequiresPhpExtension('xdebug')]
    public function testTheXdebugDriverCollectsWhenInCoverageMode(): void
    {
        $driver = DriverFactory::detect();

        // Asking the factory rather than re-reading the ini: the ini is
        // not the whole answer (XDEBUG_MODE overrides it and does not
        // show up there), and a guard that reimplements the detection it
        // guards can disagree with it. This one cannot.
        if (!$driver instanceof CoverageDriver) {
            self::markTestSkipped('xdebug is loaded without coverage mode.');
        }

        $driver->start();
        $probe = $this->probeLine(21);
        $lines = $driver->stop()->lines[__FILE__] ?? [];

        self::assertSame(42, $probe);
        self::assertNotSame([], $lines);
    }

    #[RequiresPhpExtension('xdebug')]
    public function testTheXdebugDriverCollectsBranchesWhenAsked(): void
    {
        $driver = DriverFactory::detect(branchCoverage: true);

        if (!$driver instanceof CoverageDriver) {
            self::markTestSkipped('xdebug is loaded without coverage mode.');
        }

        $driver->start();
        $probe  = $this->probeBranch(9);
        $window = $driver->stop();

        self::assertSame('big', $probe);
        self::assertNotSame([], $window->lines[__FILE__] ?? []);
        self::assertNotSame([], $window->branches[__FILE__] ?? []);
    }

    private function probeLine(int $x): int
    {
        return $x * 2;
    }

    private function probeBranch(int $x): string
    {
        if ($x > 5) {
            return 'big';
        }

        return 'small';
    }
}
