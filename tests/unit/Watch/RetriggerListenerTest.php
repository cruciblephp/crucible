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
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Watch\RetriggerListener;

use function dirname;
use function fclose;
use function file_get_contents;
use function fread;
use function fwrite;
use function is_file;
use function json_encode;
use function microtime;
use function parse_url;
use function proc_close;
use function proc_open;
use function shell_exec;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;

use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_PORT;
use const PHP_URL_QUERY;

#[CoversClass(RetriggerListener::class)]
final class RetriggerListenerTest extends TestCase
{
    /** @var non-empty-string */
    private string $hotFile = 'not-yet-set';

    protected function setUp(): void
    {
        $this->hotFile = sys_get_temp_dir() . '/crucible-retrigger-' . uniqid() . '.hot';
    }

    protected function tearDown(): void
    {
        if (is_file($this->hotFile)) {
            unlink($this->hotFile);
        }
    }

    /**
     * Speak the documented wire format over a real socket — no client
     * abstraction, because the contract is what other producers rely on.
     */
    private function post(RetriggerListener $listener, string $url, string $body): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) parse_url($url, PHP_URL_PORT);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $client = stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $code, $error, 2);

        self::assertNotFalse($client, 'Could not reach the retrigger endpoint.');
        stream_set_blocking($client, false);

        fwrite($client, sprintf(
            "POST %s HTTP/1.1\r\nHost: %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $path,
            $host,
            strlen($body),
            $body,
        ));

        // The server is cooperative, not threaded: it only serves while
        // something pumps it. A client that blocks on the reply first
        // would deadlock — which is exactly the shape the watch loop
        // gets right by pumping inside its own select.
        $response = '';
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $listener->pump();
            $response .= (string) fread($client, 8192);

            if (str_contains($response, "\r\n\r\n")) {
                break;
            }

            usleep(10_000);
        }

        fclose($client);

        return $response;
    }

    public function testTheHotFileIsWrittenWhileListeningAndRemovedOnClose(): void
    {
        $listener = RetriggerListener::open($this->hotFile);

        self::assertInstanceOf(RetriggerListener::class, $listener, 'Expected to bind on an ephemeral port.');
        self::assertFileExists($this->hotFile);

        // The file IS the liveness signal, so it must hold the whole URL
        // a producer needs — token included, nothing else to look up.
        $published = trim((string) file_get_contents($this->hotFile));

        self::assertSame($listener->url(), $published);
        self::assertStringContainsString('127.0.0.1', $published, 'The endpoint must never leave loopback.');
        self::assertStringContainsString('token=', $published);

        $listener->close();

        self::assertFileDoesNotExist($this->hotFile, 'A stale hot file would claim a dead session is listening.');
    }

    public function testARealPushOverTheSocketReachesTheLoop(): void
    {
        $listener = RetriggerListener::open($this->hotFile);

        self::assertInstanceOf(RetriggerListener::class, $listener);

        try {
            $response = $this->post($listener, $listener->url(), '{"changed":["resources/js/Cart.vue"]}');

            self::assertStringContainsString('202', $response);

            $listener->pump();

            self::assertSame(['resources/js/Cart.vue'], $listener->take());
        } finally {
            $listener->close();
        }
    }

    public function testAPushWithTheWrongTokenIsRefusedOverTheSocket(): void
    {
        $listener = RetriggerListener::open($this->hotFile);

        self::assertInstanceOf(RetriggerListener::class, $listener);

        try {
            $url      = (string) parse_url($listener->url(), PHP_URL_PATH);
            $response = $this->post(
                $listener,
                sprintf('http://127.0.0.1:%d%s?token=wrong', (int) parse_url($listener->url(), PHP_URL_PORT), $url),
                '{"changed":["a.vue"]}',
            );

            self::assertStringContainsString('403', $response);

            $listener->pump();

            self::assertSame([], $listener->take());
        } finally {
            $listener->close();
        }
    }

    /**
     * The shipped producer, executed for real. Testing a re-implementation
     * of `integration/crucible-retrigger.mjs` would prove nothing about the
     * file people actually copy into their projects.
     */
    public function testTheShippedNodeProducerPushesThroughTheDocumentedContract(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is not installed; the shipped producer cannot be executed.');
        }

        $listener = RetriggerListener::open($this->hotFile);

        self::assertInstanceOf(RetriggerListener::class, $listener);

        $helper = dirname(__DIR__, 3) . '/integration/crucible-retrigger.mjs';

        self::assertFileExists($helper, 'The shipped producer is part of the contract.');

        try {
            $script = sprintf(
                'import { retrigger, endpoint } from %s;'
                . 'if (endpoint(%s) === null) { console.error("no endpoint"); process.exit(1); }'
                . 'const ok = await retrigger(["resources/js/Cart.vue", "resources/lang/messages.php"], { hotFile: %s });'
                . 'process.exit(ok ? 0 : 1);',
                json_encode($helper),
                json_encode($this->hotFile),
                json_encode($this->hotFile),
            );

            $process = proc_open(
                ['node', '--input-type=module', '-e', $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            self::assertIsResource($process, 'Could not start node.');

            // The push has to be served while node waits for its reply:
            // this is the same cooperative pump the watch loop performs.
            $deadline = microtime(true) + 5.0;
            $pushed   = [];

            while (microtime(true) < $deadline) {
                $listener->pump();
                $pushed = [...$pushed, ...$listener->take()];

                if ($pushed !== []) {
                    break;
                }

                usleep(20_000);
            }

            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            self::assertSame(0, $status, 'The shipped producer reported a failed push: ' . $stderr);
            self::assertSame(['resources/js/Cart.vue', 'resources/lang/messages.php'], $pushed);
        } finally {
            $listener->close();
        }
    }

    public function testAProducerFindingNoHotFileDoesNothing(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is not installed; the shipped producer cannot be executed.');
        }

        $helper = dirname(__DIR__, 3) . '/integration/crucible-retrigger.mjs';
        $absent = $this->hotFile . '-absent';

        self::assertFileDoesNotExist($absent);

        // Not listening is the normal case, never a build failure: the
        // producer must report "no" and exit cleanly.
        $script = sprintf(
            'import { retrigger, endpoint } from %s;'
            . 'const live = endpoint(%s);'
            . 'const ok = await retrigger(["a.vue"], { hotFile: %s });'
            . 'process.exit(live === null && ok === false ? 0 : 1);',
            json_encode($helper),
            json_encode($absent),
            json_encode($absent),
        );

        $process = proc_open(['node', '--input-type=module', '-e', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        self::assertIsResource($process);

        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), 'A missing hot file must be a quiet no-op.');
    }
}
