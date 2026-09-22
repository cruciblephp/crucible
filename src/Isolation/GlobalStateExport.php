<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Isolation;

use Throwable;

use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function define;
use function defined;
use function get_defined_constants;
use function in_array;
use function is_array;
use function is_string;
use function serialize;
use function unserialize;

/**
 * The parent's runtime global state, carried into an isolated worker —
 * what `#[PreserveGlobalState(true)]` asks for.
 *
 * Only the state a worker cannot already reconstruct for itself:
 * user-defined constants and globals set while the run was going. A
 * worker re-runs the configuration's bootstrap and re-applies its ini
 * settings on its own, so those are preserved by construction and
 * carrying them again would only let a stale copy win.
 *
 * Each value is serialized on its own, so a global holding a closure or
 * a resource is skipped by name rather than taking the whole export
 * down with it — the spec degrades the same way, and reports the same
 * way, because a silently missing global is worse than a named one.
 */
final readonly class GlobalStateExport
{
    /**
     * PHP owns these: a worker builds its own, and overwriting them
     * with the parent's would misdescribe the process they run in.
     */
    private const array SUPERGLOBALS = [
        'GLOBALS', '_ENV', '_POST', '_GET', '_COOKIE', '_SERVER', '_FILES', '_REQUEST',
    ];

    /**
     * @param array<string, string> $constants name => base64 of the serialized value
     * @param array<string, string> $globals   name => base64 of the serialized value
     * @param list<string>          $skipped   names no serializer could carry
     */
    private function __construct(
        public array $constants,
        public array $globals,
        public array $skipped,
    ) {}

    public static function capture(): self
    {
        $constants = [];
        $globals   = [];
        $skipped   = [];

        /** @var array<string, mixed> $defined */
        $defined = get_defined_constants(true)['user'] ?? [];

        foreach ($defined as $name => $value) {
            $encoded = self::encode($value);

            if ($encoded === null) {
                $skipped[] = 'constant ' . $name;

                continue;
            }

            $constants[$name] = $encoded;
        }

        foreach ($GLOBALS as $name => $value) {
            if (!is_string($name) || in_array($name, self::SUPERGLOBALS, true)) {
                continue;
            }

            $encoded = self::encode($value);

            if ($encoded === null) {
                $skipped[] = '$' . $name;

                continue;
            }

            $globals[$name] = $encoded;
        }

        return new self($constants, $globals, $skipped);
    }

    /**
     * Applied before the worker's bootstrap, so a bootstrap that sets
     * the same name still wins — the spec's ordering, and the one that
     * keeps a worker's own setup authoritative.
     */
    public function restore(): void
    {
        foreach ($this->constants as $name => $encoded) {
            $value = $this->decode($encoded);

            if (!defined($name) && $value !== null) {
                define($name, $value['value']);
            }
        }

        foreach ($this->globals as $name => $encoded) {
            $value = $this->decode($encoded);

            if ($value !== null) {
                $GLOBALS[$name] = $value['value'];
            }
        }
    }

    public function toJson(): string
    {
        return base64_encode(serialize(['constants' => $this->constants, 'globals' => $this->globals]));
    }

    public static function fromJson(?string $encoded): ?self
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return null;
        }

        try {
            // Suppressed: malformed input is an EXPECTED path here and
            // unserialize() warns rather than throws, so the catch below
            // never sees it. The try stays for a throwing __wakeup().
            $payload = @unserialize($decoded);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        return new self(
            self::stringMap($payload['constants'] ?? null),
            self::stringMap($payload['globals'] ?? null),
            [],
        );
    }

    /**
     * @return ?string base64 of the serialized value, or null when nothing can carry it
     */
    private static function encode(mixed $value): ?string
    {
        try {
            // Wrapped, so a legitimately-null value round-trips as a
            // value rather than as "could not decode".
            return base64_encode(serialize(['value' => $value]));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return ?array{value: mixed}
     */
    private function decode(string $encoded): ?array
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return null;
        }

        try {
            $value = unserialize($decoded);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) && array_key_exists('value', $value) ? ['value' => $value['value']] : null;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $decoded): array
    {
        $map = [];

        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            if (is_string($name) && $name !== '' && is_string($value)) {
                $map[$name] = $value;
            }
        }

        return $map;
    }
}
