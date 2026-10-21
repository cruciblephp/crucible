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
use LucianoPereira\Crucible\Dialect\Pest\BlockHook;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Dialect\Pest\PestRegistry;
use LucianoPereira\Crucible\Dialect\Pest\PestScopes;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use ReflectionProperty;

use function basename;
use function dirname;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** @phpcpd-keep Fixture for the test below; written to by the pest files it builds. */
final class DescribeHooksLog
{
    /** @var list<string> */
    public static array $lines = [];
}

/**
 * A beforeEach/afterEach inside describe() belongs to that block (D-150).
 * Every expectation here is Pest 5.3.3's answer to the same file: hooks
 * run in declaration order, file-level ones for every test and a block's
 * only for the tests of that block and the blocks nested in it; and
 * beforeAll/afterAll inside describe() is refused at collection.
 */
#[CoversClass(PestRegistry::class)]
#[CoversClass(PestBuilder::class)]
#[CoversClass(BlockHook::class)]
final class PestDescribeHooksTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }

        DescribeHooksLog::$lines = [];
    }

    public function testAHookAppliesOnlyToItsBlockInDeclarationOrder(): void
    {
        $log = $this->run(<<<'PHP'
            <?php
            use LucianoPereira\Crucible\Tests\Dialect\Pest\DescribeHooksLog as L;
            \it('first', function (): void { L::$lines[] = 'test first'; });
            \beforeEach(function (): void { L::$lines[] = 'before file'; });
            \afterEach(function (): void { L::$lines[] = 'after file'; });
            \describe('A', function (): void {
                \it('A1', function (): void { L::$lines[] = 'test A1'; });
                \beforeEach(function (): void { L::$lines[] = 'before A'; });
                \afterEach(function (): void { L::$lines[] = 'after A'; });
                \describe('B', function (): void {
                    \afterEach(function (): void { L::$lines[] = 'after B'; });
                    \beforeEach(function (): void { L::$lines[] = 'before B'; });
                    \it('B1', function (): void { L::$lines[] = 'test B1'; });
                });
                \beforeEach(function (): void { L::$lines[] = 'before A, second'; });
            });
            \beforeEach(function (): void { L::$lines[] = 'before file, after A'; });
            \it('last', function (): void { L::$lines[] = 'test last'; });
            \describe('A', function (): void {
                \it('A again', function (): void { L::$lines[] = 'test A again'; });
            });
            PHP);

        self::assertSame([
            'before file', 'before file, after A', 'test first', 'after file',
            'before file', 'before A', 'before A, second', 'before file, after A', 'test A1', 'after file', 'after A',
            'before file', 'before A', 'before B', 'before A, second', 'before file, after A', 'test B1', 'after file', 'after A', 'after B',
            'before file', 'before file, after A', 'test last', 'after file',
            'before file', 'before file, after A', 'test A again', 'after file',
        ], $log);
    }

    /**
     * A Pest.php beforeEach runs before the file's, and so does its
     * afterEach: ✓ Pest 5.3.3 runs `after global` before `after file`,
     * where spec §3 had read "after".
     */
    public function testAGlobalHookRunsBeforeTheFilesOwnOnBothSides(): void
    {
        $saved = [];

        foreach (['registrations', 'configDir'] as $name) {
            $saved[$name] = (new ReflectionProperty(PestScopes::class, $name))->getValue();
        }

        $file = $this->write(<<<'PHP'
            <?php
            use LucianoPereira\Crucible\Tests\Dialect\Pest\DescribeHooksLog as L;
            \beforeEach(function (): void { L::$lines[] = 'before file'; });
            \afterEach(function (): void { L::$lines[] = 'after file'; });
            \it('runs', function (): void { L::$lines[] = 'test'; });
            PHP);

        try {
            (new ReflectionProperty(PestScopes::class, 'registrations'))->setValue(null, []);
            // The file's own directory: on Windows sys_get_temp_dir() is the short
            // 8.3 path and tempnam() answers the long one, which no scope matches.
            (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, dirname($file));

            PestScopes::pest()
                ->beforeEach(static function (): void {
                    DescribeHooksLog::$lines[] = 'before global';
                })
                ->afterEach(static function (): void {
                    DescribeHooksLog::$lines[] = 'after global';
                });

            (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, null);

            $log = $this->runFile($file);
        } finally {
            foreach ($saved as $name => $value) {
                (new ReflectionProperty(PestScopes::class, $name))->setValue(null, $value);
            }
        }

        self::assertSame(['before global', 'before file', 'test', 'after global', 'after file'], $log);
    }

    public function testBeforeAllInsideDescribeIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The [beforeAll] hook may not be used inside a [describe] block. Please move it to the top level of [');

        $this->run(<<<'PHP'
            <?php
            \describe('A', function (): void {
                \beforeAll(function (): void {});
                \it('runs', function (): void {});
            });
            PHP);
    }

    public function testAfterAllInsideDescribeIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The [afterAll] hook may not be used inside a [describe] block. Please move it to the top level of [');

        $this->run(<<<'PHP'
            <?php
            \describe('A', function (): void {
                \afterAll(function (): void {});
                \it('runs', function (): void {});
            });
            PHP);
    }

    /**
     * Builds the file and runs each of its tests in order.
     *
     * @return list<string> what the file's hooks and tests logged
     */
    private function run(string $source): array
    {
        return $this->runFile($this->write($source));
    }

    /**
     * @return non-empty-string
     */
    private function write(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-pest-describe-');

        if ($file === false) {
            self::fail('Cannot create a temp file.');
        }

        $this->cleanup[] = $file;
        file_put_contents($file, $source);

        return $file;
    }

    /**
     * @param non-empty-string $file
     *
     * @return list<string> what the file's hooks and tests logged
     */
    private function runFile(string $file): array
    {
        $relative = basename($file);
        self::assertNotSame('', $relative);

        foreach ((new PestBuilder())->build($file, $relative)->tests as $test) {
            ($test->test)([]);
        }

        return DescribeHooksLog::$lines;
    }
}
