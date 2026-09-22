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
use LucianoPereira\Crucible\Browser\Pumpable;
use stdClass;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;

/**
 * The protocol layer over a transport, pinned black-box (probes
 * 2026-07-16): requests are {id, guid, method, params, metadata} —
 * metadata is mandatory (the driver crashes without it); replies are
 * {id, result|error}; the remote object tree arrives as __create__ /
 * __dispose__ events rooted at guid "" and is mirrored here so
 * callers can read any object's initializer (the Playwright root
 * carries the chromium/firefox/webkit guids, pages carry their main
 * frame). Other events are ignored at this tier — the console/dialog
 * streams become a listener seam when the assertions that need them
 * land.
 */
final class Connection
{
    /**
     * The id-less events worth keeping. Health data (D-057) plus the
     * network lifecycle, which is what "has the page gone quiet?" is
     * actually asking — request/finished/failed, never response, so a
     * body that never arrives still counts as in flight.
     */
    private const array RECORDED_EVENTS = ['console', 'pageError', 'request', 'requestFinished', 'requestFailed'];

    private int $nextId = 1;

    /** @var array<string, true> guid|event pairs already subscribed */
    private array $subscriptions = [];

    /** Late-watches a Pumpable when the transport can pump (D-065). */
    public function watch(Pumpable $pump): void
    {
        if ($this->transport instanceof DriverTransport) {
            $this->transport->watch($pump);
        }
    }

    /** @var array<string, RemoteObject> */
    private array $objects = [];

    /** @var list<array{guid: string, method: string, params: array<string, mixed>}> */
    private array $pageEvents = [];

    public function __construct(
        private readonly Transport $transport,
    ) {}

    /**
     * Send one request and block until its reply, folding object
     * lifecycle events into the registry on the way.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function call(string $guid, string $method, array $params = []): array
    {
        $id = $this->nextId++;

        $this->transport->send([
            'id'       => $id,
            'guid'     => $guid,
            'method'   => $method,
            'params'   => $params === [] ? new stdClass() : $params,
            'metadata' => new stdClass(),
        ]);

        while (true) {
            $message = $this->transport->receive();

            if ($this->absorbLifecycleEvent($message)) {
                continue;
            }

            if (($message['id'] ?? null) !== $id) {
                continue; // an event or a stale reply this tier does not track
            }

            if (isset($message['error'])) {
                throw new BrowserProtocolException(sprintf(
                    '%s.%s failed: %s',
                    $guid,
                    $method,
                    $this->errorMessage($message['error']),
                ));
            }

            $result = $message['result'] ?? [];

            if (!is_array($result)) {
                return [];
            }

            /** @var array<string, mixed> $result */
            return $result;
        }
    }

    public function object(string $guid): RemoteObject
    {
        return $this->objects[$guid] ?? throw new BrowserProtocolException(sprintf('Unknown remote object %s.', $guid));
    }

    /**
     * Console/pageError events recorded for one emitter guid (they
     * arrive on the browser-context — probe-pinned).
     *
     * @return list<array{method: string, params: array<string, mixed>}>
     */
    public function pageEventsFor(string $guid): array
    {
        $events = [];

        foreach ($this->pageEvents as $event) {
            if ($event['guid'] === $guid) {
                $events[] = ['method' => $event['method'], 'params' => $event['params']];
            }
        }

        return $events;
    }

    /**
     * Ask the driver to start sending an event, at most once per
     * emitter. Several events only flow once subscribed (probe-pinned),
     * and the surfaces that need them are readonly, so remembering
     * belongs here rather than in each caller.
     */
    public function subscribe(string $guid, string $event): void
    {
        $key = $guid . '|' . $event;

        if (isset($this->subscriptions[$key])) {
            return;
        }

        $this->subscriptions[$key] = true;

        $this->call($guid, 'updateSubscription', ['event' => $event, 'enabled' => true]);
    }

    /** The first live object of a driver-side type (e.g. LocalUtils). */
    public function objectOfType(string $type): RemoteObject
    {
        foreach ($this->objects as $object) {
            if ($object->type === $type) {
                return $object;
            }
        }

        throw new BrowserProtocolException(sprintf('No remote object of type %s.', $type));
    }

    public function close(): void
    {
        $this->transport->close();
    }

    /**
     * @param array<string, mixed> $message
     */
    private function absorbLifecycleEvent(array $message): bool
    {
        if (isset($message['id']) && is_int($message['id'])) {
            return false;
        }

        $method = $message['method'] ?? null;
        $params = $message['params'] ?? null;

        if (in_array($method, self::RECORDED_EVENTS, true) && is_array($params) && is_string($message['guid'] ?? null)) {
            /** @var array<string, mixed> $params */
            $this->pageEvents[] = ['guid' => $message['guid'], 'method' => $method, 'params' => $params];

            return true;
        }

        if ($method === '__create__' && is_array($params)
            && is_string($params['guid'] ?? null) && is_string($params['type'] ?? null)) {
            $initializer = $params['initializer'] ?? [];
            /** @var array<string, mixed> $initializer */
            $initializer = is_array($initializer) ? $initializer : [];
            $parentGuid  = is_string($message['guid'] ?? null) ? $message['guid'] : '';

            $this->objects[$params['guid']] = new RemoteObject($params['guid'], $params['type'], $parentGuid, $initializer);

            return true;
        }

        if ($method === '__dispose__' && is_string($message['guid'] ?? null)) {
            unset($this->objects[$message['guid']]);

            return true;
        }

        return true; // every id-less message is an event; none others are tracked yet
    }

    private function errorMessage(mixed $error): string
    {
        if (is_array($error)) {
            $inner = $error['error'] ?? $error;

            if (is_array($inner) && is_string($inner['message'] ?? null)) {
                return $inner['message'];
            }
        }

        return (string) json_encode($error);
    }
}
