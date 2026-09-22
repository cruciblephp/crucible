<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Isolation;

use ReflectionClass;
use ReflectionProperty;

use function array_slice;
use function count;
use function get_declared_classes;
use function str_starts_with;

/**
 * Enumerates the mutable static properties eligible for backup:
 * user-defined classes only, never the engine's own state, never
 * readonly or uninitialized statics. PHP's mutable global surface is
 * enumerable — the property that makes VMVM-style cheap isolation
 * unusually tractable here (RESEARCH.md §4).
 */
final class StaticRegistry
{
    /** @var list<array{class-string, non-empty-string}> */
    private array $eligible = [];

    private int $scannedClasses = 0;

    /**
     * @return list<array{class-string, non-empty-string}> [class, property] pairs
     */
    public function eligibleProperties(): array
    {
        $declared = get_declared_classes();
        $total    = count($declared);

        // Classes are only ever appended: scan the new tail.
        foreach (array_slice($declared, $this->scannedClasses) as $class) {
            $reflection = new ReflectionClass($class);

            // Aliases appear as extra declared names: skip them (the
            // resolved class is tracked once under its real name) —
            // otherwise compat aliases would smuggle engine statics
            // past the exclusions below.
            if ($reflection->getName() !== $class) {
                continue;
            }

            if (str_starts_with($class, 'LucianoPereira\\Crucible\\') || str_starts_with($class, 'CrucibleDouble_')) {
                continue;
            }

            if (str_starts_with($class, 'Composer\\')) {
                continue;
            }

            if (!$reflection->isUserDefined() || $reflection->isEnum()) {
                continue;
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->isReadOnly()) {
                    continue;
                }

                $name = $property->getName();

                if (!$property->isInitialized()) {
                    continue;
                }

                $this->eligible[] = [$class, $name];
            }
        }

        $this->scannedClasses = $total;

        return $this->eligible;
    }
}
