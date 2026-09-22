<?php

declare(strict_types=1);

namespace CrucibleConformance\GlobalState;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\TestCase;

final class Ledger
{
    public static int $balance = 0;
}

#[BackupGlobals(true)]
#[BackupStaticProperties(true)]
final class GlobalStateTest extends TestCase
{
    public function testMutatesGlobals(): void
    {
        $GLOBALS['conformance_marker'] = 'dirty';
        $_ENV['CONFORMANCE_ENV']       = 'dirty';

        $this->assertSame('dirty', $GLOBALS['conformance_marker']);
    }

    public function testSeesCleanGlobals(): void
    {
        $this->assertArrayNotHasKey('conformance_marker', $GLOBALS);
        $this->assertArrayNotHasKey('CONFORMANCE_ENV', $_ENV);
    }

    public function testIncrementsStatic(): void
    {
        Ledger::$balance++;

        $this->assertSame(1, Ledger::$balance);
    }

    public function testStaticWasRestored(): void
    {
        Ledger::$balance++;

        // Each test saw the pristine value: backup restored between tests.
        $this->assertSame(1, Ledger::$balance);
    }
}
