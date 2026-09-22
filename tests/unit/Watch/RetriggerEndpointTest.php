<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Watch;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Watch\RetriggerEndpoint;

use function json_encode;

#[CoversClass(RetriggerEndpoint::class)]
final class RetriggerEndpointTest extends TestCase
{
    private const string TOKEN = 'a-secret-token';

    /**
     * @param list<mixed> $changed
     */
    private function push(RetriggerEndpoint $endpoint, array $changed, string $token = self::TOKEN): int
    {
        return $endpoint->handle(new HttpRequest(
            'POST',
            '/retrigger?token=' . $token,
            [],
            (string) json_encode(['changed' => $changed]),
        ))->status;
    }

    public function testAPushedChangeSetIsAcceptedAndHandedOverOnce(): void
    {
        $endpoint = new RetriggerEndpoint(self::TOKEN);

        self::assertSame(202, $this->push($endpoint, ['resources/js/Cart.vue']));
        self::assertSame(['resources/js/Cart.vue'], $endpoint->take());

        // Draining is the point: the loop owns what it took, so the same
        // push must not run a second time on the next tick.
        self::assertSame([], $endpoint->take());
    }

    public function testTheTokenIsRequired(): void
    {
        $endpoint = new RetriggerEndpoint(self::TOKEN);

        self::assertSame(403, $this->push($endpoint, ['a.vue'], 'not-the-token'));
        self::assertSame([], $endpoint->take(), 'A refused push must leave nothing behind.');
    }

    public function testAMissingTokenIsRefused(): void
    {
        $endpoint = new RetriggerEndpoint(self::TOKEN);
        $response = $endpoint->handle(new HttpRequest('POST', '/retrigger', [], '{"changed":["a.vue"]}'));

        self::assertSame(403, $response->status);
    }

    public function testOnlyPostIsAccepted(): void
    {
        $endpoint = new RetriggerEndpoint(self::TOKEN);
        $response = $endpoint->handle(new HttpRequest('GET', '/retrigger?token=' . self::TOKEN, []));

        self::assertSame(405, $response->status);
    }

    public function testAMalformedBodyIsRefusedRatherThanGuessed(): void
    {
        $endpoint = new RetriggerEndpoint(self::TOKEN);
        $response = $endpoint->handle(new HttpRequest('POST', '/retrigger?token=' . self::TOKEN, [], 'not json'));

        self::assertSame(400, $response->status);
    }

    public function testNonStringEntriesAreDroppedNotCoerced(): void
    {
        $endpoint = new RetriggerEndpoint(self::TOKEN);

        self::assertSame(202, $this->push($endpoint, ['good.vue', 42, ['nested'], '', null, 'also-good.vue']));
        self::assertSame(['good.vue', 'also-good.vue'], $endpoint->take());
    }

    public function testThePayloadCarriesPathsAndNothingCommandShaped(): void
    {
        // The trust boundary: a caller may name files, never say what to
        // run with them. Anything but `changed` is ignored outright.
        $endpoint = new RetriggerEndpoint(self::TOKEN);
        $response = $endpoint->handle(new HttpRequest(
            'POST',
            '/retrigger?token=' . self::TOKEN,
            [],
            '{"changed":["a.vue"],"filter":"testSomething","groups":["browser"],"argv":["--update-snapshots"]}',
        ));

        self::assertSame(202, $response->status);
        self::assertSame(['a.vue'], $endpoint->take());
    }
}
