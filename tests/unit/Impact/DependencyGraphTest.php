<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\DependencyGraph;

use function array_keys;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function realpath;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(DependencyGraph::class)]
final class DependencyGraphTest extends TestCase
{
    public function testTheClosureFollowsReferencesTransitively(): void
    {
        $fixtures = __DIR__ . '/Fixtures';
        $closure  = (new DependencyGraph(dirname(__DIR__, 3)))->closureOf($fixtures . '/Gamma.php');

        self::assertArrayHasKey($this->key($fixtures . '/Gamma.php'), $closure);
        self::assertArrayHasKey($this->key($fixtures . '/Beta.php'), $closure);   // aliased import
        self::assertArrayHasKey($this->key($fixtures . '/Alpha.php'), $closure);  // same-namespace, transitive
    }

    public function testAnIslandReachesOnlyItself(): void
    {
        $fixtures = __DIR__ . '/Fixtures';
        $closure  = (new DependencyGraph(dirname(__DIR__, 3)))->closureOf($fixtures . '/Delta.php');

        self::assertArrayHasKey($this->key($fixtures . '/Delta.php'), $closure);
        $this->assertArrayNotHasKey($this->key($fixtures . '/Alpha.php'), $closure);
        $this->assertArrayNotHasKey($this->key($fixtures . '/Gamma.php'), $closure);
    }

    public function testVendorFilesAreNeverGraphNodes(): void
    {
        // Every file in this repo references vendor classes somewhere
        // (Composer\Autoload\ClassLoader in the graph itself); none may
        // appear in a closure.
        $closure = (new DependencyGraph(dirname(__DIR__, 3)))->closureOf(dirname(__DIR__, 3) . '/src/Impact/DependencyGraph.php');

        foreach (array_keys($closure) as $file) {
            if (str_contains($file, '/vendor/')) {
                self::fail($file . ' is vendor code and must not be tracked.');
            }
        }

        self::assertArrayHasKey($this->key(dirname(__DIR__, 3) . '/src/Impact/ReferenceScanner.php'), $closure);
    }

    /**
     * A nested vendor/ is still vendor code.
     *
     * The test above only catches this while some OTHER checkout inside
     * the repo happens to declare a class this closure reaches — which
     * is how it was found (phpcpd-main/ gained symfony/polyfill-php80,
     * whose PhpToken stub entered the graph). This pins the rule
     * instead, and its control is the other half of the pair: Owned sits
     * at the same depth in the same namespace and MUST be tracked, so a
     * green here cannot come from the class simply being unresolvable.
     */
    public function testVendorIsExcludedByPathSegmentNotOnlyAtTheRoot(): void
    {
        $fixtures = __DIR__ . '/Fixtures/NestedVendor';

        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
        $loader->addPsr4('NestedVendorFixture\\', [$fixtures . '/vendor/pkg', $fixtures . '/pkg']);

        $closure = (new DependencyGraph(dirname(__DIR__, 3)))->closureOf($fixtures . '/Consumer.php');

        self::assertArrayHasKey($this->key($fixtures . '/pkg/Owned.php'), $closure, 'the control: an owned package is tracked');
        self::assertArrayNotHasKey($this->key($fixtures . '/vendor/pkg/Vendored.php'), $closure, 'a nested vendor/ is still vendor');
    }

    public function testObservedEdgesApplyOneHopFromTheRootOnly(): void
    {
        $fixtures = __DIR__ . '/Fixtures';
        $root     = dirname(__DIR__, 3);

        // Delta is a static island; an observed edge from Gamma (the
        // root) pulls it in — coverage saw what static analysis could
        // not (D-041).
        $graph   = new DependencyGraph($root, observedEdges: [$this->key($fixtures . '/Gamma.php') => [$this->key($fixtures . '/Delta.php')]]);
        $closure = $graph->closureOf($fixtures . '/Gamma.php');

        self::assertArrayHasKey($this->key($fixtures . '/Delta.php'), $closure);
        self::assertArrayHasKey($this->key($fixtures . '/Alpha.php'), $closure); // static edges still walk

        // The same edge keyed on an INNER node must not be followed:
        // observed edges describe the tests declared in that file,
        // not the file's production code.
        $conflated = new DependencyGraph($root, observedEdges: [$this->key($fixtures . '/Beta.php') => [$this->key($fixtures . '/Delta.php')]]);

        $this->assertArrayNotHasKey($this->key($fixtures . '/Delta.php'), $conflated->closureOf($fixtures . '/Gamma.php'));
    }

    public function testPestFamilyFilesDependOnTheSuiteConfigurationAboveThem(): void
    {
        $root = sys_get_temp_dir() . '/crucible-impact-' . uniqid();

        mkdir($root . '/sub/Datasets', 0o777, true);

        // macOS tempdirs are symlinks; the graph realpaths everything.
        $real = realpath($root);
        $root = $real === false ? $root : $real;
        file_put_contents($root . '/sub/Pest.php', '<?php');
        file_put_contents($root . '/sub/Datasets/Rows.php', '<?php');
        file_put_contents($root . '/sub/CoffeeDialect.crucible.php', '<?php');

        try {
            $closure = (new DependencyGraph($root))->closureOf($root . '/sub/CoffeeDialect.crucible.php');

            self::assertArrayHasKey($this->key($root . '/sub/Pest.php'), $closure);
            self::assertArrayHasKey($this->key($root . '/sub/Datasets/Rows.php'), $closure);
        } finally {
            foreach (['/sub/CoffeeDialect.crucible.php', '/sub/Datasets/Rows.php', '/sub/Pest.php'] as $file) {
                unlink($root . $file);
            }

            foreach (['/sub/Datasets', '/sub', ''] as $directory) {
                if (is_dir($root . $directory)) {
                    rmdir($root . $directory);
                }
            }
        }
    }

    /** The form the graph keys on: resolved where the file exists, the OS's own separator either way. */
    private function key(string $path): string
    {
        $real = realpath($path);

        return $real === false ? WorkingDirectory::native($path) : $real;
    }
}
