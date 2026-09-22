<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Fixtures\Inline;

use InvalidArgumentException;
use LucianoPereira\Crucible\Attributes\Check;
use LucianoPereira\Crucible\Attributes\Group;

use function strtoupper;

/**
 * Fixture "application source" for the inline dialect (G2c): the
 * class the checks and doctests below pretend to ship with.
 *
 * @crucible expect(Temperature::freezing()->celsius)->toBe(0.0)
 */
#[Group('inline-fixture')]
final class Temperature
{
    public function __construct(
        public float $celsius = 20.0,
    ) {}

    public static function freezing(): self
    {
        return new self(0.0);
    }

    #[Check([0.0], returns: 32.0)]
    #[Check([100.0], returns: 212.0, name: 'boiling point')]
    #[Check(['celsius' => 40.0], returns: 104.0)]
    public static function toFahrenheit(float $celsius): float
    {
        return $celsius * 9 / 5 + 32;
    }

    #[Check([-300.0], throws: InvalidArgumentException::class)]
    #[Check([21.0])]
    public static function assertPhysical(float $celsius): float
    {
        if ($celsius < -273.15) {
            throw new InvalidArgumentException('below absolute zero');
        }

        return $celsius;
    }

    /**
     * @crucible expect((new Temperature(30.0))->feels())->toBe('warm')
     * @crucible expect((new Temperature())->feels())->toBe('mild')
     */
    #[Check(returns: 'mild')]
    public function feels(): string
    {
        return match (true) {
            $this->celsius <= 10.0 => 'cold',
            $this->celsius < 25.0  => 'mild',
            default                => 'warm',
        };
    }

    // Protected on purpose: checks reach non-public targets through
    // ReflectionMethod::getClosure(), and this pins that.
    #[Check(returns: 'brr')]
    protected function whisper(): string
    {
        return 'brr';
    }
}

/** @phpcpd-keep Inline-dialect fixture: discovered by scan, never referenced. */
#[Check(['crucible'], returns: 'CRUCIBLE')]
#[Check(['-'], returns: '-')]
function shoutForInlineFixture(string $word): string
{
    return strtoupper($word);
}
