<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use Closure;
use Composer\InstalledVersions;
use LucianoPereira\Crucible\CLI\Phpstan;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\Console\Output\CliOutput;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function sprintf;

/**
 * `crucible doctor`: the migration's current state, in one answer.
 *
 * The engine is not the hard part of leaving PHPUnit; the edges are:
 * a PHPStan extension that still pulls PHPUnit in, a Testbench meta
 * package that requires it, a phpunit.xml nobody migrated, a Laravel
 * package discovery switch that turns the bridge off. Each check names
 * the command or package that fixes it.
 *
 * Exit 1 only for a problem that stops the drop-in story; PHPUnit still
 * installed (migration mode) and a phpunit.xml still present are notes,
 * not failures.
 */
final readonly class DoctorCommand
{
    /**
     * @param ?Closure(string): bool $installed package presence, for tests
     */
    public function __construct(private ?Closure $installed = null) {}

    public function execute(WorkingDirectory $workingDirectory): int
    {
        $installed = $this->installed ?? static fn(string $package): bool => InstalledVersions::isInstalled($package);
        $problems  = 0;
        $warnings  = 0;

        $line = static function (string $level, string $message) use (&$problems, &$warnings): void {
            CliOutput::line(sprintf('  %-7s %s', $level, $message));

            if ($level === 'problem') {
                $problems++;
            }

            if ($level === 'warn') {
                $warnings++;
            }
        };

        CliOutput::line('Crucible doctor');
        CliOutput::line();

        if (is_file($workingDirectory->path . '/vendor/bin/crucible')) {
            $line('ok', 'vendor/bin/crucible is installed.');
        } else {
            $line('problem', 'vendor/bin/crucible is missing — run composer install in this project.');
        }

        if (is_file($workingDirectory->path . '/crucible.php')) {
            $line('ok', 'crucible.php is present.');
        } else {
            $line('note', 'No crucible.php — run crucible --init or crucible migrate-config.');
        }

        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            if (is_file($workingDirectory->path . '/' . $file)) {
                $line('note', $file . ' is still here — run crucible migrate-config, then remove it.');
            }
        }

        // Laravel and testbench require the real PHPUnit; telling those
        // projects to remove it breaks the framework. Migration mode is
        // the supported route, and the aliases already run Laravel's
        // own TestCase (D-139).
        $framework = $installed('laravel/framework')
            || $installed('orchestra/testbench')
            || $installed('orchestra/testbench-core');

        if ($installed('phpunit/phpunit')) {
            $line('note', $framework
                ? 'phpunit/phpunit is installed because Laravel/testbench requires it: keep it and use ->phpunitCompatibility() in migration mode.'
                : 'phpunit/phpunit is installed: Crucible is in migration mode. Keep it with ->phpunitCompatibility(), or remove it for drop-in aliases.');
        }

        if ($installed('orchestra/testbench')) {
            $line('warn', 'orchestra/testbench (the meta package) requires phpunit/phpunit; switch to orchestra/testbench-core and keep ->phpunitCompatibility().');
        } elseif ($installed('orchestra/testbench-core')) {
            $line('ok', 'orchestra/testbench-core is installed without the PHPUnit meta package.');
        }

        foreach (['phpstan/phpstan-phpunit', 'pestphp/pest-plugin-phpstan'] as $duplicate) {
            if (!$installed($duplicate)) {
                continue;
            }

            // With real PHPUnit installed, phpstan-phpunit is the
            // analyser companion for the real assertion classes, not a
            // duplicate of Crucible's extension.
            if ($duplicate === 'phpstan/phpstan-phpunit' && $installed('phpunit/phpunit')) {
                $line('note', 'phpstan/phpstan-phpunit is installed: in migration mode it is the companion for the real PHPUnit assertions; keep it.');

                continue;
            }

            $line('problem', $duplicate . ' duplicates Crucible\'s PHPStan extension — composer remove --dev ' . $duplicate);
        }

        $extension = Phpstan::extension($workingDirectory);
        $config    = Phpstan::projectConfiguration($workingDirectory);
        $installer = $installed('phpstan/extension-installer') || PhpstanNeon::installerLoadsExtension($workingDirectory);

        if ($extension === null) {
            $line('note', 'Crucible\'s PHPStan extension is not installed here.');
        } elseif (!$installer) {
            $existing = $config === null ? false : file_get_contents($workingDirectory->path . '/' . $config);

            if (!is_string($existing) || !PhpstanNeon::alreadyWired($existing, $extension)) {
                $line('note', 'Crucible\'s PHPStan extension is not wired — run crucible phpstan-init.');
            } else {
                $line('ok', 'Crucible\'s PHPStan extension is wired in ' . $config . '.');
            }
        } else {
            $existing = $config === null ? false : file_get_contents($workingDirectory->path . '/' . $config);

            if (is_string($existing) && PhpstanNeon::alreadyWired($existing, $extension)) {
                $line('problem', 'phpstan/extension-installer loads Crucible\'s extension, and ' . $config . ' includes it too — PHPStan refuses a file included twice.');
            } else {
                $line('ok', 'phpstan/extension-installer loads Crucible\'s PHPStan extension.');
            }
        }

        if ($installed('laravel/framework')) {
            $manifest = is_file($workingDirectory->path . '/composer.json')
                ? json_decode((string) file_get_contents($workingDirectory->path . '/composer.json'), true)
                : null;
            $extra        = is_array($manifest) && is_array($manifest['extra'] ?? null) ? $manifest['extra'] : [];
            $laravel      = is_array($extra['laravel'] ?? null) ? $extra['laravel'] : [];
            $dontDiscover = is_array($laravel['dont-discover'] ?? null) ? $laravel['dont-discover'] : [];

            if (in_array('cruciblephp/crucible', $dontDiscover, true)) {
                $line('problem', 'composer.json disables package discovery for cruciblephp/crucible — the artisan test bridge cannot register.');
            } else {
                $line('ok', 'Laravel detected; package discovery can register the artisan test bridge.');
            }
        }

        CliOutput::line();
        CliOutput::line(sprintf(
            '%d problem%s, %d warning%s.',
            $problems,
            $problems === 1 ? '' : 's',
            $warnings,
            $warnings === 1 ? '' : 's',
        ));

        return $problems === 0 ? 0 : 1;
    }
}
