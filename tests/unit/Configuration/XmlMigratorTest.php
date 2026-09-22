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
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Configuration\XmlMigrator;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_put_contents;
use function is_file;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(XmlMigrator::class)]
final class XmlMigratorTest extends TestCase
{
    private const string DOCUMENT = <<<'XML'
        <phpunit bootstrap="tests/bootstrap.php"
                 colors="true"
                 cacheDirectory=".cache/crucible"
                 cacheResult="false"
                 executionOrder="defects"
                 failOnRisky="true"
                 stopOnFailure="true"
                 backupGlobals="true"
                 requireCoverageMetadata="true">
            <testsuites>
                <testsuite name="unit">
                    <directory suffix="Test.php">tests/unit</directory>
                    <file>tests/QuickTest.php</file>
                </testsuite>
                <testsuite name="integration">
                    <directory>tests/integration</directory>
                </testsuite>
            </testsuites>
            <source>
                <include><directory suffix=".php">src</directory></include>
                <exclude><directory>src/generated</directory></exclude>
            </source>
            <php>
                <ini name="memory_limit" value="1G"/>
                <env name="APP_ENV" value="testing"/>
                <const name="CRUCIBLE_MIGRATED_FLAG" value="true"/>
                <const name="CRUCIBLE_MIGRATED_ANSWER" value="42"/>
            </php>
            <coverage/>
        </phpunit>
        XML;

    /** @var non-empty-string */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crucible-migrate-' . uniqid();
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->directory . '/crucible.php')) {
            unlink($this->directory . '/crucible.php');
        }
    }

    public function testMigratedConfigurationLoadsWithTheRightTypedValues(): void
    {
        $result = (new XmlMigrator())->migrate(self::DOCUMENT);

        file_put_contents($this->directory . '/crucible.php', $result->code);

        $configuration = (new Loader())->load(new WorkingDirectory($this->directory))->configuration;

        $this->assertSame('tests/bootstrap.php', $configuration->bootstrap);
        $this->assertTrue($configuration->colors);
        $this->assertSame('.cache/crucible', $configuration->cacheDirectory);
        $this->assertFalse($configuration->cacheResult);
        $this->assertSame(ExecutionOrder::DefectsFirst, $configuration->executionOrder);
        $this->assertTrue($configuration->failOnRisky);
        $this->assertTrue($configuration->stopOnFailure);
        $this->assertTrue($configuration->backupGlobals);

        $this->assertCount(2, $configuration->testSuites);
        $this->assertSame('unit', $configuration->testSuites[0]->name);
        $this->assertSame(['tests/unit'], $configuration->testSuites[0]->directories);
        $this->assertSame(['tests/QuickTest.php'], $configuration->testSuites[0]->files);
        $this->assertSame(['tests/integration'], $configuration->testSuites[1]->directories);

        $this->assertSame(['src'], $configuration->source->includeDirectories);
        $this->assertSame(['src/generated'], $configuration->source->excludeDirectories);

        $this->assertSame(['memory_limit' => '1G'], $configuration->php->ini);
        $this->assertSame(['APP_ENV' => 'testing'], $configuration->php->env);
        $this->assertSame(
            ['CRUCIBLE_MIGRATED_FLAG' => true, 'CRUCIBLE_MIGRATED_ANSWER' => 42],
            $configuration->php->constants,
        );
    }

    public function testEverythingWithoutACrucibleEquivalentIsNamedInTheNotes(): void
    {
        $result = (new XmlMigrator())->migrate(self::DOCUMENT);

        $this->assertContains('element <coverage>', $result->notes);

        // requireCoverageMetadata gained a Crucible equivalent (D-063):
        // it now migrates instead of landing in the notes.
        $this->assertNotContains('attribute requireCoverageMetadata="true"', $result->notes);
        $this->assertStringContainsString('->requireCoverageMetadata(true)', $result->code);

        // The notes are duplicated into the generated file itself.
        $this->assertStringContainsString('Not migrated', $result->code);
    }

    public function testDefaultsAreNotRestatedInTheGeneratedCode(): void
    {
        $result = (new XmlMigrator())->migrate('<phpunit colors="false" cacheResult="true"/>');

        $this->assertStringNotContainsString('colors', $result->code);
        $this->assertStringNotContainsString('cacheResult', $result->code);
    }

    public function testUnparseableXmlThrows(): void
    {
        $this->expectException(ConfigurationException::class);

        (new XmlMigrator())->migrate('this is not xml <');
    }
}
