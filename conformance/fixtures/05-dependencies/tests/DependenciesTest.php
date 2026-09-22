<?php

declare(strict_types=1);

namespace CrucibleConformance\Dependencies;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class DependenciesTest extends TestCase
{
    public function testProducer(): array
    {
        $this->assertTrue(true);

        return ['token' => 42];
    }

    #[Depends('testProducer')]
    public function testConsumerReceivesValue(array $payload): void
    {
        $this->assertSame(42, $payload['token']);
    }

    public function testBrokenProducer(): void
    {
        $this->assertSame(1, 2);
    }

    #[Depends('testBrokenProducer')]
    public function testSkippedBecauseDependencyFailed(): void
    {
        $this->assertTrue(true);
    }
}
