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
use LucianoPereira\Crucible\Browser\Selector;

use function base64_decode;
use function base64_encode;
use function basename;
use function defined;
use function fgetc;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_bool;
use function is_string;
use function max;
use function microtime;
use function sprintf;
use function str_contains;
use function stream_isatty;
use function usleep;

/**
 * A page, addressed through its main frame (the driver's navigation,
 * interactions, and reads are frame methods; the page's initializer
 * names its main frame — probe-pinned). The interaction surface
 * follows the Pest browser vocabulary (spec/pest-api.md §6), each
 * method one protocol call through the Selector grammar, each
 * honoring the configured wait budget. Interactions and assertions
 * return $this — the spec's tests are written as one fluent chain.
 */
final readonly class Page
{
    use PageAssertions;

    /** Attempts for a screenshot the renderer could not paint yet. */
    private const int SCREENSHOT_ATTEMPTS = 3;

    /** Pause between those attempts, in microseconds. */
    private const int SCREENSHOT_RETRY_US = 250_000;

    private string $mainFrameGuid;

    /**
     * @param ?string $scopedFrameGuid a child frame to address instead of the
     *                                 main frame — the withinFrame() surface
     *                                 (D-064); every selector and read runs
     *                                 inside that frame
     */
    public function __construct(
        private Connection $connection,
        public string $guid,
        private BrowserConfiguration $configuration,
        private string $contextGuid = '',
        private ?string $scopedFrameGuid = null,
    ) {
        $this->mainFrameGuid = $scopedFrameGuid ?? $connection->object($guid)->referencedGuid('mainFrame');

        if ($scopedFrameGuid === null) {
            // Console events flow only once subscribed (probe-pinned);
            // pageError events flow unconditionally. A frame-scoped
            // surface shares the page's subscription.
            $connection->subscribe($guid, 'console');

            // Network events are subscribed here rather than inside
            // waitForNetworkIdle() because subscribing is retroactive to
            // nothing: an action that dispatches its request first — a
            // click, a submit — would have its `request` event missed
            // entirely, and the wait would see an idle page and return
            // at once. That failure is silent, so the ordering trap is
            // removed rather than documented.
            foreach (['request', 'requestFinished', 'requestFailed'] as $event) {
                $connection->subscribe($guid, $event);
            }
        }
    }

    public function navigate(string $url): self
    {
        $this->frame('goto', ['url' => $url, 'waitUntil' => 'load']);

        return $this;
    }

    public function title(): string
    {
        return $this->stringResult($this->connection->call($this->mainFrameGuid, 'title'));
    }

    // -- interactions (Pest vocabulary) ---------------------------------

    /** Clicks by visible text, CSS, or @data-test. */
    public function click(string $target): self
    {
        $this->frame('click', ['selector' => Selector::resolve($target)]);

        return $this;
    }

    /** Presses a button (the Pest spelling for clicking buttons). */
    public function press(string $button): self
    {
        return $this->click($button);
    }

    /** Sends keyboard keys to a field ("Enter", "Control+a", ...). */
    public function keys(string $field, string ...$keys): self
    {
        foreach ($keys as $key) {
            $this->frame('press', ['selector' => Selector::field($field), 'key' => $key]);
        }

        return $this;
    }

    /** Sets a field's value (clears first — the fill semantics). */
    public function fill(string $field, string $value): self
    {
        $this->frame('fill', ['selector' => Selector::field($field), 'value' => $value]);

        return $this;
    }

    /** Pest alias of fill(). */
    public function type(string $field, string $value): self
    {
        return $this->fill($field, $value);
    }

    /** Types character by character (keyboard events per key). */
    public function typeSlowly(string $field, string $value, int $delayMs = 100): self
    {
        $this->frame('type', ['selector' => Selector::field($field), 'text' => $value, 'delay' => $delayMs]);

        return $this;
    }

    /** Appends to a field without clearing it. */
    public function append(string $field, string $value): self
    {
        $this->frame('type', ['selector' => Selector::field($field), 'text' => $value]);

        return $this;
    }

    /** Clears a field. */
    public function clear(string $field): self
    {
        return $this->fill($field, '');
    }

    public function check(string $field): self
    {
        $this->frame('check', ['selector' => Selector::field($field)]);

        return $this;
    }

    public function uncheck(string $field): self
    {
        $this->frame('uncheck', ['selector' => Selector::field($field)]);

        return $this;
    }

    /** Selects the radio input carrying the given value. */
    public function radio(string $field, string $value): self
    {
        $this->frame('check', ['selector' => Selector::radio($field, $value)]);

        return $this;
    }

    /** Selects an option by value or label. */
    public function select(string $field, string $option): self
    {
        $this->frame('selectOption', [
            'selector' => Selector::field($field),
            'options'  => [['valueOrLabel' => $option]],
        ]);

        return $this;
    }

    public function hover(string $target): self
    {
        $this->frame('hover', ['selector' => Selector::resolve($target)]);

        return $this;
    }

    /** Drags one element onto another — one dragAndDrop frame call. */
    public function drag(string $from, string $to): self
    {
        $this->frame('dragAndDrop', [
            'source' => Selector::resolve($from),
            'target' => Selector::resolve($to),
        ]);

        return $this;
    }

    /**
     * Attaches a file to a file input. The bytes travel as a wire
     * payload, read PHP-side — the incumbent sends local paths and
     * dies on a stdio driver with "localPaths are not allowed when
     * the client is not local" (probe-pinned, D-064); the payload
     * form works everywhere.
     */
    public function attach(string $field, string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new BrowserProtocolException(sprintf('attach(): cannot read "%s".', $path));
        }

        $this->frame('setInputFiles', [
            'selector' => Selector::field($field),
            'payloads' => [[
                'name'     => basename($path),
                'mimeType' => 'application/octet-stream',
                'buffer'   => base64_encode($contents),
            ]],
        ]);

        return $this;
    }

    /**
     * Holds a modifier key down around the callback — keyboardDown/Up
     * page calls; keys sent inside carry the modifier (probe-pinned:
     * CDP shows modifiers=8 on the inner key events).
     *
     * @param callable(self): mixed $callback
     */
    public function withKeyDown(string $key, callable $callback): self
    {
        $this->connection->call($this->guid, 'keyboardDown', ['key' => $key]);

        try {
            $callback($this);
        } finally {
            $this->connection->call($this->guid, 'keyboardUp', ['key' => $key]);
        }

        return $this;
    }

    /**
     * Runs the callback against a surface scoped to an iframe: same
     * vocabulary, every selector resolved inside the child frame.
     *
     * @param callable(self): mixed $callback
     */
    public function withinFrame(string $selector, callable $callback): self
    {
        $element = $this->frame('querySelector', ['selector' => Selector::resolve($selector)])['element'] ?? null;

        if (!is_array($element) || !is_string($element['guid'] ?? null)) {
            throw new BrowserProtocolException(sprintf('withinFrame(): no element matches "%s".', $selector));
        }

        $frame = $this->connection->call($element['guid'], 'contentFrame')['frame'] ?? null;

        if (!is_array($frame) || !is_string($frame['guid'] ?? null)) {
            throw new BrowserProtocolException(sprintf('withinFrame(): "%s" is not a frame.', $selector));
        }

        $callback(new self($this->connection, $this->guid, $this->configuration, $this->contextGuid, $frame['guid']));

        return $this;
    }

    /** Presses a button, then waits — the Pest two-step spelling. */
    public function pressAndWaitFor(string $button, float|int $seconds = 1): self
    {
        return $this->press($button)->wait((float) $seconds);
    }

    /**
     * Submits the page's form — a REAL submission (requestSubmit:
     * validation, submit event, serialized fields). Recorded
     * deviation (D-064): the incumbent navigates to the form's bare
     * action URL, dropping the form data (probed three ways).
     */
    public function submit(): self
    {
        $this->script('document.forms[0] && document.forms[0].requestSubmit()');

        try {
            // One settling round-trip; a submission that navigates may
            // destroy the execution context mid-call — that IS the
            // navigation happening, not a failure.
            $this->script('void 0');
        } catch (BrowserProtocolException) {
        }

        return $this;
    }

    /**
     * Blocks until a key is pressed in the TERMINAL — the incumbent's
     * headed-debugging pause. Without a TTY (CI, pipes) it returns
     * immediately: blocking forever on a headless pipeline is the one
     * thing this must never do.
     */
    public function waitForKey(): self
    {
        if (defined('STDIN') && stream_isatty(STDIN)) {
            fgetc(STDIN);
        }

        return $this;
    }

    // -- reads -----------------------------------------------------------

    /** The element's visible text. */
    public function text(string $target): string
    {
        return $this->stringResult(
            $this->frame('innerText', ['selector' => Selector::resolve($target)]),
        );
    }

    /** An attribute's value, null when absent. */
    public function attribute(string $target, string $name): ?string
    {
        $value = $this->frame('getAttribute', ['selector' => Selector::resolve($target), 'name' => $name])['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** A form field's current value. */
    public function value(string $field): string
    {
        return $this->stringResult(
            $this->frame('inputValue', ['selector' => Selector::field($field)]),
        );
    }

    /** The page's full HTML source. */
    public function content(): string
    {
        return $this->stringResult($this->connection->call($this->mainFrameGuid, 'content'));
    }

    /** The current URL, as the browser sees it. */
    public function url(): string
    {
        $url = $this->script('location.href');

        return is_string($url) ? $url : '';
    }

    /** Evaluates a JavaScript expression and returns its value. */
    public function script(string $expression): mixed
    {
        $result = $this->connection->call($this->mainFrameGuid, 'evaluateExpression', [
            'expression' => $expression,
            'isFunction' => false,
            'arg'        => JsValue::undefinedArgument(),
        ]);

        return $this->decodedValue($result);
    }

    // -- health data (B7) --------------------------------------------------

    /**
     * Console entries this page's context emitted so far. One cheap
     * protocol round-trip first drains queued events.
     *
     * @return list<array{type: string, text: string}>
     */
    public function consoleLogs(): array
    {
        $this->script('void 0');
        $logs = [];

        foreach ($this->connection->pageEventsFor($this->contextGuid) as $event) {
            if ($event['method'] === 'console') {
                $type   = $event['params']['type'] ?? '';
                $text   = $event['params']['text'] ?? '';
                $logs[] = ['type' => is_string($type) ? $type : '', 'text' => is_string($text) ? $text : ''];
            }
        }

        return $logs;
    }

    /**
     * Uncaught page errors so far.
     *
     * @return list<string>
     */
    public function javaScriptErrors(): array
    {
        $this->script('void 0');
        $errors = [];

        foreach ($this->connection->pageEventsFor($this->contextGuid) as $event) {
            if ($event['method'] === 'pageError') {
                $error    = $event['params']['error'] ?? null;
                $inner    = is_array($error) && is_array($error['error'] ?? null) ? $error['error'] : [];
                $message  = $inner['message'] ?? null;
                $errors[] = is_string($message) ? $message : 'unknown error';
            }
        }

        return $errors;
    }

    /** PNG screenshot bytes (binary travels base64 on the wire). */
    public function screenshot(): string
    {
        $result = $this->captureWithRetry();
        $binary = $result['binary'] ?? null;

        if (!is_string($binary)) {
            throw new BrowserProtocolException('screenshot returned no binary payload.');
        }

        $decoded = base64_decode($binary, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Chromium answers `Protocol error (Page.captureScreenshot): Unable
     * to capture screenshot` when the renderer cannot produce a frame.
     *
     * ✓ Measured 2026-09-16 in a full run under concurrent load, which
     * is where it fires. The session is ALIVE: it answers with a
     * structured protocol error rather than dying, which disproves the
     * previous diagnosis — "the driver connection dies under load" —
     * that this code and BrowsingTest both carried.
     *
     * ⚠ The retry is not proven to clear it. The transient appeared once
     * in roughly twenty full runs and could not be reproduced on demand:
     * with attempts forced to 1, twelve runs under CPU saturation and
     * eight concurrent Browser-suite runs all passed. So this gives the
     * capture three chances where it had one, on the reading that a
     * renderer too busy to paint will usually paint 250ms later. It is
     * not a measured cure, and BrowsingTest keeps its skip as the
     * backstop.
     *
     * Narrow on purpose: only that message retries. A closed page or a
     * wrong path is not transient and must fail on the first attempt.
     *
     * @return array<string, mixed>
     */
    private function captureWithRetry(): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->connection->call($this->guid, 'screenshot', [
                    'timeout' => $this->configuration->timeoutMs,
                    'type'    => 'png',
                ]);
            } catch (BrowserProtocolException $starved) {
                if ($attempt >= self::SCREENSHOT_ATTEMPTS
                    || !str_contains($starved->getMessage(), 'Unable to capture screenshot')
                ) {
                    throw $starved;
                }

                usleep(self::SCREENSHOT_RETRY_US);
            }
        }
    }

    /** Writes a PNG screenshot to a path and returns the path. */
    public function screenshotTo(string $path): string
    {
        file_put_contents($path, $this->screenshot());

        return $path;
    }

    // -- viewport / waiting ----------------------------------------------

    public function resize(int $width, int $height): self
    {
        $this->connection->call($this->guid, 'setViewportSize', [
            'viewportSize' => ['width' => $width, 'height' => $height],
        ]);

        return $this;
    }

    /** Waits until the target is present (the configured budget). */
    public function waitFor(string $target): self
    {
        $this->frame('waitForSelector', ['selector' => Selector::resolve($target)]);

        return $this;
    }

    /** Sleeps — an explicit, last-resort wait. */
    public function wait(float $seconds): self
    {
        usleep((int) ($seconds * 1_000_000));

        return $this;
    }

    /**
     * Waits until the page stops talking to the network — no request in
     * flight for a continuous quiet period.
     *
     * The primitive every framework round-trip needs: an Inertia visit
     * or a Livewire update is a click followed by a request cycle, and
     * asserting before it lands is the flake that `wait(0.5)` papers
     * over. Built on the driver's own request/requestFinished/
     * requestFailed events rather than a `performance` heuristic, so a
     * request that fails still ends its own wait.
     *
     * The quiet period is what makes it usable: a handler that fires a
     * request 50 ms after load would sail past a bare "nothing in
     * flight right now" check.
     *
     * @param float $quietSeconds   how long the page must stay silent to count as settled
     * @param float $timeoutSeconds how long to allow before calling it a failure — never silently
     *
     * @throws BrowserProtocolException when the page never settles
     */
    public function waitForNetworkIdle(float $quietSeconds = 0.25, float $timeoutSeconds = 5.0): self
    {
        foreach (['request', 'requestFinished', 'requestFailed'] as $event) {
            $this->connection->subscribe($this->guid, $event);
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $quietAt  = null;

        while (true) {
            // The cheap round-trip that drains whatever the driver
            // queued — the same idiom consoleLogs() uses.
            $this->script('void 0');

            $inFlight = $this->inFlightRequests();

            if ($inFlight === 0) {
                $quietAt ??= microtime(true);

                if (microtime(true) - $quietAt >= $quietSeconds) {
                    return $this;
                }
            } else {
                $quietAt = null;
            }

            if (microtime(true) >= $deadline) {
                throw new BrowserProtocolException(sprintf(
                    'The page never went quiet: %d request(s) still in flight after %.1fs.',
                    $inFlight,
                    $timeoutSeconds,
                ));
            }

            usleep(20_000);
        }
    }

    /**
     * Requests started but neither finished nor failed. Network events
     * arrive on the browser context, not the page — probe-pinned, the
     * same asymmetry consoleLogs() lives with.
     */
    private function inFlightRequests(): int
    {
        $started = 0;
        $ended   = 0;

        foreach ($this->connection->pageEventsFor($this->contextGuid) as $event) {
            if ($event['method'] === 'request') {
                ++$started;
            } elseif ($event['method'] === 'requestFinished' || $event['method'] === 'requestFailed') {
                ++$ended;
            }
        }

        return max(0, $started - $ended);
    }

    // -- element state (probe-pinned frame queries) ------------------------

    /** Visible right now — no waiting (the probe-pinned no-timeout call). */
    private function isVisible(string $selector): bool
    {
        return $this->boolValue($this->connection->call($this->mainFrameGuid, 'isVisible', ['selector' => $selector]));
    }

    private function isChecked(string $selector): bool
    {
        return $this->boolValue($this->frame('isChecked', ['selector' => $selector]));
    }

    private function isEnabled(string $selector): bool
    {
        return $this->boolValue($this->frame('isEnabled', ['selector' => $selector]));
    }

    /**
     * Runs `expression(elements, argument)` over every match — the
     * driver's evalOnSelectorAll, no waiting, zero matches allowed.
     */
    private function overMatches(string $selector, string $expression, bool|float|int|string|null $argument = null): mixed
    {
        $result = $this->connection->call($this->mainFrameGuid, 'evalOnSelectorAll', [
            'selector'   => $selector,
            'expression' => $expression,
            'isFunction' => true,
            'arg'        => JsValue::argument($argument),
        ]);

        return $this->decodedValue($result);
    }

    // ----------------------------------------------------------------------

    /**
     * One frame call with the configured wait budget attached.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function frame(string $method, array $params): array
    {
        return $this->connection->call($this->mainFrameGuid, $method, [...$params, 'timeout' => $this->configuration->timeoutMs]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function stringResult(array $result): string
    {
        $value = $result['value'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function boolValue(array $result): bool
    {
        $value = $result['value'] ?? null;

        return is_bool($value) && $value;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function decodedValue(array $result): mixed
    {
        $value = $result['value'] ?? null;

        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return JsValue::decode($value);
    }
}
