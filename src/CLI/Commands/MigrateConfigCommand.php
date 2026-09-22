<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Configuration\XmlMigrator;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function printf;

use const PHP_EOL;

/**
 * `crucible migrate-config`: the one sanctioned phpunit.xml read
 * (DESIGN.md D-024) — convert once, then the XML file can be
 * deleted. Refuses to overwrite an existing crucible.php and names
 * everything it could not migrate.
 */
final class MigrateConfigCommand
{
    public function execute(WorkingDirectory $workingDirectory): int
    {
        if ((new Loader())->exists($workingDirectory)) {
            print 'A crucible.php (or crucible.dist.php) already exists in this directory.' . PHP_EOL;

            return 1;
        }

        $xmlPath = null;

        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            if (is_file($workingDirectory->path . '/' . $candidate)) {
                $xmlPath = $workingDirectory->path . '/' . $candidate;

                break;
            }
        }

        if ($xmlPath === null) {
            print 'No phpunit.xml or phpunit.xml.dist found in this directory.' . PHP_EOL;

            return 1;
        }

        $xml = file_get_contents($xmlPath);

        if ($xml === false) {
            printf('Cannot read %s.' . PHP_EOL, $xmlPath);

            return 1;
        }

        try {
            $result = (new XmlMigrator())->migrate($xml);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $path = $workingDirectory->path . '/crucible.php';

        if (file_put_contents($path, $result->code) === false) {
            printf('Cannot write %s.' . PHP_EOL, $path);

            return 1;
        }

        printf('Created %s from %s.' . PHP_EOL, $path, $xmlPath);

        if ($result->notes !== []) {
            print PHP_EOL . 'Not migrated (no Crucible equivalent):' . PHP_EOL;

            foreach ($result->notes as $note) {
                print '  - ' . $note . PHP_EOL;
            }
        }

        return 0;
    }
}
