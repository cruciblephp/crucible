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
use LucianoPereira\Crucible\Configuration\Crucible;
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;

#[CoversClass(\LucianoPereira\Crucible\Configuration\Builder::class)]
#[CoversClass(\LucianoPereira\Crucible\Configuration\Configuration::class)]
#[CoversClass(\LucianoPereira\Crucible\Configuration\Crucible::class)]
final class BuilderTest extends TestCase
{
    public function testDefaultsMatchThePhpunitSpec(): void
    {
        $configuration = Crucible::configure()->build();

        $this->assertSame([], $configuration->testSuites);
        $this->assertNull($configuration->bootstrap);
        $this->assertSame('.crucible.cache', $configuration->cacheDirectory);
        $this->assertFalse($configuration->colors);
        $this->assertSame(ExecutionOrder::Declared, $configuration->executionOrder);
        $this->assertFalse($configuration->failOnRisky);
        $this->assertFalse($configuration->source->notEmpty());
    }

    public function testMapsThePhpunitXmlCoreSurface(): void
    {
        $configuration = Crucible::configure()
            ->bootstrap('vendor/autoload.php')
            ->testSuite('unit', 'tests/Unit')
            ->testSuite('feature', ['tests/Feature', 'tests/Http'], suffix: 'FeatureTest.php')
            ->source(include: ['src'], exclude: ['src/generated'])
            ->cacheDirectory('.cache/crucible')
            ->executionOrder(ExecutionOrder::DefectsFirst)
            ->stopOnFailure()
            ->testdox()
            ->ini('memory_limit', '512M')
            ->env('APP_ENV', 'testing')
            ->constant('CRUCIBLE_TESTING', true)
            ->build();

        $this->assertSame('vendor/autoload.php', $configuration->bootstrap);
        $this->assertCount(2, $configuration->testSuites);
        $this->assertSame('unit', $configuration->testSuites[0]->name);
        $this->assertSame(['tests/Unit'], $configuration->testSuites[0]->directories);
        $this->assertSame('Test.php', $configuration->testSuites[0]->suffix);
        $this->assertSame(['tests/Feature', 'tests/Http'], $configuration->testSuites[1]->directories);
        $this->assertSame('FeatureTest.php', $configuration->testSuites[1]->suffix);
        $this->assertSame(['src'], $configuration->source->includeDirectories);
        $this->assertSame(['src/generated'], $configuration->source->excludeDirectories);
        $this->assertTrue($configuration->source->notEmpty());
        $this->assertSame('.cache/crucible', $configuration->cacheDirectory);
        $this->assertSame(ExecutionOrder::DefectsFirst, $configuration->executionOrder);
        $this->assertTrue($configuration->stopOnFailure);
        $this->assertTrue($configuration->testdox);
        $this->assertSame(['memory_limit' => '512M'], $configuration->php->ini);
        $this->assertSame(['APP_ENV' => 'testing'], $configuration->php->env);
        $this->assertSame(['CRUCIBLE_TESTING' => true], $configuration->php->constants);
    }

    public function testStrictEnablesEveryFailOnFlag(): void
    {
        $configuration = Crucible::configure()->strict()->build();

        $this->assertTrue($configuration->failOnDeprecation);
        $this->assertTrue($configuration->failOnIncomplete);
        $this->assertTrue($configuration->failOnNotice);
        $this->assertTrue($configuration->failOnRisky);
        $this->assertTrue($configuration->failOnSkipped);
        $this->assertTrue($configuration->failOnWarning);
    }

    public function testRejectsDuplicateTestSuiteNames(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Test suite "unit" is defined more than once.');

        Crucible::configure()
            ->testSuite('unit', 'tests/Unit')
            ->testSuite('unit', 'tests/AlsoUnit');
    }

    public function testReportFormatRegistersAClassStringAndParams(): void
    {
        $configuration = Crucible::configure()
            ->reportFormat('custom', self::class, ['output' => 'out.txt'])
            ->build();

        $this->assertSame(
            ['class' => self::class, 'params' => ['output' => 'out.txt']],
            $configuration->reportFormats['custom'],
        );
    }

    public function testReportFormatCallingTwiceWithTheSameKeyOverridesRatherThanThrows(): void
    {
        $configuration = Crucible::configure()
            ->reportFormat('custom', self::class, ['output' => 'first.txt'])
            ->reportFormat('custom', self::class, ['output' => 'second.txt'])
            ->build();

        $this->assertSame('second.txt', $configuration->reportFormats['custom']['params']['output']);
    }

    public function testSubscriberRegistersAClassStringAndParams(): void
    {
        $configuration = Crucible::configure()
            ->subscriber('custom', self::class, ['output' => 'out.xml'])
            ->build();

        $this->assertSame(
            ['class' => self::class, 'params' => ['output' => 'out.xml']],
            $configuration->subscribers['custom'],
        );
    }

    public function testSubscriberCallingTwiceWithTheSameKeyOverridesRatherThanThrows(): void
    {
        $configuration = Crucible::configure()
            ->subscriber('custom', self::class, ['output' => 'first.xml'])
            ->subscriber('custom', self::class, ['output' => 'second.xml'])
            ->build();

        $this->assertSame('second.xml', $configuration->subscribers['custom']['params']['output']);
    }
}
