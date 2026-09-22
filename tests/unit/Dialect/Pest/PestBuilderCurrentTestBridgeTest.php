<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\CurrentTest;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;

use function basename;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Stands in for a uses() class with no PHPUnit-style lifecycle at
 * all — CurrentTest::set() happens unconditionally in
 * PestBuilder::definition(), before the $hasLifecycle branch, since
 * Pest\Laravel\* needs to reach the instance regardless of whether it
 * happens to have setUp()/tearDown().
 */
/** @phpcpd-keep Fixture for the test below; instantiated by class-string, never referenced. */
final class PlainFixture {}

#[CoversClass(PestBuilder::class)]
#[CoversClass(CurrentTest::class)]
final class PestBuilderCurrentTestBridgeTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }

        CurrentTest::set(null);
    }

    public function testCurrentTestIsSetToTheRunningInstanceDuringTheTestBody(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            \uses(\LucianoPereira\Crucible\Tests\Dialect\Pest\PlainFixture::class);
            \it('sees itself as the current test', function (): bool {
                return $this === \LucianoPereira\Crucible\Dialect\Pest\CurrentTest::get();
            });
            PHP);

        $relative = basename($file);

        if ($relative === '') {
            self::fail('tempnam() produced a path with no basename.');
        }

        $group = (new PestBuilder())->build($file, $relative);

        self::assertTrue(($group->tests[0]->test)([]));
    }

    public function testCurrentTestIsClearedOnceTheTestFinishes(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            \uses(\LucianoPereira\Crucible\Tests\Dialect\Pest\PlainFixture::class);
            \it('does nothing notable', function (): void {
            });
            PHP);

        $relative = basename($file);

        if ($relative === '') {
            self::fail('tempnam() produced a path with no basename.');
        }

        $group = (new PestBuilder())->build($file, $relative);

        ($group->tests[0]->test)([]);

        $this->expectException(ConfigurationException::class);

        CurrentTest::get();
    }

    /**
     * @return non-empty-string
     */
    private function write(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-pest-builder-current-test-');

        if ($file === false) {
            self::fail('Cannot create a temp file.');
        }

        file_put_contents($file, $source);

        $this->cleanup[] = $file;

        return $file;
    }
}
