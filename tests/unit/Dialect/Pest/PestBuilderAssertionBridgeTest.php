<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Framework\TestCase;

use function basename;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Stands in for a real PHPUnit\Framework\TestCase (Orchestra
 * Testbench's, in the real spatie/laravel-data case this fix was
 * built from) — its own tearDown() verifies something (there, a
 * Mockery/facade-mock expectation) and reports it through its own
 * numberOfAssertionsPerformed(), entirely separate from Crucible's
 * own counter.
 */
/** @phpcpd-keep Fixture for the test below; instantiated by class-string, never referenced. */
final class RealTestCaseLikeFixture
{
    private int $verifiedDuringTearDown = 0;

    public function setUp(): void {}

    public function tearDown(): void
    {
        $this->verifiedDuringTearDown = 3;
    }

    public function numberOfAssertionsPerformed(): int
    {
        return $this->verifiedDuringTearDown;
    }
}

#[CoversClass(PestBuilder::class)]
final class PestBuilderAssertionBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        Assert::resetAssertionCount();
    }

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
    }

    public function testAssertionsVerifiedDuringATestCasesTearDownBridgeIntoCruciblesOwnCounter(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            \uses(\LucianoPereira\Crucible\Tests\Dialect\Pest\RealTestCaseLikeFixture::class);
            \it('performs no crucible-visible assertions', function (): void {
            });
            PHP);

        $relative = basename($file);

        if ($relative === '') {
            self::fail('tempnam() produced a path with no basename.');
        }

        $group = (new PestBuilder())->build($file, $relative);

        self::assertCount(1, $group->tests);

        // Isolate the closure's own contribution from the assertCount()
        // call just above, which already counted itself.
        Assert::resetAssertionCount();

        ($group->tests[0]->test)([]);

        self::assertSame(3, Assert::assertionCount());
    }

    /**
     * @return non-empty-string
     */
    private function write(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-pest-builder-bridge-');

        if ($file === false) {
            self::fail('Cannot create a temp file.');
        }

        file_put_contents($file, $source);

        $this->cleanup[] = $file;

        return $file;
    }
}
