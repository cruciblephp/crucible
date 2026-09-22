<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Registry;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use ReflectionClass;

use function array_column;
use function array_key_exists;
use function array_keys;
use function array_map;
use function class_exists;
use function extension_loaded;
use function get_debug_type;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function is_subclass_of;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * The describe/list/validate logic every plugin kind's registry needs
 * (report format, subscriber, progress view) — extracted after
 * `ReportFormatRegistry` and `SubscriberRegistry` turned out identical
 * except for which attribute/contract they reflect against and the
 * noun in their error prose. `resolve()` (and report-format's extra
 * `instantiate()`) are deliberately NOT declared here: each kind
 * constructs its instance differently (zero-arg + call-time params,
 * constructor param-spread, or stream + constructor param-spread), and
 * PHP fatals on overriding an abstract method with incompatible arity
 * — nothing anywhere holds a variable typed to this base, so nothing
 * is lost by leaving `resolve()` out of it.
 *
 * `describe()`/`all()` never throw for a misconfigured entry — a
 * broken registration shows up as `available: false` with the reason
 * in `missingRequirements`, so `crucible extensions` stays usable as a
 * diagnostic even when one plugin is broken. A concrete subclass's
 * `resolve()` is the one place that throws, and only for a plugin a
 * run actually selected.
 */
abstract class AbstractRegistry
{
    /** @param array<string, array{class: class-string, params: array<string, mixed>}> $configured keyed by plugin key */
    public function __construct(protected readonly array $configured) {}

    /** @return class-string<PluginAttribute> the attribute class reflected for on a configured class */
    abstract protected function attributeClass(): string;

    /** @return class-string the interface a configured class must implement */
    abstract protected function contractClass(): string;

    /** Two-or-so-word human-readable noun for error prose, e.g. "Report format" or "Subscriber". */
    abstract protected function noun(): string;

    public function describe(string $key): PluginDescriptor
    {
        if (!isset($this->configured[$key])) {
            throw new ConfigurationException(sprintf('%s "%s" is not registered.', $this->noun(), $key));
        }

        $class = $this->configured[$key]['class'];

        if (!class_exists($class)) {
            return $this->brokenDescriptor($key, $class, [sprintf('class "%s" does not exist', $class)]);
        }

        $attribute = $this->attributeOf($class);

        if (!$attribute instanceof PluginAttribute) {
            $attributeName = (new ReflectionClass($this->attributeClass()))->getShortName();

            return $this->brokenDescriptor($key, $class, [sprintf('class "%s" has no #[%s] attribute', $class, $attributeName)]);
        }

        $problems = [];

        if (!is_subclass_of($class, $this->contractClass())) {
            $contractName = (new ReflectionClass($this->contractClass()))->getShortName();
            $problems[]   = sprintf('class "%s" does not implement %s', $class, $contractName);
        }

        foreach ($attribute->requires as $requirement) {
            if (str_starts_with($requirement, 'ext-') && !extension_loaded(substr($requirement, 4))) {
                $problems[] = $requirement;
            }
        }

        return new PluginDescriptor(
            key: $key,
            class: $class,
            description: $attribute->description,
            comment: $attribute->comment,
            requires: $attribute->requires,
            params: $attribute->params,
            priority: $attribute->priority,
            available: $problems === [],
            missingRequirements: $problems,
        );
    }

    /** @return list<PluginDescriptor> every configured plugin, regardless of availability */
    public function all(): array
    {
        return array_map($this->describe(...), array_keys($this->configured));
    }

    /** requires-check only — no params validation, no instantiation. */
    public function isAvailable(string $key): bool
    {
        return $this->describe($key)->available;
    }

    public function has(string $key): bool
    {
        return isset($this->configured[$key]);
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $problems
     */
    protected function brokenDescriptor(string $key, string $class, array $problems): PluginDescriptor
    {
        return new PluginDescriptor(
            key: $key,
            class: $class,
            description: '',
            comment: null,
            requires: [],
            params: [],
            priority: 0,
            available: false,
            missingRequirements: $problems,
        );
    }

    /** @param class-string $class */
    private function attributeOf(string $class): ?PluginAttribute
    {
        $attributes = (new ReflectionClass($class))->getAttributes($this->attributeClass());

        if ($attributes === []) {
            return null;
        }

        /** @var PluginAttribute */
        return $attributes[0]->newInstance();
    }

    /**
     * @param  array<string, mixed>  $supplied
     * @return array<string, mixed>
     */
    protected function validatedParams(string $key, PluginDescriptor $descriptor, array $supplied): array
    {
        $known = array_column($descriptor->params, 'name');

        foreach (array_keys($supplied) as $name) {
            if (!in_array($name, $known, true)) {
                throw new ConfigurationException(sprintf('%s "%s" does not accept a param named "%s".', $this->noun(), $key, $name));
            }
        }

        $result = [];

        foreach ($descriptor->params as $param) {
            $name       = $param['name'];
            $hasDefault = array_key_exists('default', $param);
            $value      = $supplied[$name] ?? ($hasDefault ? $param['default'] : null);

            if ($value === null) {
                if ($param['required']) {
                    throw new ConfigurationException(sprintf('%s "%s" is missing required param "%s".', $this->noun(), $key, $name));
                }

                continue;
            }

            $this->validateType($key, $name, $param['type'], $value);
            $result[$name] = $value;
        }

        return $result;
    }

    private function validateType(string $key, string $name, string $type, mixed $value): void
    {
        $matches = match ($type) {
            'string' => is_string($value),
            'int'    => is_int($value),
            'float'  => is_float($value) || is_int($value),
            'bool'   => is_bool($value),
            default  => true,
        };

        if (!$matches) {
            throw new ConfigurationException(sprintf(
                '%s "%s" param "%s" must be of type %s, got %s.',
                $this->noun(),
                $key,
                $name,
                $type,
                get_debug_type($value),
            ));
        }
    }
}
