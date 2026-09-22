<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\ContextOptions;
use LucianoPereira\Crucible\Browser\Device;

use function is_array;
use function is_string;
use function sprintf;

/**
 * A launched browser process on the driver side. Contexts are the
 * isolation unit (cookies, storage, emulation) — one per test is the
 * incumbent's model and will be Crucible's too. Environment simulation
 * (ContextOptions) happens here because every emulated fact — device,
 * geolocation, timezone, locale, color scheme — is a context-creation
 * parameter on the wire (probe-pinned).
 */
final readonly class Browser
{
    /** Descriptor keys that are context parameters (probe-pinned). */
    private const array DESCRIPTOR_KEYS = ['userAgent', 'viewport', 'screen', 'deviceScaleFactor', 'isMobile', 'hasTouch'];

    public function __construct(
        private Connection $connection,
        public string $guid,
        private BrowserConfiguration $configuration,
    ) {}

    public function newContext(?ContextOptions $options = null): BrowserContext
    {
        $result  = $this->connection->call($this->guid, 'newContext', $this->contextParams($options));
        $context = $result['context'] ?? null;

        if (!is_array($context) || !is_string($context['guid'] ?? null)) {
            throw new BrowserProtocolException('newContext did not return a context.');
        }

        return new BrowserContext($this->connection, $context['guid'], $this->configuration);
    }

    public function close(): void
    {
        $this->connection->call($this->guid, 'close');
    }

    /**
     * Device descriptor first, city preset over it, explicit fields
     * last — the most specific declaration wins.
     *
     * @return array<string, mixed>
     */
    private function contextParams(?ContextOptions $options): array
    {
        $params = ['noDefaultViewport' => false];

        if (!$options instanceof ContextOptions) {
            return $params;
        }

        if ($options->device instanceof Device) {
            $params = [...$params, ...$this->deviceParams($options->device)];
        }

        if ($options->from instanceof \LucianoPereira\Crucible\Browser\City) {
            $simulation            = $options->from->simulation();
            $params['geolocation'] = ['latitude' => $simulation['latitude'], 'longitude' => $simulation['longitude']];
            $params['permissions'] = ['geolocation'];
            $params['timezoneId']  = $simulation['timezone'];
            $params['locale']      = $simulation['locale'];
        }

        if ($options->darkMode !== null) {
            $params['colorScheme'] = $options->darkMode ? 'dark' : 'light';
        }

        if ($options->locale !== null) {
            $params['locale'] = $options->locale;
        }

        if ($options->timezone !== null) {
            $params['timezoneId'] = $options->timezone;
        }

        if ($options->userAgent !== null) {
            $params['userAgent'] = $options->userAgent;
        }

        if ($options->geolocation !== null) {
            [$latitude, $longitude] = $options->geolocation;
            $params['geolocation']  = ['latitude' => $latitude, 'longitude' => $longitude];
            $params['permissions']  = ['geolocation'];
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceParams(Device $device): array
    {
        $registryName = $device->registryName();

        if ($registryName === null) {
            return $device->descriptor();
        }

        $descriptors = $this->connection->objectOfType('LocalUtils')->initializer['deviceDescriptors'] ?? null;

        if (is_array($descriptors)) {
            foreach ($descriptors as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $registryName && is_array($entry['descriptor'] ?? null)) {
                    $params = [];

                    foreach (self::DESCRIPTOR_KEYS as $key) {
                        if (isset($entry['descriptor'][$key])) {
                            $params[$key] = $entry['descriptor'][$key];
                        }
                    }

                    return $params;
                }
            }
        }

        throw new BrowserProtocolException(sprintf('Device "%s" is missing from the Playwright registry.', $registryName));
    }
}
