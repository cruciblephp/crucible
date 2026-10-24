<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Php;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\ProcessSetup;
use ReflectionProperty;

use function getenv;
use function putenv;
use function sys_get_temp_dir;

/**
 * A configured env value applies as PHPUnit's <env> does (D-151). Each
 * expectation is PHPUnit 13.4.3's answer, the same on 12.5.40: what the
 * environment already defines is kept unless forced, and $_SERVER is
 * never written.
 */
#[CoversClass(ProcessSetup::class)]
final class ProcessSetupEnvTest extends TestCase
{
    private const string NAME = 'CRUCIBLE_ENV_PROBE';

    /** ProcessSetup's record of what it replaced is process-global: put back, never cleared. */
    private mixed $replaced = null;

    protected function setUp(): void
    {
        $this->replaced = (new ReflectionProperty(ProcessSetup::class, 'replaced'))->getValue();
        (new ReflectionProperty(ProcessSetup::class, 'replaced'))->setValue(null, []);
        $this->clear();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ProcessSetup::class, 'replaced'))->setValue(null, $this->replaced);
        $this->clear();
    }

    public function testAnUndefinedVariableTakesTheConfiguredValue(): void
    {
        $this->apply(force: false);

        $this->assertSame(['from_config', 'from_config', null], $this->channels());
    }

    public function testAnExportedVariableIsKept(): void
    {
        $this->export('exported_real');

        $this->apply(force: false);

        $this->assertSame(['exported_real', 'exported_real', 'exported_real'], $this->channels());
    }

    public function testAnExportedEmptyVariableIsKeptToo(): void
    {
        $this->export('');

        $this->apply(force: false);

        $this->assertSame(['', '', ''], $this->channels());
    }

    public function testForceReplacesItEverywhereButServer(): void
    {
        $this->export('exported_real');

        $this->apply(force: true);

        $this->assertSame(['from_config', 'from_config', 'exported_real'], $this->channels());
    }

    /** A worker starts from the environment the user exported, and applies the configuration itself. */
    public function testAWorkerInheritsWhatWasExportedNotWhatWasConfigured(): void
    {
        $before = ProcessSetup::inheritedEnvironment();
        $this->assertNull($before);

        $this->apply(force: false);

        $inherited = ProcessSetup::inheritedEnvironment();
        $this->assertIsArray($inherited);
        $this->assertArrayNotHasKey(self::NAME, $inherited);

        (new ReflectionProperty(ProcessSetup::class, 'replaced'))->setValue(null, []);
        $this->clear();
        $this->export('exported_real');
        $this->apply(force: true);

        $forced = ProcessSetup::inheritedEnvironment();
        $this->assertSame('exported_real', $forced[self::NAME] ?? null);
    }

    private function apply(bool $force): void
    {
        $php = new Php(env: [self::NAME => 'from_config'], forcedEnv: $force ? [self::NAME => true] : []);

        ProcessSetup::phpSettings(new Configuration([], new Source(), $php), new WorkingDirectory(sys_get_temp_dir()));
    }

    /** As a parent process exports it: the environment and $_SERVER, never $_ENV under GPCS. */
    private function export(string $value): void
    {
        putenv(self::NAME . '=' . $value);
        $_SERVER[self::NAME] = $value;
    }

    /**
     * @return array{string|false, mixed, mixed} getenv(), $_ENV, $_SERVER
     */
    private function channels(): array
    {
        return [getenv(self::NAME), $_ENV[self::NAME] ?? null, $_SERVER[self::NAME] ?? null];
    }

    private function clear(): void
    {
        putenv(self::NAME);
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
    }
}
