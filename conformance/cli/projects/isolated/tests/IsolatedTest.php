<?php

declare(strict_types=1);

use LucianoPereira\Crucible\Attributes\RunInSeparateProcess;
use LucianoPereira\Crucible\Framework\TestCase;

final class IsolatedTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testWritesToStderr(): void
    {
        fwrite(STDERR, "ISOLATED-STDERR\n");
        self::assertTrue(true);
    }

    #[RunInSeparateProcess]
    public function testQuiet(): void
    {
        self::assertTrue(true);
    }
}
