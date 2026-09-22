<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\PhpConfigurationCheck;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_column;
use function extension_loaded;
use function in_array;

#[CoversClass(PhpConfigurationCheck::class)]
final class PhpConfigurationCheckTest extends TestCase
{
    public function testItReportsTheSpecsSettingsAndNothingElse(): void
    {
        $names = array_column(PhpConfigurationCheck::results(), 'name');

        foreach (['display_errors', 'display_startup_errors', 'error_reporting', 'zend.assertions', 'assert.exception', 'memory_limit'] as $expected) {
            $this->assertContains($expected, $names);
        }

        // A setting belonging to an absent extension is not a problem
        // anyone has, so it is left out rather than reported failing.
        $this->assertSame(
            extension_loaded('xdebug'),
            in_array('xdebug.show_exception_trace', $names, true),
        );
    }

    public function testEveryResultCarriesItsOwnVerdict(): void
    {
        foreach (PhpConfigurationCheck::results() as $result) {
            $this->assertNotSame('', $result['name']);
            $this->assertNotSame('', $result['expected']);

            // ok is the verdict on this run's actual ini value, so the
            // assertion is the shape rather than a value that depends
            // on whoever's machine runs the suite.
            $this->assertIsBool($result['ok']);
        }
    }

    public function testOnlyTheSettingsThatAreOffProduceAWarning(): void
    {
        $off = 0;

        foreach (PhpConfigurationCheck::results() as $result) {
            if (!$result['ok']) {
                $off++;
            }
        }

        $warnings = PhpConfigurationCheck::warnings();

        $this->assertCount($off, $warnings);

        foreach ($warnings as $warning) {
            $this->assertStringContainsString('PHP is not configured for development:', $warning);
        }
    }
}
