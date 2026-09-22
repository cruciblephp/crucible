<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Browser\BrowserNotEnabledException;
use LucianoPereira\Crucible\Browser\InertiaRecorder;
use LucianoPereira\Crucible\Browser\LivewireSnapshot;
use LucianoPereira\Crucible\Browser\Selector;
use LucianoPereira\Crucible\Snapshot\Snapshots;

use function array_key_exists;
use function explode;
use function file_get_contents;
use function hash;
use function implode;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function trim;
use function urldecode;

use const PHP_URL_FRAGMENT;
use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_PORT;
use const PHP_URL_QUERY;
use const PHP_URL_SCHEME;

/**
 * The Pest browser assertion surface (spec/pest-api.md §6), one thin
 * layer over the Page reads and the probe-pinned element-state
 * queries — every check lands in the ordinary assertion machinery, so
 * counts, failure formatting, and fail-fast behave like any other
 * assertion. Health checks (assertNoConsoleLogs / NoJavaScriptErrors /
 * NoAccessibilityIssues / assertScreenshotMatches / assertNoSmoke) are
 * B7 — they need the console event stream and the screenshot pipeline.
 */
trait PageAssertions
{
    // -- title -------------------------------------------------------------

    public function assertTitle(string $title): self
    {
        Assert::assertSame($title, $this->title(), 'Page title mismatch.');

        return $this;
    }

    public function assertTitleContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->title(), 'Page title does not contain the expected fragment.');

        return $this;
    }

    // -- text ----------------------------------------------------------------

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->text('body'), sprintf('Expected to see [%s] on the page.', $text));

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->text('body'), sprintf('Expected NOT to see [%s] on the page.', $text));

        return $this;
    }

    public function assertSeeIn(string $target, string $text): self
    {
        Assert::assertStringContainsString($text, $this->text($target), sprintf('Expected to see [%s] within [%s].', $text, $target));

        return $this;
    }

    public function assertDontSeeIn(string $target, string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->text($target), sprintf('Expected NOT to see [%s] within [%s].', $text, $target));

        return $this;
    }

    public function assertSeeAnythingIn(string $target): self
    {
        Assert::assertNotSame('', trim($this->text($target)), sprintf('Expected [%s] to contain some text.', $target));

        return $this;
    }

    public function assertSeeNothingIn(string $target): self
    {
        Assert::assertSame('', trim($this->text($target)), sprintf('Expected [%s] to contain no text.', $target));

        return $this;
    }

    public function assertCount(string $target, int $count): self
    {
        $found = $this->overMatches(Selector::resolve($target), 'els => els.length');

        Assert::assertSame($count, is_int($found) ? $found : -1, sprintf('Element count mismatch for [%s].', $target));

        return $this;
    }

    // -- script & source -------------------------------------------------------

    public function assertScript(string $expression, mixed $expected = true): self
    {
        Assert::assertSame($expected, $this->script($expression), sprintf('Script [%s] did not evaluate to the expected value.', $expression));

        return $this;
    }

    // -- Inertia (D-085) -------------------------------------------------------

    /**
     * The current Inertia page object, or null when the page is not an
     * Inertia page at all — which is itself worth distinguishing from
     * "the component did not match".
     *
     * @return ?array<string, mixed>
     */
    public function inertiaPage(): ?array
    {
        $page = $this->script(InertiaRecorder::readExpression());

        if (!is_array($page)) {
            return null;
        }

        // A decoded JSON object, narrowed by inspection rather than
        // asserted: the page object is keyed by name, and anything that
        // is not simply is not part of it.
        $named = [];

        foreach ($page as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }

        return $named;
    }

    /** The component Inertia is currently rendering. */
    public function assertInertiaComponent(string $component): self
    {
        $page = $this->inertiaPage();

        Assert::assertNotNull($page, 'The page is not an Inertia page: no recorded visit and no data-page payload.');
        Assert::assertSame(
            $component,
            $page['component'] ?? null,
            sprintf('Expected Inertia to be rendering [%s].', $component),
        );

        return $this;
    }

    /**
     * One Inertia prop, addressed with dot notation for nesting —
     * `assertInertiaProp('user.name', 'Ada')`.
     */
    public function assertInertiaProp(string $key, mixed $expected): self
    {
        $page = $this->inertiaPage();

        Assert::assertNotNull($page, 'The page is not an Inertia page: no recorded visit and no data-page payload.');

        $props = $page['props'] ?? null;
        $found = is_array($props) ? self::dotted($props, $key) : null;

        Assert::assertSame($expected, $found, sprintf('Expected the Inertia prop [%s] to match.', $key));

        return $this;
    }

    /**
     * Walks a dot-separated path, returning null the moment it leaves
     * the map — an absent prop and a null prop both read as null, and
     * the assertion message names the path either way.
     *
     * @param array<mixed, mixed> $props decoded JSON of any shape: props nest
     *                                   into lists as readily as into objects,
     *                                   which is why `users.0.name` resolves
     */
    private static function dotted(array $props, string $key): mixed
    {
        $value = $props;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    // -- Livewire (D-086) ------------------------------------------------------

    /**
     * A Livewire component's current state, read from the `wire:snapshot`
     * the component carries.
     *
     * No recorder here, and the asymmetry with Inertia is the finding
     * rather than an oversight: Livewire **rewrites** `wire:snapshot` in
     * the DOM on every round trip (observed against a real Livewire 4
     * app — the counter's snapshot went from `count:0` to `count:1`,
     * checksum and all), so the DOM is authoritative and an event
     * listener would add a second source of truth for no gain. Inertia
     * needed one precisely because it does the opposite.
     *
     * @param ?string $component name of the component to read; null = the first on the page
     *
     * @return ?array<string, mixed> null when the page carries no such component
     */
    public function wire(?string $component = null): ?array
    {
        $snapshot = $this->script(LivewireSnapshot::readExpression($component));

        if (!is_array($snapshot)) {
            return null;
        }

        $data = $snapshot['data'] ?? null;

        if (!is_array($data)) {
            return null;
        }

        $named = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }

        return $named;
    }

    /**
     * One Livewire property, dot notation for nesting — named after the
     * server-side `assertSet()` so the two tiers read alike.
     */
    public function assertWireSet(string $key, mixed $expected, ?string $component = null): self
    {
        $state = $this->wire($component);

        Assert::assertNotNull($state, 'No Livewire component with a wire:snapshot was found on the page.');
        Assert::assertSame($expected, self::dotted($state, $key), sprintf('Expected the Livewire property [%s] to match.', $key));

        return $this;
    }

    /**
     * Clicks, then waits for the update cycle it starts to land — the
     * whole point of the helper. Asserting straight after a click races
     * the round trip, which is the flake `wait(0.5)` is usually hiding.
     */
    public function wireClick(string $target, float $quietSeconds = 0.25, float $timeoutSeconds = 5.0): self
    {
        $this->click($target);

        return $this->waitForNetworkIdle($quietSeconds, $timeoutSeconds);
    }

    public function assertSourceHas(string $code): self
    {
        Assert::assertStringContainsString($code, $this->content(), sprintf('Expected the page source to contain [%s].', $code));

        return $this;
    }

    public function assertSourceMissing(string $code): self
    {
        Assert::assertStringNotContainsString($code, $this->content(), sprintf('Expected the page source NOT to contain [%s].', $code));

        return $this;
    }

    public function assertSeeLink(string $text): self
    {
        Assert::assertTrue($this->isVisible(self::linkSelector($text)), sprintf('Expected to see a link [%s].', $text));

        return $this;
    }

    public function assertDontSeeLink(string $text): self
    {
        Assert::assertFalse($this->isVisible(self::linkSelector($text)), sprintf('Expected NOT to see a link [%s].', $text));

        return $this;
    }

    // -- form state -------------------------------------------------------------

    public function assertValue(string $field, string $value): self
    {
        Assert::assertSame($value, $this->value($field), sprintf('Field [%s] value mismatch.', $field));

        return $this;
    }

    public function assertValueIsNot(string $field, string $value): self
    {
        Assert::assertNotSame($value, $this->value($field), sprintf('Field [%s] should not hold this value.', $field));

        return $this;
    }

    public function assertChecked(string $field): self
    {
        Assert::assertTrue($this->isChecked(Selector::field($field)), sprintf('Expected [%s] to be checked.', $field));

        return $this;
    }

    public function assertNotChecked(string $field): self
    {
        Assert::assertFalse($this->isChecked(Selector::field($field)), sprintf('Expected [%s] to be unchecked.', $field));

        return $this;
    }

    public function assertIndeterminate(string $field): self
    {
        $state = $this->overMatches(Selector::field($field), 'els => els[0].indeterminate');

        Assert::assertTrue($state === true, sprintf('Expected [%s] to be indeterminate.', $field));

        return $this;
    }

    public function assertRadioSelected(string $field, string $value): self
    {
        Assert::assertTrue($this->isChecked(Selector::radio($field, $value)), sprintf('Expected radio [%s=%s] to be selected.', $field, $value));

        return $this;
    }

    public function assertRadioNotSelected(string $field, string $value): self
    {
        Assert::assertFalse($this->isChecked(Selector::radio($field, $value)), sprintf('Expected radio [%s=%s] NOT to be selected.', $field, $value));

        return $this;
    }

    public function assertSelected(string $field, string $value): self
    {
        Assert::assertTrue($this->optionIsSelected($field, $value), sprintf('Expected option [%s] to be selected in [%s].', $value, $field));

        return $this;
    }

    public function assertNotSelected(string $field, string $value): self
    {
        Assert::assertFalse($this->optionIsSelected($field, $value), sprintf('Expected option [%s] NOT to be selected in [%s].', $value, $field));

        return $this;
    }

    // -- attributes ---------------------------------------------------------------

    public function assertAttribute(string $target, string $name, string $value): self
    {
        Assert::assertSame($value, $this->attribute($target, $name), sprintf('Attribute [%s] mismatch on [%s].', $name, $target));

        return $this;
    }

    public function assertAttributeMissing(string $target, string $name): self
    {
        Assert::assertNull($this->attribute($target, $name), sprintf('Expected [%s] to have no [%s] attribute.', $target, $name));

        return $this;
    }

    public function assertAttributeContains(string $target, string $name, string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->attribute($target, $name) ?? '', sprintf('Attribute [%s] on [%s] does not contain the fragment.', $name, $target));

        return $this;
    }

    public function assertAttributeDoesntContain(string $target, string $name, string $needle): self
    {
        Assert::assertStringNotContainsString($needle, $this->attribute($target, $name) ?? '', sprintf('Attribute [%s] on [%s] should not contain the fragment.', $name, $target));

        return $this;
    }

    public function assertAriaAttribute(string $target, string $name, string $value): self
    {
        return $this->assertAttribute($target, 'aria-' . $name, $value);
    }

    public function assertDataAttribute(string $target, string $name, string $value): self
    {
        return $this->assertAttribute($target, 'data-' . $name, $value);
    }

    // -- presence & interactability ---------------------------------------------------

    public function assertVisible(string $target): self
    {
        Assert::assertTrue($this->isVisible(Selector::resolve($target)), sprintf('Expected [%s] to be visible.', $target));

        return $this;
    }

    public function assertMissing(string $target): self
    {
        Assert::assertFalse($this->isVisible(Selector::resolve($target)), sprintf('Expected [%s] to be missing (not visible).', $target));

        return $this;
    }

    public function assertPresent(string $target): self
    {
        Assert::assertTrue($this->isPresent($target), sprintf('Expected [%s] to be present in the DOM.', $target));

        return $this;
    }

    public function assertNotPresent(string $target): self
    {
        Assert::assertFalse($this->isPresent($target), sprintf('Expected [%s] NOT to be present in the DOM.', $target));

        return $this;
    }

    public function assertEnabled(string $field): self
    {
        Assert::assertTrue($this->isEnabled(Selector::field($field)), sprintf('Expected [%s] to be enabled.', $field));

        return $this;
    }

    public function assertDisabled(string $field): self
    {
        Assert::assertFalse($this->isEnabled(Selector::field($field)), sprintf('Expected [%s] to be disabled.', $field));

        return $this;
    }

    public function assertButtonEnabled(string $button): self
    {
        Assert::assertTrue($this->isEnabled(Selector::resolve($button)), sprintf('Expected button [%s] to be enabled.', $button));

        return $this;
    }

    public function assertButtonDisabled(string $button): self
    {
        Assert::assertFalse($this->isEnabled(Selector::resolve($button)), sprintf('Expected button [%s] to be disabled.', $button));

        return $this;
    }

    // -- URL ------------------------------------------------------------------------

    /** Full URLs compare whole; bare paths compare the path. */
    public function assertUrlIs(string $expected): self
    {
        $actual = $this->url();

        if (str_starts_with($expected, '/')) {
            Assert::assertSame($expected, $this->urlPart(PHP_URL_PATH), 'URL path mismatch.');

            return $this;
        }

        Assert::assertSame($expected, $actual, 'URL mismatch.');

        return $this;
    }

    public function assertSchemeIs(string $scheme): self
    {
        Assert::assertSame($scheme, $this->urlPart(PHP_URL_SCHEME), 'URL scheme mismatch.');

        return $this;
    }

    public function assertSchemeIsNot(string $scheme): self
    {
        Assert::assertNotSame($scheme, $this->urlPart(PHP_URL_SCHEME), 'URL scheme should differ.');

        return $this;
    }

    public function assertHostIs(string $host): self
    {
        Assert::assertSame($host, $this->urlPart(PHP_URL_HOST), 'URL host mismatch.');

        return $this;
    }

    public function assertHostIsNot(string $host): self
    {
        Assert::assertNotSame($host, $this->urlPart(PHP_URL_HOST), 'URL host should differ.');

        return $this;
    }

    public function assertPortIs(string $port): self
    {
        Assert::assertSame($port, $this->urlPart(PHP_URL_PORT), 'URL port mismatch.');

        return $this;
    }

    public function assertPortIsNot(string $port): self
    {
        Assert::assertNotSame($port, $this->urlPart(PHP_URL_PORT), 'URL port should differ.');

        return $this;
    }

    public function assertPathIs(string $path): self
    {
        Assert::assertSame($path, $this->urlPart(PHP_URL_PATH), 'URL path mismatch.');

        return $this;
    }

    public function assertPathIsNot(string $path): self
    {
        Assert::assertNotSame($path, $this->urlPart(PHP_URL_PATH), 'URL path should differ.');

        return $this;
    }

    public function assertPathBeginsWith(string $prefix): self
    {
        Assert::assertTrue(str_starts_with($this->urlPart(PHP_URL_PATH), $prefix), sprintf('Expected the path to begin with [%s].', $prefix));

        return $this;
    }

    public function assertPathEndsWith(string $suffix): self
    {
        Assert::assertTrue(str_ends_with($this->urlPart(PHP_URL_PATH), $suffix), sprintf('Expected the path to end with [%s].', $suffix));

        return $this;
    }

    public function assertPathContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->urlPart(PHP_URL_PATH), 'URL path does not contain the fragment.');

        return $this;
    }

    public function assertQueryStringHas(string $name, ?string $value = null): self
    {
        $query = $this->queryParameters();

        Assert::assertArrayHasKey($name, $query, sprintf('Expected the query string to have [%s].', $name));

        if ($value !== null) {
            Assert::assertSame($value, $query[$name], sprintf('Query parameter [%s] mismatch.', $name));
        }

        return $this;
    }

    public function assertQueryStringMissing(string $name): self
    {
        Assert::assertArrayNotHasKey($name, $this->queryParameters(), sprintf('Expected the query string NOT to have [%s].', $name));

        return $this;
    }

    public function assertFragmentIs(string $fragment): self
    {
        Assert::assertSame($fragment, $this->urlPart(PHP_URL_FRAGMENT), 'URL fragment mismatch.');

        return $this;
    }

    public function assertFragmentIsNot(string $fragment): self
    {
        Assert::assertNotSame($fragment, $this->urlPart(PHP_URL_FRAGMENT), 'URL fragment should differ.');

        return $this;
    }

    public function assertFragmentBeginsWith(string $prefix): self
    {
        Assert::assertTrue(str_starts_with($this->urlPart(PHP_URL_FRAGMENT), $prefix), sprintf('Expected the fragment to begin with [%s].', $prefix));

        return $this;
    }

    // -- health checks (B7; semantics oracle-pinned) ------------------------------

    /**
     * Fails on `log`-type console entries — warn/info/debug/error do
     * NOT trip it (oracle-probed behavior, matched exactly).
     */
    public function assertNoConsoleLogs(): self
    {
        $entries = [];

        foreach ($this->consoleLogs() as $log) {
            if ($log['type'] === 'log') {
                $entries[] = $log['text'];
            }
        }

        Assert::assertSame([], $entries, sprintf('Expected no console logs, saw: %s', implode(' | ', $entries)));

        return $this;
    }

    /** Fails on uncaught page errors (console.error does not count). */
    public function assertNoJavaScriptErrors(): self
    {
        $errors = $this->javaScriptErrors();

        Assert::assertSame([], $errors, sprintf('Expected no JavaScript errors, saw: %s', implode(' | ', $errors)));

        return $this;
    }

    /**
     * The smoke check — the oracle-observed subset: the page produced
     * no uncaught JavaScript errors.
     */
    public function assertNoSmoke(): self
    {
        return $this->assertNoJavaScriptErrors();
    }

    /**
     * Runs axe-core (the incumbent's engine — its failure messages
     * carry axe rule links) against the page, reporting serious and
     * critical violations (oracle-pinned: moderate landmark findings
     * never appear in the incumbent's failures). The axe script is
     * loaded from the SAME node_modules that provides Playwright:
     * `npm install axe-core` — Crucible bundles no third-party code.
     */
    public function assertNoAccessibilityIssues(): self
    {
        $this->script($this->axeSource());
        $results    = $this->script('axe.run(document)');
        $violations = is_array($results) && is_array($results['violations'] ?? null) ? $results['violations'] : [];
        $lines      = [];

        foreach ($violations as $violation) {
            if (!is_array($violation)) {
                continue;
            }

            $impact = is_string($violation['impact'] ?? null) ? $violation['impact'] : 'unknown';

            if ($impact !== 'serious' && $impact !== 'critical') {
                continue;
            }

            $help    = is_string($violation['help'] ?? null) ? $violation['help'] : '';
            $url     = is_string($violation['helpUrl'] ?? null) ? $violation['helpUrl'] : '';
            $lines[] = sprintf('- [%s] %s %s', $impact, $help, $url);
        }

        Assert::assertSame([], $lines, "Accessibility issues found:\n" . implode("\n", $lines));

        return $this;
    }

    /**
     * Screenshot pinned through the snapshot engine (D-042): the PNG's
     * content hash is the snapshot value, so `--update-snapshots` is
     * the one way a new baseline gets recorded — never as a side
     * effect. The raw image is available via screenshotTo() when eyes
     * are needed.
     *
     * @param ?non-empty-string $name
     */
    public function assertScreenshotMatches(?string $name = null): self
    {
        // The screenshot flavor (D-064): hash in the .snap, reference
        // PNG stored on record, visual diff written on mismatch.
        Snapshots::matchScreenshot($this->screenshot(), $name);

        return $this;
    }

    private function axeSource(): string
    {
        $root      = $this->configuration->playwrightRoot;
        $candidate = ($root ?? '.') . '/node_modules/axe-core/axe.min.js';

        if ($root === null || !is_file($candidate)) {
            throw new BrowserNotEnabledException(
                "assertNoAccessibilityIssues needs axe-core next to the Playwright install:\n\n    npm install axe-core\n\nCrucible never bundles or downloads third-party code itself.",
            );
        }

        $source = file_get_contents($candidate);

        return $source === false ? '' : $source;
    }

    // ----------------------------------------------------------------------------

    private function isPresent(string $target): bool
    {
        return $this->overMatches(Selector::resolve($target), 'els => els.length > 0') === true;
    }

    private function optionIsSelected(string $field, string $value): bool
    {
        return $this->overMatches(
            Selector::field($field),
            '(els, v) => [...els[0].options].some(o => o.selected && o.value === v)',
            $value,
        ) === true;
    }

    private static function linkSelector(string $text): string
    {
        return sprintf('a:has-text("%s")', str_replace('"', '\\"', $text));
    }

    /** One PHP_URL_* component of the current URL, '' when absent. */
    private function urlPart(int $component): string
    {
        $part = parse_url($this->url(), $component);

        if (is_string($part)) {
            return $part;
        }

        return is_int($part) ? (string) $part : '';
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(): array
    {
        $query      = $this->urlPart(PHP_URL_QUERY);
        $parameters = [];

        if ($query === '') {
            return $parameters;
        }

        foreach (explode('&', $query) as $pair) {
            [$name, $value]               = str_contains($pair, '=') ? explode('=', $pair, 2) : [$pair, ''];
            $parameters[urldecode($name)] = urldecode($value);
        }

        return $parameters;
    }
}
