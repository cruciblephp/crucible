<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Bridge\PestSnapshots;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;

/**
 * The real test file's own identity — scoped by PestBuilder around
 * each test's body, the same way CurrentTest is. Read by
 * SnapshotIdentityWrapper's generated getSnapshotDirectory()/
 * getSnapshotId() overrides.
 *
 * Necessary because Spatie\Snapshots\Concerns\SnapshotDirectoryAware/
 * SnapshotIdAware (from spatie/phpunit-snapshot-assertions) derive
 * both from ReflectionClass::getFileName()/getShortName() on the
 * running instance — meaningful for a real, file-backed class, but
 * TraitComposer::compose() generates its classes via eval(), which
 * report the *calling* file (TraitComposer.php's own location) and a
 * hash-based short name, neither connected to the actual test file.
 * Confirmed this isn't a Crucible-specific defect: real Pest's own
 * TestCaseFactory also eval()s its generated classes and hits the
 * exact same getFileName() result — real Pest's fix is that
 * spatie/pest-plugin-snapshots ships its own same-FQCN override of
 * both Concerns traits, using Pest\TestSuite::getInstance()->rootPath
 * and the test file's own basename instead of reflection. That
 * plugin package can never be installed alongside Crucible's dialect
 * (D-019 — it hard-requires pestphp/pest, like every other pest
 * plugin this bridge covers), so this reproduces the same fix by a
 * different mechanism: a real, static context PestBuilder populates
 * with the same two values (see SnapshotIdentityWrapper, the only
 * reader).
 */
final class SnapshotFileContext
{
    private static ?string $directory = null;

    private static ?string $basename = null;

    public static function set(?string $directory, ?string $basename): void
    {
        self::$directory = $directory;
        self::$basename  = $basename;
    }

    public static function directory(): string
    {
        return self::$directory ?? throw new ConfigurationException(
            'No test is currently running: Spatie\Snapshots\* needs a running test to resolve its snapshot directory.',
        );
    }

    public static function basename(): string
    {
        return self::$basename ?? throw new ConfigurationException(
            'No test is currently running: Spatie\Snapshots\* needs a running test to resolve its snapshot id.',
        );
    }
}
