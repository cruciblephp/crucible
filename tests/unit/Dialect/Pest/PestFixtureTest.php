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
use LucianoPereira\Crucible\Dialect\Pest\PestRegistry;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function file_get_contents;

/**
 * `fixture()` — a path helper, so the only assertions worth making are
 * that the path it returns can actually be read, and that a missing one
 * is refused where it is written rather than somewhere downstream.
 */
#[CoversClass(PestRegistry::class)]
final class PestFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        PestRegistry::begin(__FILE__, dirname(__DIR__, 4));
    }

    protected function tearDown(): void
    {
        PestRegistry::drain();
    }

    public function testFixtureAlwaysResolvesUnderTheProjectsTestsFixtures(): void
    {
        // Measured against Pest 5.1.1: the base is `tests/Fixtures` under
        // the project root, not the nearest `Fixtures` above the calling
        // file, and not the calling file's own test-suite directory.
        // This file lives four levels down and still resolves there.
        $path = PestRegistry::fixture('sample.txt');

        self::assertSame(dirname(__DIR__, 4) . '/tests/Fixtures/sample.txt', $path);
        self::assertSame("seed-data\n", file_get_contents($path));
    }

    public function testTheNameIsJoinedToFixturesRatherThanContainingIt(): void
    {
        // fixture('Fixtures/sample.txt') is an error in the incumbent,
        // which prepends the directory itself. Accepting both spellings
        // would make one of them silently resolve somewhere else.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Fixtures/Fixtures/sample.txt');

        PestRegistry::fixture('Fixtures/sample.txt');
    }

    public function testAMissingFixtureIsRefusedAndSaysWhereItLooked(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('fixture(nope.txt)');

        PestRegistry::fixture('nope.txt');
    }

    public function testFixtureOutsideCollectionSaysSoRatherThanGuessingADirectory(): void
    {
        PestRegistry::drain();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('only available while a test file is being collected');

        PestRegistry::fixture('sample.txt');
    }
}
