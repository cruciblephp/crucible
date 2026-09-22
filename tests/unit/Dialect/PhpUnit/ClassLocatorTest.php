<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\PhpUnit;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Depth tracking (the fix for the real spatie/laravel-data crash: a
 * named fixture class declared inside an it() closure body was
 * wrongly treated as a top-level TestCase candidate — see
 * tests/_fixtures/dialect-detection/nested-class/NestedClassPestTest.php,
 * the exact real-world shape this regression test is drawn from).
 */
#[CoversClass(ClassLocator::class)]
final class ClassLocatorTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
    }

    public function testExcludesTheNestedClassInTheRealSpatieRegressionFixture(): void
    {
        $classes = (new ClassLocator())->classesIn($this->fixture('nested-class/NestedClassPestTest.php'));

        self::assertSame([], $classes, 'the file has no top-level class at all — only a nested one');
    }

    public function testExcludesAClassNestedInsideAClosureBody(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            it('does something', function (): void {
                class NestedFixture
                {
                }
            });
            PHP);

        self::assertSame([], (new ClassLocator())->classesIn($file));
    }

    public function testExcludesAClassNestedInsideAFunctionBody(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            function factory(): object
            {
                class FactoryNestedFixture
                {
                }

                return new FactoryNestedFixture();
            }
            PHP);

        self::assertSame([], (new ClassLocator())->classesIn($file));
    }

    public function testStillFindsATrueTopLevelClass(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            class RealTopLevelTest
            {
                public function testIt(): void
                {
                }
            }
            PHP);

        self::assertSame(['RealTopLevelTest'], (new ClassLocator())->classesIn($file));
    }

    public function testStillExcludesAnAnonymousClass(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            $x = new class {
            };
            PHP);

        self::assertSame([], (new ClassLocator())->classesIn($file));
    }

    public function testFindsATopLevelClassDeclaredAfterANestedOne(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            function factory(): object
            {
                class FactoryNestedFixture
                {
                }

                return new FactoryNestedFixture();
            }

            class RealTopLevelTest
            {
            }
            PHP);

        self::assertSame(['RealTopLevelTest'], (new ClassLocator())->classesIn($file));
    }

    private function write(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-class-locator-');

        self::assertIsString($file);

        file_put_contents($file, $source);

        $this->cleanup[] = $file;

        return $file;
    }

    private function fixture(string $relative): string
    {
        return dirname(__DIR__, 4) . '/tests/_fixtures/dialect-detection/' . $relative;
    }
}
