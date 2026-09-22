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
use LucianoPereira\Crucible\Dialect\Pest\PestFileSniffer;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(PestFileSniffer::class)]
final class PestFileSnifferTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
    }

    public function testDetectsATopLevelItCall(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            it('does something', function (): void {
            });
            PHP);

        self::assertTrue((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testDetectsABackslashPrefixedCall(): void
    {
        // pint's native_function_invocation fixer rewrites it() to
        // \it() — the same convention this project's own real
        // .pest.php files use (tests/unit/Pest.php: \pest(),
        // \dataset()). It tokenizes as one T_NAME_FULLY_QUALIFIED
        // token, not T_STRING — a real, previously-uncaught gap.
        $file = $this->write(<<<'PHP'
            <?php
            \it('does something', function (): void {
            });
            PHP);

        self::assertTrue((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testDetectsATopLevelDatasetOnlyCall(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            dataset('beans', ['arabica', 'robusta']);
            PHP);

        self::assertTrue((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testDetectsAFileWhoseOnlyTestIsATopLevelTodo(): void
    {
        // A planned test is still a test. Missing it would route the file
        // to no dialect at all, and the todo would never be reported.
        $file = $this->write(<<<'PHP'
            <?php
            todo('wire the spinner');
            PHP);

        self::assertTrue((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testDetectsAFileWhoseOnlyPestSpellingIsCovers(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            covers(\LucianoPereira\Crucible\Impact\ReferenceScanner::class);
            PHP);

        self::assertTrue((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testReturnsFalseWhenThereAreNoEntryCalls(): void
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

        self::assertFalse((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testIgnoresAMethodCallOfTheSameName(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            class Runner
            {
                public function run(): void
                {
                    $this->it('is not a pest call');
                }
            }
            PHP);

        self::assertFalse((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testIgnoresAStaticCallOfTheSameName(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            Factory::describe('is not a pest call');
            PHP);

        self::assertFalse((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testIgnoresAUserDeclaredFunctionOfTheSameName(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            function it(string $name): void
            {
            }
            PHP);

        self::assertFalse((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    public function testIgnoresACallNestedInsideAClosureBody(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            function factory(): void
            {
                it('nested inside a function body, not top-level', function (): void {
                });
            }
            PHP);

        self::assertFalse((new PestFileSniffer())->hasTopLevelCalls($file));
    }

    private function write(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-pest-sniffer-');

        self::assertIsString($file);

        file_put_contents($file, $source);

        $this->cleanup[] = $file;

        return $file;
    }
}
