<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The pest dialect's global vocabulary. Loaded only when a
 * *.pest.php file is about to be built (never implicitly), each
 * function guarded by function_exists, and refused outright when the
 * real pestphp/pest is installed — the D-019 coexistence principle:
 * never mask a real package silently.
 */

use LucianoPereira\Crucible\Architecture\ArchRule;
use LucianoPereira\Crucible\Browser\Browsing;
use LucianoPereira\Crucible\Browser\ContextOptions;
use LucianoPereira\Crucible\Browser\PageCollection;
use LucianoPereira\Crucible\Browser\Playwright\Page;
use LucianoPereira\Crucible\Dialect\Pest\DescribeCall;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Dialect\Pest\PestRegistry;
use LucianoPereira\Crucible\Dialect\Pest\PestScopes;
use LucianoPereira\Crucible\Dialect\Pest\ScopeRegistration;
use LucianoPereira\Crucible\Dialect\Pest\TestCall;

if (!\function_exists('test')) {
    /** @param non-empty-string $description */
    function test(string $description, ?Closure $closure = null): TestCall
    {
        return PestRegistry::test($description, $closure, 'test');
    }
}

if (!\function_exists('it')) {
    /**
     * @param non-empty-string $description
     *
     * @phpcpd-keep The user-facing half of the Pest vocabulary: called from
     * .pest.php files, never from src/, so no internal reference can exist. It
     * is reported as a POSSIBLE orphan rather than a certain one because
     * PestFileSniffer names it in a string literal while sniffing for exactly
     * this vocabulary — which is the reference, spelled the only way a sniffer
     * can spell it.
     */
    function it(string $description, ?Closure $closure = null): TestCall
    {
        return PestRegistry::test($description, $closure, 'it');
    }
}

if (!\function_exists('todo')) {
    /**
     * A test that is planned but not written. Body-less by
     * construction — a todo with a body is `test()->todo()`.
     *
     * @param non-empty-string $description
     */
    function todo(string $description, ?string $assignee = null, string|int|null $issue = null, ?string $note = null): TestCall
    {
        return PestRegistry::test($description, null, 'test')->todo($assignee, $issue, $note);
    }
}

if (!\function_exists('describe')) {
    /** @param non-empty-string $description */
    function describe(string $description, Closure $body): DescribeCall
    {
        return PestRegistry::describe($description, $body);
    }
}

if (!\function_exists('beforeEach')) {
    function beforeEach(Closure $hook): void
    {
        PestRegistry::beforeEach($hook);
    }
}

if (!\function_exists('afterEach')) {
    function afterEach(Closure $hook): void
    {
        PestRegistry::afterEach($hook);
    }
}

if (!\function_exists('beforeAll')) {
    function beforeAll(Closure $hook): void
    {
        PestRegistry::beforeAll($hook);
    }
}

if (!\function_exists('afterAll')) {
    function afterAll(Closure $hook): void
    {
        PestRegistry::afterAll($hook);
    }
}

if (!\function_exists('covers')) {
    /** @param non-empty-string ...$targets classes, interfaces, traits or functions under test */
    function covers(string ...$targets): void
    {
        PestRegistry::covers(...$targets);
    }
}

if (!\function_exists('mutates')) {
    /** @param non-empty-string ...$targets classes `crucible mutate` should narrow to */
    function mutates(string ...$targets): void
    {
        PestRegistry::mutates(...$targets);
    }
}

if (!\function_exists('fixture')) {
    /**
     * @param non-empty-string $path relative to the nearest Fixtures/ directory
     *
     * @return non-empty-string
     */
    function fixture(string $path): string
    {
        return PestRegistry::fixture($path);
    }
}

if (!\function_exists('uses')) {
    /** @param class-string ...$names classes and traits, mixed */
    function uses(string ...$names): ScopeRegistration
    {
        return PestRegistry::uses(...$names);
    }
}

if (!\function_exists('pest')) {
    function pest(): ScopeRegistration
    {
        return PestScopes::pest();
    }
}

if (!\function_exists('dataset')) {
    /**
     * @param non-empty-string                   $name
     * @param iterable<array-key, mixed>|Closure $rows
     */
    function dataset(string $name, iterable|Closure $rows): void
    {
        PestScopes::dataset($name, $rows);
    }
}

if (!\function_exists('expect')) {
    /**
     * Typed for the analyser by ExpectFunctionReturnTypeExtension (D-128):
     * `Expectation<typeof $value>`.
     */
    function expect(mixed $value): Expectation
    {
        return new Expectation($value);
    }
}

if (!\function_exists('visit')) {
    /**
     * Browser testing (spec/pest-api.md §6): a navigated page in a
     * fresh browser context — or one page per URL behind the fan-out
     * surface for a list (D-064). The tier is off by default —
     * enabling it is an explicit crucible.php decision (`->browser()`),
     * and `->browser(enabled: false)` makes every visiting test skip.
     *
     * @param list<non-empty-string>|string $url
     *
     * @return ($url is string ? Page : PageCollection)
     */
    function visit(array|string $url, ?ContextOptions $options = null): Page|PageCollection
    {
        return Browsing::visit($url, $options);
    }
}

if (!\function_exists('arch')) {
    /**
     * An architecture rule (D-088): state what the code's shape must be,
     * and let it fail like any other test when the shape drifts.
     *
     *     arch('the domain stays pure')
     *         ->expect('App\\Domain')
     *         ->toOnlyUse('App\\Domain');
     *
     * The universe is the configured `->source(include: [...])` — the
     * code you own — so vendor and tests are outside it by construction.
     * A rule matching no class **fails**: a renamed namespace would
     * otherwise leave a green test enforcing nothing.
     *
     * @param ?non-empty-string $description
     */
    function arch(?string $description = null): ArchRule
    {
        return PestRegistry::arch($description);
    }
}
