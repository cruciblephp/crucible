<?php

declare(strict_types=1);

namespace CrucibleConformance\Outcomes;

use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutcomesTest extends TestCase
{
    public function testPasses(): void
    {
        $this->assertSame(4, 2 + 2);
    }

    public function testFails(): void
    {
        $this->assertSame('expected', 'actual');
    }

    public function testErrors(): void
    {
        throw new RuntimeException('boom');
    }

    public function testSkips(): void
    {
        $this->markTestSkipped('not here');
    }

    public function testIncomplete(): void
    {
        $this->markTestIncomplete('later');
    }

    public function testRiskyNoAssertions(): void
    {
        // deliberately empty
    }

    #[DoesNotPerformAssertions]
    public function testDeclaredQuiet(): void
    {
        // deliberately empty, declared
    }
}
