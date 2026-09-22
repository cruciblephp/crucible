<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

use LucianoPereira\Crucible\Browser\BrowserProtocolException;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_string;
use function json_encode;
use function sprintf;

/**
 * The driver's value-serialization envelope for evaluateExpression,
 * pinned black-box (probe 2026-07-16): {s} string, {n} number,
 * {b} bool, {v: "undefined"|"null"}, {a: [...]} array, {o: [{k,v}]}
 * object. Handles (element/JS references) are not part of this tier.
 */
final readonly class JsValue
{
    /**
     * The "no argument" envelope every evaluateExpression sends.
     *
     * @return array<string, mixed>
     */
    public static function undefinedArgument(): array
    {
        return ['value' => ['v' => 'undefined'], 'handles' => []];
    }

    /**
     * A scalar argument envelope for evaluate calls.
     *
     * @return array<string, mixed>
     */
    public static function argument(bool|float|int|string|null $value): array
    {
        $encoded = match (true) {
            $value === null   => ['v' => 'null'],
            is_bool($value)   => ['b' => $value],
            is_string($value) => ['s' => $value],
            default           => ['n' => $value],
        };

        return ['value' => $encoded, 'handles' => []];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function decode(array $envelope): mixed
    {
        if (array_key_exists('s', $envelope)) {
            return $envelope['s'];
        }

        if (array_key_exists('n', $envelope)) {
            return $envelope['n'];
        }

        if (array_key_exists('b', $envelope)) {
            return $envelope['b'];
        }

        if (array_key_exists('v', $envelope)) {
            return null; // undefined and null both surface as PHP null
        }

        if (isset($envelope['a']) && is_array($envelope['a'])) {
            $list = [];

            foreach ($envelope['a'] as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $list[] = self::decode($item);
                }
            }

            return $list;
        }

        if (isset($envelope['o']) && is_array($envelope['o'])) {
            $object = [];

            foreach ($envelope['o'] as $entry) {
                if (is_array($entry) && is_string($entry['k'] ?? null) && is_array($entry['v'] ?? null)) {
                    /** @var array<string, mixed> $value */
                    $value               = $entry['v'];
                    $object[$entry['k']] = self::decode($value);
                }
            }

            return $object;
        }

        throw new BrowserProtocolException(sprintf(
            'Unknown value envelope: %s',
            (string) json_encode($envelope),
        ));
    }
}
