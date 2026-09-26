<?php

declare(strict_types=1);

namespace CrucibleConformance\SeparateProcess;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function fwrite;

use const STDERR;

/**
 * What a test writes to STDERR from its own process is not lost: the
 * parent errors the test with that text as the message. Written to
 * the process's STDERR, never through an assertion, so the verdict is
 * the process runner's alone.
 */
final class StderrIsolationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testWritesToStderrFromItsOwnProcess(): void
    {
        fwrite(STDERR, 'STDERR-MARK-ISOLATED' . "\n");

        $this->assertTrue(true);
    }

    #[RunInSeparateProcess]
    public function testStaysQuietInItsOwnProcess(): void
    {
        $this->assertTrue(true);
    }
}
