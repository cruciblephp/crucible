<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser\Server;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\Server\CgiVariables;
use LucianoPereira\Crucible\Browser\Server\HttpRequest;
use LucianoPereira\Crucible\Framework\TestCase;

/**
 * The CGI translation the Laravel bridge used to carry inline, where a
 * mangled header name needed a booted application to observe (D-065).
 */
#[CoversClass(CgiVariables::class)]
final class CgiVariablesTest extends TestCase
{
    public function testTheMethodIsUppercasedAndTheUriKept(): void
    {
        $server = CgiVariables::forRequest(new HttpRequest('post', '/orders?page=2', []));

        self::assertSame('POST', $server['REQUEST_METHOD']);
        self::assertSame('/orders?page=2', $server['REQUEST_URI']);
    }

    public function testTheHostFallsBackToTheLoopbackWhenNoHostHeaderArrives(): void
    {
        $server = CgiVariables::forRequest(new HttpRequest('GET', '/', []));

        self::assertSame('127.0.0.1', $server['SERVER_NAME']);
        self::assertSame('127.0.0.1', $server['HTTP_HOST']);
    }

    public function testTheHostHeaderWinsWhenItArrives(): void
    {
        $server = CgiVariables::forRequest(new HttpRequest('GET', '/', ['host' => 'example.test']));

        self::assertSame('example.test', $server['HTTP_HOST']);
    }

    public function testHeadersBecomeUppercasedUnderscoredHttpVariables(): void
    {
        $server = CgiVariables::forRequest(new HttpRequest('GET', '/', [
            'x-requested-with' => 'XMLHttpRequest',
            'accept'           => 'text/html',
        ]));

        self::assertSame('XMLHttpRequest', $server['HTTP_X_REQUESTED_WITH']);
        self::assertSame('text/html', $server['HTTP_ACCEPT']);
    }

    /**
     * CGI carries the entity headers unprefixed as well: a kernel
     * reading CONTENT_TYPE would not find HTTP_CONTENT_TYPE.
     */
    public function testTheContentTypeIsCarriedBothPrefixedAndUnprefixed(): void
    {
        $server = CgiVariables::forRequest(new HttpRequest('POST', '/', ['content-type' => 'application/json'], '{}'));

        self::assertSame('application/json', $server['CONTENT_TYPE']);
        self::assertSame('application/json', $server['HTTP_CONTENT_TYPE']);
    }

    public function testAResponseHeaderKeepsItsHyphenatedTitleCasing(): void
    {
        $headers = CgiVariables::fromResponseHeaders(['x-frame-options' => ['SAMEORIGIN']]);

        self::assertSame(['X-Frame-Options' => 'SAMEORIGIN'], $headers);
    }

    /**
     * The renderer writes these from the response it is given; a second
     * copy out of the header bag would contradict it.
     */
    public function testTheHeadersTheRendererOwnsAreDropped(): void
    {
        $headers = CgiVariables::fromResponseHeaders([
            'Content-Type'   => ['text/html'],
            'content-length' => ['12'],
            'X-Ok'           => ['yes'],
        ]);

        self::assertSame(['X-Ok' => 'yes'], $headers);
    }

    public function testANonIterableOrEmptyNamedEntryIsSkippedRatherThanFatal(): void
    {
        $headers = CgiVariables::fromResponseHeaders([
            'x-good' => ['fine'],
            'x-bad'  => 'not-a-list',
            ''       => ['nameless'],
        ]);

        self::assertSame(['X-Good' => 'fine'], $headers);
    }

    public function testTheLastValueOfARepeatedHeaderWins(): void
    {
        $headers = CgiVariables::fromResponseHeaders(['set-cookie' => ['a=1', 'b=2']]);

        self::assertSame(['Set-Cookie' => 'b=2'], $headers);
    }
}
