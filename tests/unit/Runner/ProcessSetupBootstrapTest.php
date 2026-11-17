<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Configuration\Php;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\ProcessSetup;

use function defined;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function realpath;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * A bootstrap loads from the path the configuration names, whether that
 * path is relative to the project or absolute. On Windows a drive path
 * (`C:\…`) is absolute too, and treating it as relative prefixed the
 * project root to it, so the file was simply never found (D-028).
 */
#[CoversClass(ProcessSetup::class)]
final class ProcessSetupBootstrapTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-bootstrap-' . uniqid();

        mkdir($root, 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testAnAbsoluteBootstrapPathLoads(): void
    {
        $constant = $this->bootstrap('absolute');

        ProcessSetup::phpSettings(
            new Configuration([], new Source(), new Php(), bootstrap: $this->project . '/boot.php'),
            new WorkingDirectory($this->project),
        );

        self::assertTrue(defined($constant), 'An absolute bootstrap was not loaded.');
    }

    public function testAnAbsoluteBootstrapFromTheCliOverrideLoads(): void
    {
        $constant = $this->bootstrap('override');

        ProcessSetup::phpSettings(
            new Configuration([], new Source(), new Php()),
            new WorkingDirectory($this->project),
            new Overrides(bootstrap: $this->project . '/boot.php'),
        );

        self::assertTrue(defined($constant), 'An absolute --bootstrap override was not loaded.');
    }

    public function testARelativeBootstrapStillLoads(): void
    {
        $constant = $this->bootstrap('relative');

        ProcessSetup::phpSettings(
            new Configuration([], new Source(), new Php(), bootstrap: 'boot.php'),
            new WorkingDirectory($this->project),
        );

        self::assertTrue(defined($constant), 'A project-relative bootstrap was not loaded.');
    }

    /**
     * Writes a bootstrap that defines a constant unique to this test.
     *
     * @return non-empty-string the constant it defines
     */
    private function bootstrap(string $suffix): string
    {
        $constant = 'CRUCIBLE_BOOTSTRAP_' . uniqid($suffix, false);

        file_put_contents($this->project . '/boot.php', '<?php define(' . var_export($constant, true) . ', true);');

        return $constant;
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
