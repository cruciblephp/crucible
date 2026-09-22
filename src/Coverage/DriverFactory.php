<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function extension_loaded;
use function function_exists;
use function getenv;
use function ini_get;
use function is_string;
use function str_contains;

/**
 * Runtime driver detection (D-041): pcov when loaded (fastest),
 * xdebug in coverage mode as the fallback, and — coverage being an
 * opt-in feature with real install friction — a failure message that
 * says exactly how to get a driver on each platform instead of a
 * bare "not available".
 */
final readonly class DriverFactory
{
    /**
     * The mode xdebug is ACTUALLY in, env override included.
     *
     * ⚠ `XDEBUG_MODE` takes precedence over the `xdebug.mode` ini
     * setting, and `ini_get()` does not reflect it — ✓ measured
     * 2026-09-20: under `XDEBUG_MODE=coverage` on a box whose ini says
     * `develop`, `ini_get('xdebug.mode')` still answers `develop` while
     * `xdebug_get_code_coverage()` collects perfectly.
     *
     * Reading only the ini made Crucible refuse coverage under the exact
     * command its own failure message recommends. The message was right
     * and the check was wrong.
     */
    private static function xdebugMode(): string
    {
        $environment = getenv('XDEBUG_MODE');

        return is_string($environment) && $environment !== ''
            ? $environment
            : (string) ini_get('xdebug.mode');
    }

    /**
     * @param bool $branchCoverage branch analysis requested (D-062) — xdebug territory only
     * @param bool $pathCoverage   path analysis requested — the same xdebug pass, so it implies branches
     *
     * @return CoverageDriver|non-empty-string the driver, or the reason there is none
     */
    public static function detect(bool $branchCoverage = false, bool $pathCoverage = false): CoverageDriver|string
    {
        // Paths come out of the branch-check pass, so asking for one is
        // asking for the other.
        $branchCoverage = $branchCoverage || $pathCoverage;

        if (!$branchCoverage && extension_loaded('pcov') && function_exists('pcov\collect')) {
            if ((string) ini_get('pcov.enabled') === '1') {
                return new PcovDriver();
            }

            return 'pcov is loaded but disabled. Run: php -d pcov.enabled=1 crucible --coverage';
        }

        if (extension_loaded('xdebug') && function_exists('xdebug_start_code_coverage')) {
            if (str_contains(self::xdebugMode(), 'coverage')) {
                return new XdebugDriver($branchCoverage, $pathCoverage);
            }

            return "xdebug is loaded without coverage mode. Run either:\n"
                . "  XDEBUG_MODE=coverage php crucible --coverage\n"
                . '  php -d xdebug.mode=coverage crucible --coverage';
        }

        if ($branchCoverage) {
            return ($pathCoverage ? 'path' : 'branch') . " coverage needs xdebug — pcov cannot collect branches.\n"
                . '  php -d zend_extension=xdebug.so -d xdebug.mode=coverage crucible --coverage'
                . ' (pecl install xdebug if missing)';
        }

        return "no coverage driver is loaded. Coverage needs pcov (fastest) or xdebug:\n"
            . "  Debian/Ubuntu:  apt install php-pcov   (the Sury packages carry PHP 8.5 builds)\n"
            . "  macOS/Homebrew: brew install shivammathur/extensions/pcov@8.5   (upstream pcov does not build on 8.5)\n"
            . "  any platform:   pecl install xdebug, then run with XDEBUG_MODE=coverage\n"
            . 'Load the extension on demand (php -d zend_extension=xdebug.so -d xdebug.mode=coverage crucible --coverage) to keep normal runs fast.';
    }
}
