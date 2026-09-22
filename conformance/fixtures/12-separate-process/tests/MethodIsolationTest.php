<?php

declare(strict_types=1);

namespace CrucibleConformance\SeparateProcess;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class MethodIsolationTest extends TestCase
{
    public static bool $polluted = false;

    public function testPollutesStaticStateInMainProcess(): void
    {
        self::$polluted = true;

        $this->assertTrue(self::$polluted);
    }

    #[RunInSeparateProcess]
    public function testSeesFreshStateInItsOwnProcess(): void
    {
        $this->assertFalse(self::$polluted);
    }

    public function testMainProcessStateSurvivesTheIsolatedTest(): void
    {
        $this->assertTrue(self::$polluted);
    }
}
