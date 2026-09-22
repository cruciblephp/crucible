<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser;

use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\Playwright\PageAssertions;

/**
 * A page state, without a browser.
 *
 * PageAssertions is a trait of thin checks over its host's reads, and
 * every one of them asks the same question: given THIS page state,
 * does the assertion fire correctly? That question does not need
 * Playwright to answer, and asking it through Playwright is why 65
 * assertions sat behind 2 tests — the oracle-backed test is slow and
 * skips wholesale wherever the oracle is not built, so the surface
 * went unmeasured on most machines.
 *
 * This is a stand-in for the PAGE, not for the driver: what a real
 * browser reports for a selector is the driver's business and the 58
 * oracle-backed browser tests own it. What is pinned here is the layer
 * above — URL decomposition, negation pairs, dot-path resolution, the
 * console-log filter — which is ordinary logic that happens to live
 * behind a browser.
 *
 * Reads answer from maps keyed exactly as the trait resolves them, so
 * a test states the selector grammar's real output rather than a
 * convenient stand-in for it.
 */
final class StandInPage
{
    use PageAssertions;

    private BrowserConfiguration $configuration;

    /** @var list<string> every target click() was called with, in order */
    public array $clicked = [];

    /** @var list<array{float, float}> every waitForNetworkIdle() call, in order */
    public array $waited = [];

    /**
     * Every expression script() was handed, in order.
     *
     * Page::script() is a protocol round trip, not a lookup, and the
     * surface leans on that: assertNoAccessibilityIssues() evaluates
     * the axe source purely for its effect on the page and discards
     * the result. A stand-in that answered from a map and did nothing
     * else would be a PURE function where the real one is not, which
     * is a different contract -- and the type gate says so.
     *
     * @var list<string>
     */
    public array $evaluated = [];

    /**
     * @param array<string, string>                    $texts      selector => textContent
     * @param array<string, string>                    $values     field => value
     * @param array<string, ?string>                   $attributes "selector\nname" => value, null = absent
     * @param array<string, mixed>                     $scripts    expression => what evaluating it yields
     * @param array<string, bool>                      $visible    resolved selector => visible
     * @param array<string, bool>                      $checked    resolved selector => checked
     * @param array<string, bool>                      $enabled    resolved selector => enabled
     * @param array<string, mixed>                     $matches    "selector\nexpression\nargument" => result
     * @param list<array{type: string, text: string}>  $consoleLogs
     * @param list<string>                             $javaScriptErrors
     */
    public function __construct(
        private string $title = '',
        private string $url = '',
        private string $content = '',
        private array $texts = [],
        private array $values = [],
        private array $attributes = [],
        private array $scripts = [],
        private array $visible = [],
        private array $checked = [],
        private array $enabled = [],
        private array $matches = [],
        private array $consoleLogs = [],
        private array $javaScriptErrors = [],
        private string $screenshot = '',
    ) {
        $this->configuration = new BrowserConfiguration();
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(string $target): string
    {
        return $this->texts[$target] ?? '';
    }

    public function content(): string
    {
        return $this->content;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function value(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    public function attribute(string $target, string $name): ?string
    {
        return $this->attributes[$target . "\n" . $name] ?? null;
    }

    public function script(string $expression): mixed
    {
        $this->evaluated[] = $expression;

        return $this->scripts[$expression] ?? null;
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    public function consoleLogs(): array
    {
        return $this->consoleLogs;
    }

    /**
     * @return list<string>
     */
    public function javaScriptErrors(): array
    {
        return $this->javaScriptErrors;
    }

    public function screenshot(): string
    {
        return $this->screenshot;
    }

    public function click(string $target): self
    {
        $this->clicked[] = $target;

        return $this;
    }

    public function waitForNetworkIdle(float $quietSeconds = 0.25, float $timeoutSeconds = 5.0): self
    {
        $this->waited[] = [$quietSeconds, $timeoutSeconds];

        return $this;
    }

    // Private on Page, so private here: the trait reaches them as its
    // own, and a public copy would be a different contract.

    private function isVisible(string $selector): bool
    {
        return $this->visible[$selector] ?? false;
    }

    private function isChecked(string $selector): bool
    {
        return $this->checked[$selector] ?? false;
    }

    private function isEnabled(string $selector): bool
    {
        return $this->enabled[$selector] ?? false;
    }

    private function overMatches(string $selector, string $expression, bool|float|int|string|null $argument = null): mixed
    {
        return $this->matches[$selector . "\n" . $expression . "\n" . $argument] ?? null;
    }
}
