<?php

declare(strict_types=1);

namespace CrucibleConformance\Exceptions;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionsTest extends TestCase
{
    public function testExpectedClassMatches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        throw new InvalidArgumentException('nope');
    }

    public function testExpectedMessageMatches(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disk full');

        throw new RuntimeException('error: disk full!');
    }

    public function testExpectedCodeMatches(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(507);

        throw new RuntimeException('x', 507);
    }

    public function testWrongClassFails(): void
    {
        $this->expectException(LogicException::class);

        throw new RuntimeException('different type');
    }

    public function testMissedExceptionFails(): void
    {
        $this->expectException(RuntimeException::class);
        // nothing thrown
    }
}
