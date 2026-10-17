<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Dialect\Pest\RunningTest;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_map;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function putenv;
use function realpath;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

use const PHP_BINARY;

/**
 * The prelude in a child process, against a vendor laid out the way
 * Composer lays out a Pest project with pestphp/pest-plugin-laravel: the
 * plugin's `files` entry calls expect()->extend() while it loads, which
 * ended the process at exit 255 before Crucible printed anything.
 */
#[CoversClass(PestBuilder::class)]
#[CoversClass(RunningTest::class)]
final class VocabularyPreludeTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        putenv('CRUCIBLE_PEST_VENDOR');

        if ($this->root !== '') {
            $this->remove($this->root);
        }
    }

    public function testAPluginThatExtendsExpectWhileLoadingReachesCrucible(): void
    {
        $output = $this->runWithPrelude(<<<'PHP'
            echo function_exists('pest_probe_loaded') ? 'pest-functions-loaded' : 'pest-functions-skipped', "\n";
            echo realpath((string) (new ReflectionFunction('expect'))->getFileName()), "\n";
            expect('x')->toBeProbe();
            echo "extension-works\n";
            echo get_class(test()), "\n";
            PHP);

        self::assertSame([
            'pest-functions-skipped',
            (string) realpath(dirname(__DIR__, 4) . '/src/Dialect/Pest/functions.php'),
            'extension-works',
            RunningTest::class,
        ], $output);
    }

    /**
     * @return list<string>
     */
    private function runWithPrelude(string $body): array
    {
        $this->root = sys_get_temp_dir() . '/crucible-prelude-' . uniqid();
        $vendor     = $this->root . '/vendor';

        foreach (['/composer', '/pestphp/pest/src', '/pestphp/pest-plugin-laravel/src'] as $directory) {
            mkdir($vendor . $directory, 0o777, true);
        }

        file_put_contents($vendor . '/pestphp/pest/src/Functions.php', '<?php function pest_probe_loaded(): void {}');
        file_put_contents(
            $vendor . '/pestphp/pest-plugin-laravel/src/Autoload.php',
            '<?php namespace Pest\Laravel; expect()->extend("toBeProbe", function () { return $this->toBe("x"); });',
        );
        file_put_contents($vendor . '/composer/autoload_files.php', '<?php return ' . var_export([
            'pest'   => $vendor . '/pestphp/pest/src/Functions.php',
            'plugin' => $vendor . '/pestphp/pest-plugin-laravel/src/Autoload.php',
        ], true) . ';');

        // Composer's own order: the class loader first, then each `files`
        // entry behind the guard the prelude writes to.
        $script = $this->root . '/run.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true) . ';'
            . 'foreach (require ' . var_export($vendor . '/composer/autoload_files.php', true) . ' as $hash => $file) {'
            . ' if (empty($GLOBALS["__composer_autoload_files"][$hash])) { $GLOBALS["__composer_autoload_files"][$hash] = true; require $file; } }'
            . "\n" . $body);

        putenv('CRUCIBLE_PEST_VENDOR=' . $vendor);

        $command = [PHP_BINARY, '-d', 'auto_prepend_file=' . dirname(__DIR__, 4) . '/src/Dialect/Pest/vocabulary-prelude.php', $script];
        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output);

        return $output;
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
