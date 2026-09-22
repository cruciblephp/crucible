<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Configuration;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

#[CoversClass(Loader::class)]
final class LoaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../_fixtures';

    public function testLoadsAConfigurationFileThatReturnsABuilder(): void
    {
        $loaded = (new Loader())->load(new WorkingDirectory(self::FIXTURES . '/complete'));

        $this->assertSame(self::FIXTURES . '/complete/crucible.php', $loaded->path);
        $this->assertSame('vendor/autoload.php', $loaded->configuration->bootstrap);
        $this->assertCount(1, $loaded->configuration->testSuites);
        $this->assertSame('unit', $loaded->configuration->testSuites[0]->name);
    }

    public function testFallsBackToDistFile(): void
    {
        $loaded = (new Loader())->load(new WorkingDirectory(self::FIXTURES . '/dist-only'));

        $this->assertSame(self::FIXTURES . '/dist-only/crucible.dist.php', $loaded->path);
    }

    public function testExplicitPathWinsOverDefaultFileNames(): void
    {
        $loaded = (new Loader())->load(
            new WorkingDirectory(self::FIXTURES . '/complete'),
            self::FIXTURES . '/dist-only/crucible.dist.php',
        );

        $this->assertSame(self::FIXTURES . '/dist-only/crucible.dist.php', $loaded->path);
    }

    public function testRejectsAMissingExplicitPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not exist');

        (new Loader())->load(new WorkingDirectory(self::FIXTURES . '/complete'), self::FIXTURES . '/nope.php');
    }

    public function testRejectsADirectoryWithoutConfiguration(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No configuration file found');

        (new Loader())->load(new WorkingDirectory(self::FIXTURES));
    }

    public function testRejectsAConfigurationFileWithAWrongReturnType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must return a configuration');

        (new Loader())->load(new WorkingDirectory(self::FIXTURES . '/wrong-return'));
    }

    public function testExistsChecksDefaultFileNames(): void
    {
        $loader = new Loader();

        $this->assertTrue($loader->exists(new WorkingDirectory(self::FIXTURES . '/complete')));
        $this->assertTrue($loader->exists(new WorkingDirectory(self::FIXTURES . '/dist-only')));
        $this->assertFalse($loader->exists(new WorkingDirectory(self::FIXTURES)));
    }
}
