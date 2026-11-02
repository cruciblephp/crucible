<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Types\Observed;

use InvalidArgumentException;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Extension\Artifact\Claim;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Types\Observed\Fixtures\Catalogue;
use LucianoPereira\Crucible\Types\Observed\DocTypes;
use LucianoPereira\Crucible\Types\Observed\IncludeRewriter;
use LucianoPereira\Crucible\Types\Observed\ObservedTypesCheck;
use LucianoPereira\Crucible\Types\Observed\Observer;
use LucianoPereira\Crucible\Types\Observed\ProbeRewriter;
use ReflectionClass;

use function array_key_first;
use function class_exists;
use function file_get_contents;
use function glob;
use function is_dir;
use function iterator_to_array;
use function json_decode;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The fixture is included once, through the rewriter, with the probes in
 * it; each test then calls it and reads what the observer made of the calls.
 */
#[CoversClass(Observer::class)]
#[CoversClass(ProbeRewriter::class)]
#[CoversClass(IncludeRewriter::class)]
#[CoversClass(DocTypes::class)]
#[CoversClass(ObservedTypesCheck::class)]
final class ObservedTypesTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/Fixtures/Catalogue.php';

    protected function setUp(): void
    {
        if (!class_exists(Catalogue::class, false)) {
            $rewriter = new ProbeRewriter();

            IncludeRewriter::register(static fn(string $path): ?string => $path === self::FIXTURE ? $rewriter->rewrite((string) file_get_contents($path)) : null);

            try {
                require self::FIXTURE;
            } finally {
                IncludeRewriter::unregister();
            }
        }

        Observer::reset();
    }

    protected function tearDown(): void
    {
        Observer::reset();
    }

    /** @return array<string, array{written: string, mismatch: string, calls: int}> */
    private function contradictions(string $method): array
    {
        return isset(Observer::functions()[Catalogue::class . '::' . $method]) ? Observer::functions()[Catalogue::class . '::' . $method]->contradictions : [];
    }

    public function testTheRewrittenClassIsStillDeclaredInItsOwnFile(): void
    {
        self::assertSame(self::FIXTURE, (new ReflectionClass(Catalogue::class))->getFileName());
    }

    public function testAReturnTheDocblockRefusesIsFoundWithTheValue(): void
    {
        $catalogue = new Catalogue();

        self::assertSame(3, $catalogue->priceOf('tea'));
        self::assertNull($catalogue->priceOf('coffee'));

        self::assertSame(
            ['return' => ['written' => 'int', 'mismatch' => '$: expected int, got null', 'calls' => 1]],
            $this->contradictions('priceOf'),
        );
        self::assertSame(['positive-int' => 1, 'null' => 1], Observer::functions()[Catalogue::class . '::priceOf']->types['return'] ?? null);
    }

    public function testACallThatThrowsIsNotTheContract(): void
    {
        try {
            (new Catalogue())->stock('', -1); // @phpstan-ignore argument.type (the call the docblock forbids is the point)
        } catch (InvalidArgumentException) {
        }

        self::assertArrayNotHasKey(Catalogue::class . '::stock', Observer::functions());

        self::assertSame(1, (new Catalogue())->stock('', 1)); // @phpstan-ignore argument.type (as above)

        self::assertSame("\$item", array_key_first($this->contradictions('stock')));
    }

    public function testATagTheMethodDoesNotRestateIsReadFromWhatItImplements(): void
    {
        self::assertSame(0.5, (new Catalogue())->discount(50));
        self::assertSame('positive-int', $this->contradictions('discount')['return']['written'] ?? null);
    }

    public function testAnIteratorIsNamedButNeverConsumed(): void
    {
        $items = (new Catalogue())->items();

        self::assertSame(['tea'], iterator_to_array($items));
        self::assertSame([], $this->contradictions('items'));
        self::assertSame(['\ArrayIterator' => 1], Observer::functions()[Catalogue::class . '::items']->types['return'] ?? null);
    }

    public function testATypeAliasIsNotReadAsAClass(): void
    {
        self::assertSame(['item' => 'tea', 'count' => 1], (new Catalogue())->line());
        self::assertSame([], $this->contradictions('line'));
    }

    public function testAGeneratorIsLeftAsItIs(): void
    {
        self::assertSame(['tea'], iterator_to_array((new Catalogue())->lazily()));
        self::assertArrayNotHasKey(Catalogue::class . '::lazily', Observer::functions());
    }

    public function testTheCheckCountsEachContradictedSlotAndWritesWhatEachReceived(): void
    {
        $cache = sys_get_temp_dir() . '/crucible-observed-' . uniqid();
        self::assertNull((new Catalogue())->priceOf('coffee'));
        self::assertSame(0.5, (new Catalogue())->discount(50));

        try {
            $claim = (new ObservedTypesCheck($cache))->inspect(new WorkingDirectory(__DIR__));

            self::assertInstanceOf(Claim::class, $claim);
            self::assertSame(2, $claim->actual);
            self::assertSame(0, $claim->expected);
            self::assertStringContainsString('Fixtures/Catalogue.php:29 ' . Catalogue::class . '::priceOf() return: @return int, $: expected int, got null (1 of 1 calls)', (string) $claim->detail);

            $written = json_decode((string) file_get_contents($cache . '/observed-types.json'), true);

            self::assertIsArray($written);
            self::assertIsArray($written['functions'] ?? null);
            self::assertSame(
                ['file' => 'Fixtures/Catalogue.php', 'line' => 29, 'calls' => 1, 'types' => ['$item' => ['non-empty-string' => 1], 'return' => ['null' => 1]]],
                $written['functions'][Catalogue::class . '::priceOf'] ?? null,
            );
        } finally {
            $files = glob($cache . '/*');

            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }

            if (is_dir($cache)) {
                rmdir($cache);
            }
        }
    }
}
