<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\Commands\RunTestsCommand;
use LucianoPereira\Crucible\Framework\TestCase;
use ReflectionMethod;

use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * A changed directory is a gitlink (submodule): the analyser cannot see
 * inside it, so the type tier must stay rather than answer green over a
 * fraction of the suite.
 */
#[CoversClass(RunTestsCommand::class)]
final class TypesCanChangeTest extends TestCase
{
    public function testAChangedGitlinkKeepsTheTypeTier(): void
    {
        $directory = sys_get_temp_dir() . '/crucible-types-gitlink-' . uniqid();
        mkdir($directory, 0o777, true);

        try {
            $method = new ReflectionMethod(RunTestsCommand::class, 'typesCanChange');
            self::assertTrue($method->invoke(new RunTestsCommand(), [$directory], []));
        } finally {
            rmdir($directory);
        }
    }
}
