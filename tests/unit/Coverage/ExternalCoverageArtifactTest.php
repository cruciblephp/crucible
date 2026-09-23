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
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\CoverageMap;
use LucianoPereira\Crucible\Coverage\CoverageWindow;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_keys;
use function array_map;
use function count;
use function escapeshellarg;
use function exec;
use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_dir;
use function mkdir;
use function putenv;
use function realpath;
use function rmdir;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A line executed in a child process used to read as a line nothing
 * tests: the conformance suite drives the whole assertion surface
 * against the real phpunit binary, out of process, and none of it
 * reached the map. The prelude closes that, and the property that
 * makes it safe to merge is the one asserted hardest here — an
 * artifact carries lines and NO test ids, so the report learns the
 * line ran while the impact graph learns nothing about a test that
 * does not exist.
 */
#[CoversClass(CoverageData::class)]
final class ExternalCoverageArtifactTest extends TestCase
{
    /** @var list<string> */
    private array $rubbish = [];

    protected function tearDown(): void
    {
        foreach ($this->rubbish as $path) {
            $files = glob($path . '/*');

            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }

            if (is_dir($path)) {
                rmdir($path);
            }
        }

        $this->rubbish = [];
        putenv('CRUCIBLE_COVERAGE_ARTIFACTS');
    }

    public function testAnArtifactContributesItsLinesAndClaimsNoTest(): void
    {
        $data = new CoverageData();
        $data->record('tests/OwnTest.php::testOne', new CoverageWindow(['/p/src/A.php' => [3 => 1, 4 => -1]]));

        // What a child process produces: lines, and deliberately no
        // test ids at all.
        $external = new CoverageData(['/p/src/A.php' => [4 => 1, 9 => 1]]);

        $data->merge($external);

        // The line the suite missed and the child ran is covered now.
        self::assertSame(1, $data->lines['/p/src/A.php'][4]);
        self::assertSame(1, $data->lines['/p/src/A.php'][9]);

        // And no test claims any of it: the impact graph and the
        // mutation query read this map, and both want tests that exist.
        self::assertSame(['tests/OwnTest.php::testOne'], array_keys($data->tests));

        // The observed-edge map the impact graph reads is built from
        // $tests, so it still names one test file and the single source
        // that test actually ran -- line 9 reached the report without
        // inventing an edge.
        self::assertSame(['tests/OwnTest.php' => ['src/A.php']], CoverageMap::fromData($data, '/p'));
    }

    public function testTheConformancePreludeWritesWhatAChildExecuted(): void
    {
        $enable = match (true) {
            extension_loaded('pcov')   => ['-d', 'pcov.enabled=1'],
            extension_loaded('xdebug') => ['-d', 'xdebug.mode=coverage'],
            default                    => [],
        };

        if ($enable === []) {
            self::markTestSkipped('the prelude needs pcov or xdebug in the child.');
        }

        $directory = sys_get_temp_dir() . '/crucible-external-' . uniqid();
        mkdir($directory, 0o777, true);
        $this->rubbish[] = $directory;

        $root = __DIR__ . '/../../..';
        putenv('CRUCIBLE_COVERAGE_ARTIFACTS=' . $directory);

        // A real script file, not php -r: PHP applies auto_prepend_file
        // to a script and silently ignores it for -r, so -r would test
        // nothing and pass for the wrong reason.
        $script = $directory . '/child.php';
        file_put_contents($script, '<?php echo (new \LucianoPereira\Crucible\Test\TestId("a.php", "b"))->toString();');

        // The prelude is attached the way conformance attaches it, so
        // this exercises the real mechanism rather than a stand-in.
        $command = [
            PHP_BINARY,
            ...$enable,
            '-d', 'auto_prepend_file=' . $root . '/conformance/coverage-prelude.php',
            $script,
        ];

        exec(implode(' ', array_map(escapeshellarg(...), $command)), $output, $status);

        self::assertSame(0, $status);
        self::assertSame(['a.php::b'], $output);

        $artifacts = glob($directory . '/external-*.json');
        self::assertIsArray($artifacts);
        self::assertCount(1, $artifacts);

        $contents = file_get_contents($artifacts[0]);
        self::assertIsString($contents);

        $data = CoverageData::fromJson($contents);

        // It saw the class the child touched, and it named no test.
        self::assertSame([], $data->tests);
        self::assertNotSame([], $data->lines);

        // Resolved, because the driver reports real paths in the OS's
        // own separator and $root is spelled with '..' and '/'.
        $real    = (string) realpath($root);
        $touched = 0;

        foreach ($data->lines as $file => $lines) {
            if (str_starts_with($file, $real) || str_starts_with($file, '/')) {
                $touched += count($lines) > 0 ? 1 : 0;
            }
        }

        self::assertGreaterThan(0, $touched);
    }
}
