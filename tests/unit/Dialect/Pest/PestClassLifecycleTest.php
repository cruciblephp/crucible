<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use Closure;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Framework\TestCase;

use function basename;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Stands in for a test case class with class-level lifecycle — Orchestra
 * Testbench's, in the spatie/laravel-data suite this was found on: it
 * gathers state per test and clears it in tearDownAfterClass().
 */
/** @phpcpd-keep Fixture for the test below; instantiated by class-string, never referenced. */
class ClassLifecycleFixture
{
    /** @var list<string> */
    public static array $log = [];

    public static function setUpBeforeClass(): void
    {
        self::$log[] = 'setUpBeforeClass';
    }

    public static function tearDownAfterClass(): void
    {
        self::$log[] = 'tearDownAfterClass';
    }

    public function setUp(): void {}

    public function tearDown(): void {}
}

/**
 * A Pest file is a class (D-133): real Pest generates one extending the
 * file's uses() class, and the runner calls its setUpBeforeClass() and
 * tearDownAfterClass() around the file's tests — the parent's first on
 * the way in, the file's beforeAll() hooks after it; afterAll() before
 * the parent's on the way out. Crucible called neither, so a base class
 * that clears per-class state there (Testbench) never cleared it.
 */
#[CoversClass(PestBuilder::class)]
final class PestClassLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }

        ClassLifecycleFixture::$log = [];
    }

    public function testTheClassLifecycleWrapsTheFilesOwnHooks(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-pest-lifecycle-');

        if ($file === false) {
            self::fail('Cannot create a temp file.');
        }

        $this->cleanup[] = $file;

        file_put_contents($file, <<<'PHP'
            <?php
            use LucianoPereira\Crucible\Tests\Dialect\Pest\ClassLifecycleFixture;
            \uses(ClassLifecycleFixture::class);
            \beforeAll(function (): void { ClassLifecycleFixture::$log[] = 'beforeAll'; });
            \afterAll(function (): void { ClassLifecycleFixture::$log[] = 'afterAll'; });
            \it('runs', function (): void { ClassLifecycleFixture::$log[] = 'test'; });
            PHP);

        $relative = basename($file);
        self::assertNotSame('', $relative);

        $group = (new PestBuilder())->build($file, $relative);

        self::assertInstanceOf(Closure::class, $group->beforeAll);
        self::assertInstanceOf(Closure::class, $group->afterAll);

        ($group->beforeAll)();
        ($group->tests[0]->test)([]);
        ($group->afterAll)();

        self::assertSame(['setUpBeforeClass', 'beforeAll', 'test', 'afterAll', 'tearDownAfterClass'], ClassLifecycleFixture::$log);
    }
}
