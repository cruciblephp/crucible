<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Coverage\DriverFactory;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\Process\EventParser;
use LucianoPereira\Crucible\Runner\Process\Supervisor;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Runner\TestRunner;
use ReflectionMethod;

use function dirname;
use function extension_loaded;
use function fclose;
use function is_resource;
use function proc_close;
use function proc_open;
use function stream_get_contents;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

/**
 * A coverage worker's command line names its driver the way every
 * platform resolves it.
 *
 * PHP takes `-d extension=NAME` bare and completes it against its own
 * extension_dir — `pcov` finds pcov.so on Linux and php_pcov.dll on
 * Windows. The suffixed `pcov.so` form is a Unix filename, and on
 * Windows the child answered "Unable to load dynamic library" while
 * the bare name resolves: measured with mbstring (a regular extension)
 * and Zend OPcache (the zend-extension mechanism xdebug loads
 * through), both of which this machine has loaded.
 */
#[CoversClass(Supervisor::class)]
#[CoversClass(DriverFactory::class)]
final class CoverageExtensionNamesTest extends TestCase
{
    public function testABareExtensionNameResolvesOnThisPlatform(): void
    {
        if (!extension_loaded('mbstring')) {
            self::markTestSkipped('mbstring is the stand-in for a dynamic regular extension.');
        }

        [$exit, $stdout, $stderr] = $this->php(['-d', 'extension=mbstring', '-r', 'echo "ok";']);

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('ok', $stdout);
        self::assertStringNotContainsString('Unable to load dynamic library', $stderr, 'A bare extension name must resolve against the platform\'s own extension_dir.');
    }

    public function testABareZendExtensionNameResolvesOnThisPlatform(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Zend OPcache is the stand-in for the zend-extension mechanism.');
        }

        // The already-loaded notice is the expected one: the name
        // RESOLVED, and the ini had loaded it already. The failure
        // this test exists to rule out is "Unable to load".
        [$exit, $stdout, $stderr] = $this->php(['-d', 'zend_extension=opcache', '-r', 'echo "ok";']);

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('ok', $stdout);
        self::assertStringNotContainsString('Unable to load', $stderr);
    }

    public function testTheSuffixedFormIsTheOneThatFailsOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('The .so form fails to resolve on Windows; Unix builds it can load.');
        }

        if (!extension_loaded('mbstring')) {
            self::markTestSkipped('mbstring is the stand-in for a dynamic regular extension.');
        }

        [, , $stderr] = $this->php(['-d', 'extension=mbstring.so', '-r', 'echo "ok";']);

        self::assertStringContainsString(
            'Unable to load dynamic library',
            $stderr,
            'The reproduction itself: the suffixed form is what a Windows child cannot resolve, so the fix may never drift back to it.',
        );
    }

    public function testTheDriverHelpNamesThePortableInvocation(): void
    {
        if (extension_loaded('pcov') || extension_loaded('xdebug')) {
            self::markTestSkipped('The help is written for a machine with no driver loaded.');
        }

        $branch = DriverFactory::detect(true);
        $plain  = DriverFactory::detect(false);

        self::assertIsString($branch);
        self::assertIsString($plain);

        foreach ([$branch, $plain] as $message) {
            self::assertStringContainsString('-d zend_extension=xdebug -d xdebug.mode=coverage', $message);
            self::assertStringNotContainsString('xdebug.so', $message, 'The recommended invocation must resolve on every platform.');
        }
    }

    public function testTheWorkerCoverageArgumentsCarryBareNames(): void
    {
        if (!extension_loaded('pcov') && !extension_loaded('xdebug')) {
            self::markTestSkipped('The worker arguments exist only when a driver is loaded to re-create.');
        }

        $emitter = new Emitter(new SystemClock());

        $supervisor = new Supervisor(
            $emitter,
            new TestRunner($emitter),
            new ResultCache(),
            new EventParser(),
            'crucible',
            'crucible.php',
            new WorkingDirectory(dirname(__DIR__, 3)),
            parallel: 1,
            coverageDirectory: '.crucible.cache/coverage.tmp',
        );

        $arguments = (new ReflectionMethod($supervisor, 'phpArguments'))->invoke($supervisor);

        self::assertIsArray($arguments);

        foreach ($arguments as $argument) {
            self::assertIsString($argument);
            self::assertStringNotContainsString('.so', $argument, 'A worker must be able to resolve the name it is told to load.');
        }

        self::assertContains('-d', $arguments);

        if (extension_loaded('pcov')) {
            self::assertContains('extension=pcov', $arguments);
        } else {
            self::assertContains('zend_extension=xdebug', $arguments);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function php(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('php could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }
}
