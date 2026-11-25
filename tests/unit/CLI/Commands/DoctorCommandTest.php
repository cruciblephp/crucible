<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI\Commands;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\Commands\DoctorCommand;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_exists;
use function file_put_contents;
use function in_array;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(DoctorCommand::class)]
final class DoctorCommandTest extends TestCase
{
    /** @var non-empty-string */
    private string $dir;

    protected function setUp(): void
    {
        Runtime::setTerminal(new FakeTerminal(interactive: false));

        $this->dir = sys_get_temp_dir() . '/crucible-doctor-' . uniqid();

        mkdir($this->dir . '/vendor/bin', 0o777, true);
        mkdir($this->dir . '/vendor/cruciblephp/crucible/phpstan', 0o777, true);
        file_put_contents($this->dir . '/vendor/bin/crucible', '');
        file_put_contents($this->dir . '/crucible.php', '<?php return null;');
        file_put_contents($this->dir . '/vendor/cruciblephp/crucible/phpstan/extension.neon', '');
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testACleanProjectHasNoProblems(): void
    {
        [$exit, $output] = $this->doctor([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('0 problems, 0 warnings', $output);
        self::assertStringContainsString('crucible.php is present', $output);
    }

    public function testADuplicatePhpUnitExtensionIsAProblem(): void
    {
        [$exit, $output] = $this->doctor(['phpstan/phpstan-phpunit', 'pestphp/pest-plugin-phpstan']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('phpstan/phpstan-phpunit duplicates', $output);
        self::assertStringContainsString('pestphp/pest-plugin-phpstan duplicates', $output);
    }

    public function testPhpStanPhpUnitIsACompanionInMigrationMode(): void
    {
        [$exit, $output] = $this->doctor(['phpunit/phpunit', 'phpstan/phpstan-phpunit']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('companion for the real PHPUnit assertions', $output);
        self::assertStringNotContainsString('duplicates Crucible', $output);
    }

    public function testLaravelIsNotToldToRemovePhpUnit(): void
    {
        [$exit, $output] = $this->doctor(['laravel/framework', 'phpunit/phpunit']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('because Laravel/testbench requires it', $output);
        self::assertStringContainsString('->phpunitCompatibility()', $output);
        self::assertStringNotContainsString('Remove it for the drop-in aliases', $output);
    }

    public function testTheTestbenchMetaPackageIsAWarning(): void
    {
        [$exit, $output] = $this->doctor(['orchestra/testbench']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('orchestra/testbench-core', $output);
        self::assertStringContainsString('0 problems, 1 warning', $output);
    }

    public function testTheExtensionIncludedTwiceIsAProblem(): void
    {
        file_put_contents($this->dir . '/phpstan.neon', "includes:\n    - vendor/cruciblephp/crucible/phpstan/extension.neon\n");

        [$exit, $output] = $this->doctor(['phpstan/extension-installer']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('PHPStan refuses a file included twice', $output);
    }

    public function testAComposerDiscoveryOptOutIsAProblem(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"extra":{"laravel":{"dont-discover":["cruciblephp/crucible"]}}}');

        [$exit, $output] = $this->doctor(['laravel/framework']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('disables package discovery', $output);
    }

    /**
     * @param list<string> $packages
     *
     * @return array{int, string}
     */
    private function doctor(array $packages): array
    {
        $installed = static fn(string $package): bool => in_array($package, $packages, true);

        $exit = (new DoctorCommand($installed))->execute(new WorkingDirectory($this->dir));

        $terminal = Runtime::terminal();
        $output   = $terminal instanceof FakeTerminal ? $terminal->output() : '';

        return [$exit, $output];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
