<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Generated\GeneratedCode;
use ReflectionClass;
use ReflectionProperty;

use function array_unique;
use function array_values;
use function class_exists;
use function implode;
use function md5;
use function sort;
use function sprintf;
use function strtolower;
use function var_export;

/**
 * pest()->use(Trait::class) needs the trait mixed into the binding
 * class, and PHP has no runtime trait application — so a subclass is
 * generated once per (class, traits) combination and cached, the
 * same eval-once pattern the doubles generator uses (D-017).
 */
final class TraitComposer
{
    /** @var array<string, class-string> */
    private static array $composed = [];

    /**
     * @param class-string       $class
     * @param list<class-string> $traits
     *
     * @return class-string
     */
    public static function compose(string $class, array $traits): string
    {
        $traits = array_values(array_unique($traits));

        if ($traits === []) {
            return $class;
        }

        sort($traits);

        $key = $class . '|' . implode(',', $traits);

        if (isset(self::$composed[$key])) {
            return self::$composed[$key];
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isFinal()) {
            throw new ConfigurationException(sprintf(
                'Cannot mix traits into %s: the class is final.',
                $class,
            ));
        }

        // Both checks below refuse what PHP would refuse anyway. The
        // point is not the verdict, which is identical, but that an
        // uncatchable compile fatal has no exit code and no message of
        // ours: ✓ measured, the collision died at 255 naming
        // ComposedTestCase_<md5> and "eval()'d code" — Crucible's
        // internals, for a mistake in the user's uses() call. The
        // incumbent does not do that either: ✓ pest 5.1.1 catches the
        // same fatal in a shutdown handler and reports it at exit 1.
        // Refusing before eval() is what makes a chosen exit code and a
        // message about the user's own file possible at all.
        self::guardMethods($reflection, $traits);
        self::guardProperties($reflection, $traits);

        $short = 'ComposedTestCase_' . md5($key);
        $fqcn  = __NAMESPACE__ . '\\Composed\\' . $short;

        if (!class_exists($fqcn, false)) {
            GeneratedCode::evaluate(
                sprintf(
                    'namespace %s\\Composed; class %s extends \\%s { use \\%s; }',
                    __NAMESPACE__,
                    $short,
                    $class,
                    implode(', \\', $traits),
                ),
                $class . ' composed with ' . implode(', ', $traits),
            );
        }

        /** @var class-string $fqcn */
        return self::$composed[$key] = $fqcn;
    }
    /**
     * Everything about the METHODS that PHP would refuse, in one pass
     * over them.
     *
     * Three rules read the same reflection — a collision between two
     * traits, a trait bringing a method the base sealed, and an
     * abstract requirement nobody answers — so they share the walk
     * rather than each opening their own. What differs between them is
     * the question, not the data.
     *
     * @param ReflectionClass<object> $reflection
     * @param list<class-string>      $traits
     */
    private static function guardMethods(ReflectionClass $reflection, array $traits): void
    {
        $sealed   = [];
        $provided = [];
        $required = [];

        foreach ($reflection->getMethods() as $method) {
            $name = strtolower($method->getName());

            if ($method->isFinal()) {
                $sealed[$name] = $method->getDeclaringClass()->getName();
            }

            if ($method->isAbstract()) {
                $required[$name] = $reflection->getName() . '::' . $method->getName() . '()';

                continue;
            }

            $provided[$name] = true;
        }

        /** @var array<string, class-string> $declaredBy method name => the trait that brought it */
        $declaredBy = [];

        foreach ($traits as $trait) {
            foreach ((new ReflectionClass($trait))->getMethods() as $method) {
                $name = strtolower($method->getName());

                // An abstract trait method is a REQUIREMENT, not a
                // rival declaration: ✓ measured, an abstract greeting()
                // beside a concrete one composes.
                if ($method->isAbstract()) {
                    $required[$name] ??= $trait . '::' . $method->getName() . '()';

                    continue;
                }

                if (isset($declaredBy[$name])) {
                    throw new ConfigurationException(sprintf(
                        'Cannot mix %s and %s together: both declare %s(), and PHP resolves that only with '
                            . 'an insteadof clause, which uses() cannot write. Bind one of them, or rename '
                            . 'the method.',
                        $declaredBy[$name],
                        $trait,
                        $method->getName(),
                    ));
                }

                if (isset($sealed[$name])) {
                    throw new ConfigurationException(sprintf(
                        'Cannot mix %s into %s: it declares %s(), which %s declares final.',
                        $trait,
                        $reflection->getName(),
                        $method->getName(),
                        $sealed[$name],
                    ));
                }

                $declaredBy[$name] = $trait;
                $provided[$name]   = true;
            }
        }

        $unimplemented = [];

        foreach ($required as $name => $spelling) {
            if (!isset($provided[$name])) {
                $unimplemented[] = $spelling;
            }
        }

        if ($unimplemented !== []) {
            throw new ConfigurationException(sprintf(
                'Cannot mix traits into %s: nothing here implements %s, and the composed class is '
                    . 'concrete, which PHP refuses.',
                $reflection->getName(),
                implode(', ', $unimplemented),
            ));
        }
    }

    /**
     * A property may be declared twice only if the two declarations are
     * IDENTICAL.
     *
     * ✓ Measured: same name and type but a different default is fatal,
     * so is a different visibility, so is a different type — against
     * another trait or against the base. Identical is fine, which is
     * why the comparison is a signature rather than a name.
     *
     * @param ReflectionClass<object> $reflection
     * @param list<class-string>      $traits
     */
    private static function guardProperties(ReflectionClass $reflection, array $traits): void
    {
        /** @var array<string, array{0: string, 1: string}> $seen name => [signature, who declared it] */
        $seen = [];

        foreach ($reflection->getProperties() as $property) {
            $seen[$property->getName()] = [
                self::propertySignature($property),
                $property->getDeclaringClass()->getName(),
            ];
        }

        foreach ($traits as $trait) {
            foreach ((new ReflectionClass($trait))->getProperties() as $property) {
                $signature = self::propertySignature($property);
                $known     = $seen[$property->getName()] ?? null;

                if ($known === null) {
                    $seen[$property->getName()] = [$signature, $trait];

                    continue;
                }

                if ($known[0] !== $signature) {
                    throw new ConfigurationException(sprintf(
                        'Cannot mix %s into %s: it declares $%s as "%s", and %s declares it as "%s". PHP '
                            . 'allows the same property twice only when the two declarations are identical.',
                        $trait,
                        $reflection->getName(),
                        $property->getName(),
                        $signature,
                        $known[1],
                        $known[0],
                    ));
                }
            }
        }
    }

    /**
     * What has to match, spelled out: visibility, static, readonly,
     * type, and the default.
     */
    private static function propertySignature(ReflectionProperty $property): string
    {
        $visibility = match (true) {
            $property->isPrivate()   => 'private',
            $property->isProtected() => 'protected',
            default                  => 'public',
        };

        return implode(' ', [
            $visibility,
            $property->isStatic() ? 'static' : 'instance',
            $property->isReadOnly() ? 'readonly' : 'mutable',
            (string) $property->getType(),
            $property->hasDefaultValue() ? var_export($property->getDefaultValue(), true) : '<no default>',
        ]);
    }

}
