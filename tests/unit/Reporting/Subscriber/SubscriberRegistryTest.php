<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Subscriber;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Subscriber\{SubscriberContract, SubscriberRegistry};
use LucianoPereira\Crucible\Tests\Reporting\Subscriber\Fixtures\{MissingExtensionSubscriber, NoAttributeSubscriber, NotAContractSubscriber, ValidSubscriber};

use function array_map;
use function uniqid;

#[CoversClass(SubscriberRegistry::class)]
final class SubscriberRegistryTest extends TestCase
{
    public function testDescribeReturnsMetadataForAValidSubscriber(): void
    {
        $descriptor = $this->registry(['valid' => ['class' => ValidSubscriber::class, 'params' => []]])->describe('valid');

        $this->assertSame('valid', $descriptor->key);
        $this->assertSame('A valid fixture subscriber.', $descriptor->description);
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
        $descriptor = $this->registry(['missing-ext' => ['class' => MissingExtensionSubscriber::class, 'params' => []]])->describe('missing-ext');

        $this->assertFalse($descriptor->available);
        $this->assertSame(['ext-totally-not-a-real-extension'], $descriptor->missingRequirements);
    }

    public function testDescribeIsUnavailableWhenTheClassDoesNotExist(): void
    {
        // Concatenating with uniqid()'s opaque (non-literal) return
        // keeps PHPStan from narrowing this to a literal-string type,
        // so @var can widen it to class-string below without
        // conflicting with a more specific inferred type — this class
        // deliberately doesn't exist, to exercise the class_exists() guard.
        /** @var class-string $ghostClass */
        $ghostClass = 'Nonexistent\\ClassName' . uniqid();

        $descriptor = $this->registry(['ghost' => ['class' => $ghostClass, 'params' => []]])->describe('ghost');

        $this->assertFalse($descriptor->available);
        $this->assertStringContainsString('does not exist', $descriptor->missingRequirements[0]);
    }

    public function testDescribeIsUnavailableWhenTheClassHasNoAttribute(): void
    {
        $descriptor = $this->registry(['bare' => ['class' => NoAttributeSubscriber::class, 'params' => []]])->describe('bare');

        $this->assertFalse($descriptor->available);
        $this->assertStringContainsString('no #[Subscriber] attribute', $descriptor->missingRequirements[0]);
    }

    public function testDescribeIsUnavailableWhenTheClassDoesNotImplementTheContract(): void
    {
        $descriptor = $this->registry(['bad' => ['class' => NotAContractSubscriber::class, 'params' => []]])->describe('bad');

        $this->assertFalse($descriptor->available);
        $this->assertStringContainsString('does not implement SubscriberContract', $descriptor->missingRequirements[0]);
    }

    public function testAllListsEveryConfiguredSubscriberRegardlessOfAvailability(): void
    {
        $registry = $this->registry([
            'valid'       => ['class' => ValidSubscriber::class, 'params' => []],
            'missing-ext' => ['class' => MissingExtensionSubscriber::class, 'params' => []],
        ]);

        $keys = array_map(static fn($d) => $d->key, $registry->all());

        $this->assertSame(['valid', 'missing-ext'], $keys);
    }

    public function testIsAvailableMatchesDescribe(): void
    {
        $registry = $this->registry(['valid' => ['class' => ValidSubscriber::class, 'params' => []]]);

        $this->assertTrue($registry->isAvailable('valid'));
    }

    public function testHasReflectsWhatIsConfigured(): void
    {
        $registry = $this->registry(['valid' => ['class' => ValidSubscriber::class, 'params' => []]]);

        $this->assertTrue($registry->has('valid'));
        $this->assertFalse($registry->has('nope'));
    }

    public function testResolveConstructsWithValidatedAndDefaultedParams(): void
    {
        $instance = $this->registry(['valid' => ['class' => ValidSubscriber::class, 'params' => ['output' => 'out.txt']]])->resolve('valid');

        $this->assertInstanceOf(SubscriberContract::class, $instance);
        $this->assertInstanceOf(ValidSubscriber::class, $instance);
        $this->assertSame('out.txt', $instance->output);
        $this->assertSame(80, $instance->width);
    }

    public function testResolveThrowsWhenUnavailable(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->registry(['missing-ext' => ['class' => MissingExtensionSubscriber::class, 'params' => []]])->resolve('missing-ext');
    }

    public function testResolveThrowsOnMissingRequiredParam(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->registry(['valid' => ['class' => ValidSubscriber::class, 'params' => []]])->resolve('valid');
    }

    public function testResolveThrowsOnWrongParamType(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->registry(['valid' => ['class' => ValidSubscriber::class, 'params' => ['output' => 'x', 'width' => 'not-an-int']]])->resolve('valid');
    }

    public function testResolveThrowsOnAnUnknownParam(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->registry(['valid' => ['class' => ValidSubscriber::class, 'params' => ['output' => 'x', 'bogus' => 1]]])->resolve('valid');
    }

    /** @param array<string, array{class: class-string, params: array<string, mixed>}> $configured */
    private function registry(array $configured): SubscriberRegistry
    {
        return new SubscriberRegistry($configured);
    }
}
