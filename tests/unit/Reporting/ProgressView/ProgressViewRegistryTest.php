<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\ProgressView;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\ProgressView\{ProgressViewContract, ProgressViewRegistry};
use LucianoPereira\Crucible\Tests\Reporting\ProgressView\Fixtures\{MissingExtensionProgressView, NoAttributeProgressView, NotAContractProgressView, ValidProgressView};

use function array_map;
use function fclose;
use function fopen;
use function uniqid;

#[CoversClass(ProgressViewRegistry::class)]
final class ProgressViewRegistryTest extends TestCase
{
    public function testDescribeReturnsMetadataForAValidView(): void
    {
        $descriptor = $this->registry(['valid' => ['class' => ValidProgressView::class, 'params' => []]])->describe('valid');

        $this->assertSame('valid', $descriptor->key);
        $this->assertSame('A valid fixture progress view.', $descriptor->description);
        $this->assertSame('Used to test the happy path.', $descriptor->comment);
        $this->assertTrue($descriptor->available);
        $this->assertSame([], $descriptor->missingRequirements);
    }

    public function testDescribeUnknownKeyThrows(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->registry([])->describe('nope');
    }

    public function testDescribeIsUnavailableWhenARequiredExtensionIsMissing(): void
    {
        $descriptor = $this->registry(['missing-ext' => ['class' => MissingExtensionProgressView::class, 'params' => []]])->describe('missing-ext');

        $this->assertFalse($descriptor->available);
        $this->assertSame(['ext-totally-not-a-real-extension'], $descriptor->missingRequirements);
    }

    public function testDescribeIsUnavailableWhenTheClassDoesNotExist(): void
    {
        /** @var class-string $ghostClass */
        $ghostClass = 'Nonexistent\\ClassName' . uniqid();

        $descriptor = $this->registry(['ghost' => ['class' => $ghostClass, 'params' => []]])->describe('ghost');

        $this->assertFalse($descriptor->available);
        $this->assertStringContainsString('does not exist', $descriptor->missingRequirements[0]);
    }

    public function testDescribeIsUnavailableWhenTheClassHasNoAttribute(): void
    {
        $descriptor = $this->registry(['bare' => ['class' => NoAttributeProgressView::class, 'params' => []]])->describe('bare');

        $this->assertFalse($descriptor->available);
        $this->assertStringContainsString('no #[ProgressView] attribute', $descriptor->missingRequirements[0]);
    }

    public function testDescribeIsUnavailableWhenTheClassDoesNotImplementTheContract(): void
    {
        $descriptor = $this->registry(['bad' => ['class' => NotAContractProgressView::class, 'params' => []]])->describe('bad');

        $this->assertFalse($descriptor->available);
        $this->assertStringContainsString('does not implement ProgressViewContract', $descriptor->missingRequirements[0]);
    }

    public function testAllListsEveryConfiguredViewRegardlessOfAvailability(): void
    {
        $registry = $this->registry([
            'valid'       => ['class' => ValidProgressView::class, 'params' => []],
            'missing-ext' => ['class' => MissingExtensionProgressView::class, 'params' => []],
        ]);

        $keys = array_map(static fn($d) => $d->key, $registry->all());

        $this->assertSame(['valid', 'missing-ext'], $keys);
    }

    public function testIsAvailableMatchesDescribe(): void
    {
        $registry = $this->registry(['valid' => ['class' => ValidProgressView::class, 'params' => []]]);

        $this->assertTrue($registry->isAvailable('valid'));
    }

    public function testHasReflectsWhatIsConfigured(): void
    {
        $registry = $this->registry(['valid' => ['class' => ValidProgressView::class, 'params' => []]]);

        $this->assertTrue($registry->has('valid'));
        $this->assertFalse($registry->has('nope'));
    }

    public function testResolveConstructsWithTheGivenStreamAndValidatedParams(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        $instance = $this->registry(['valid' => ['class' => ValidProgressView::class, 'params' => ['label' => 'x']]])->resolve('valid', $stream);

        $this->assertInstanceOf(ProgressViewContract::class, $instance);
        $this->assertInstanceOf(ValidProgressView::class, $instance);
        $this->assertSame($stream, $instance->stream);
        $this->assertSame('x', $instance->label);
        $this->assertSame(80, $instance->width);

        fclose($stream);
    }

    public function testResolveThrowsWhenUnavailable(): void
    {
        $this->expectException(ConfigurationException::class);

        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        $this->registry(['missing-ext' => ['class' => MissingExtensionProgressView::class, 'params' => []]])->resolve('missing-ext', $stream);
    }

    public function testResolveThrowsOnMissingRequiredParam(): void
    {
        $this->expectException(ConfigurationException::class);

        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        $this->registry(['valid' => ['class' => ValidProgressView::class, 'params' => []]])->resolve('valid', $stream);
    }

    public function testResolveThrowsOnWrongParamType(): void
    {
        $this->expectException(ConfigurationException::class);

        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        $this->registry(['valid' => ['class' => ValidProgressView::class, 'params' => ['label' => 'x', 'width' => 'not-an-int']]])->resolve('valid', $stream);
    }

    public function testResolveThrowsOnAnUnknownParam(): void
    {
        $this->expectException(ConfigurationException::class);

        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        $this->registry(['valid' => ['class' => ValidProgressView::class, 'params' => ['label' => 'x', 'bogus' => 1]]])->resolve('valid', $stream);
    }

    /** @param array<string, array{class: class-string, params: array<string, mixed>}> $configured */
    private function registry(array $configured): ProgressViewRegistry
    {
        return new ProgressViewRegistry($configured);
    }
}
