<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use Throwable;

use function base64_decode;
use function base64_encode;
use function in_array;
use function is_string;
use function serialize;
use function unserialize;

/**
 * What a #[Depends] prerequisite returned, carried across the process
 * boundary. The spec does the same thing through its isolation
 * template: the child ships back the passed tests' return values, the
 * parent serializes them into the next child's input.
 *
 * The supervisor names which values it wants (`$wanted`) rather than
 * capturing every return value, because most of them are nobody's
 * dependency and a test may legitimately return something no
 * serializer can carry.
 */
final class DependencyValues
{
    /** @var array<string, string> name => base64 of the serialized value */
    private array $captured = [];

    /**
     * @param array<string, string> $incoming name => base64 of a serialized return value
     * @param list<string>          $wanted   names whose value some other unit depends on
     */
    public function __construct(private readonly array $incoming = [], private array $wanted = []) {}

    /**
     * The supervisor only knows what to ask for once it has planned the
     * units, which is after the runner it shares this with was built.
     *
     * @param list<string> $names
     */
    public function want(array $names): void
    {
        $this->wanted = [...$this->wanted, ...$names];
    }

    /**
     * The runner's per-group results map, pre-filled with what earlier
     * units already produced. Only passed tests are ever recorded, so
     * everything seeded here is a satisfied dependency.
     *
     * @return array<string, array{passed: bool, value: mixed}>
     */
    public function seed(): array
    {
        $seeded = [];

        foreach ($this->incoming as $name => $encoded) {
            $decoded = base64_decode($encoded, true);

            if ($decoded === false) {
                continue;
            }

            try {
                $value = unserialize($decoded);
            } catch (Throwable) {
                continue;
            }

            $seeded[$name] = ['passed' => true, 'value' => $value];
        }

        return $seeded;
    }

    /**
     * A value nothing asked for is not carried, and one that cannot be
     * serialized (a closure, a resource, an object holding either) is
     * dropped rather than fatal: the dependent then reports untested,
     * which is what it already did before any of this existed.
     */
    public function capture(string $name, mixed $value): void
    {
        if (!in_array($name, $this->wanted, true)) {
            return;
        }

        try {
            $this->captured[$name] = base64_encode(serialize($value));
        } catch (Throwable) {
            unset($this->captured[$name]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function provided(): array
    {
        return $this->captured;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, string>
     */
    public static function fromArray(array $decoded): array
    {
        $values = [];

        foreach ($decoded as $name => $encoded) {
            if (is_string($name) && $name !== '' && is_string($encoded)) {
                $values[$name] = $encoded;
            }
        }

        return $values;
    }
}
