<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Metadata;

use ReflectionAttribute;
use ReflectionClass;

/**
 * Reads Crucible attributes from classes and methods via reflection.
 * Attributes only — there is no doc-comment path (ROADMAP:
 * out-of-scope). Non-Crucible attributes are ignored, with two
 * deliberate exceptions: real PHPUnit's own hook attributes
 * (#[Before]/#[PreCondition]/#[PostCondition]/#[After], translated via
 * RealPhpUnitHookAttributes, forMethod() only) and its Covers,
 * Uses, Requires, Group, Depends, DataProvider, TestWith and
 * Exclude*Backup attributes (translated via RealPhpUnitAttributes,
 * both forClass() and forMethod() — real PHPUnit allows these at
 * class level too) — everything downstream keeps matching on
 * Crucible's own attribute classes, unaware either source exists.
 */
final readonly class MetadataParser
{
    /**
     * Class-level metadata, including inherited class-level attributes
     * (own class first, then ancestors, most-derived first).
     *
     * @param class-string $className
     */
    public function forClass(string $className): MetadataCollection
    {
        $attributes = [];
        $class      = new ReflectionClass($className);

        while ($class !== false) {
            foreach ($class->getAttributes(CrucibleAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }

            foreach (RealPhpUnitAttributes::forClass($class) as $attribute) {
                $attributes[] = $attribute;
            }

            $class = $class->getParentClass();
        }

        return MetadataCollection::from(...$attributes);
    }

    /**
     * @param class-string     $className
     * @param non-empty-string $methodName
     */
    public function forMethod(string $className, string $methodName): MetadataCollection
    {
        $attributes = [];
        $method     = (new ReflectionClass($className))->getMethod($methodName);

        foreach ($method->getAttributes(CrucibleAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $attributes[] = $attribute->newInstance();
        }

        foreach (RealPhpUnitHookAttributes::forMethod($method) as $attribute) {
            $attributes[] = $attribute;
        }

        foreach (RealPhpUnitAttributes::forMethod($method) as $attribute) {
            $attributes[] = $attribute;
        }

        return MetadataCollection::from(...$attributes);
    }

    /**
     * Method metadata first, then class metadata — the precedence
     * order consumers resolve overrides in.
     *
     * @param class-string     $className
     * @param non-empty-string $methodName
     */
    public function forClassAndMethod(string $className, string $methodName): MetadataCollection
    {
        return $this->forMethod($className, $methodName)->mergedWith($this->forClass($className));
    }
}
