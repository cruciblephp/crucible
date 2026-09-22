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
use LucianoPereira\Crucible\Attributes\CoversFunction;
use LucianoPereira\Crucible\Attributes\MutatesClass;
use LucianoPereira\Crucible\Coverage\CoversTargets;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ReferenceScanner;

use function basename;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * `covers()` — the file-scoped spelling of #[CoversClass]. It is only
 * worth anything if the coverage reader picks it up, so the assertions
 * go through CoversTargets rather than stopping at the metadata.
 */
#[CoversClass(PestBuilder::class)]
final class PestCoversTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
    }

    public function testCoversNamesTheClassUnderTestForEveryTestInTheFile(): void
    {
        $group = $this->build(<<<'PHP'
            <?php
            \covers(\LucianoPereira\Crucible\Impact\ReferenceScanner::class);
            \it('one', function (): void {});
            \it('two', function (): void {});
            PHP);

        self::assertCount(2, $group->tests, 'covers() declares nothing runnable of its own.');

        foreach ($group->tests as $test) {
            $covers = $test->metadata->ofType(CoversClass::class);

            self::assertCount(1, $covers, 'A file-level covers() applies to every test in the file.');
            self::assertSame(ReferenceScanner::class, $covers[0]->className);
        }
    }

    public function testTheCoverageReaderActuallyConsumesIt(): void
    {
        $group = $this->build(<<<'PHP'
            <?php
            \covers(\LucianoPereira\Crucible\Impact\ReferenceScanner::class);
            \it('one', function (): void {});
            PHP);

        // The metadata being present proves nothing on its own — this is
        // the surface that turns it into a coverage restriction.
        self::assertTrue(CoversTargets::from($group->tests[0]->metadata)->declared);
    }

    public function testAFunctionTargetBecomesCoversFunctionRatherThanCoversClass(): void
    {
        $group = $this->build(<<<'PHP'
            <?php
            \covers('array_map');
            \it('one', function (): void {});
            PHP);

        $metadata = $group->tests[0]->metadata;

        self::assertCount(1, $metadata->ofType(CoversFunction::class));
        self::assertCount(0, $metadata->ofType(CoversClass::class));
        self::assertSame('array_map', $metadata->ofType(CoversFunction::class)[0]->functionName);
    }

    public function testATargetThatDoesNotExistIsRefusedRatherThanCoveringNothing(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('covers(App\\Nope\\Missing)');

        $this->build(<<<'PHP'
            <?php
            \covers('App\Nope\Missing');
            \it('one', function (): void {});
            PHP);
    }

    public function testMutatesNamesTheSourceTheMutationRunNarrowsTo(): void
    {
        $group = $this->build(<<<'PHP'
            <?php
            \mutates(\LucianoPereira\Crucible\Impact\ReferenceScanner::class);
            \it('one', function (): void {});
            PHP);

        $mutates = $group->tests[0]->metadata->ofType(MutatesClass::class);

        self::assertCount(1, $mutates);
        self::assertSame(ReferenceScanner::class, $mutates[0]->className);
    }

    public function testAMutatesTargetThatDoesNotExistIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('mutates(App\Nope\Missing)');

        $this->build(<<<'PHP'
            <?php
            \mutates('App\Nope\Missing');
            \it('one', function (): void {});
            PHP);
    }

    private function build(string $source): \LucianoPereira\Crucible\Test\TestGroup
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-pest-covers-');

        if ($file === false) {
            self::fail('Cannot create a temp file.');
        }

        file_put_contents($file, $source);
        $this->cleanup[] = $file;

        $relative = basename($file);

        if ($relative === '') {
            self::fail('tempnam() produced a path with no basename.');
        }

        return (new PestBuilder())->build($file, $relative);
    }
}
