<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use Closure;
use LucianoPereira\Crucible\Attributes\ExpectedOutcome;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use Throwable;

use function getenv;
use function implode;
use function in_array;
use function is_string;
use function mb_strtolower;
use function preg_match;
use function sprintf;
use function version_compare;

use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * What test()/it() return: the chainable handle the pest spec hangs
 * datasets, skips, groups, exception expectations, and dependencies
 * on. Mutable while its file is being loaded; the builder reads the
 * final state once the file has finished executing.
 *
 * A call without a body is either a todo (no chain) or a higher-order
 * test: unknown chained methods are collected via __call and replayed
 * against the test-case instance at run time.
 */
final class TestCall
{
    /**
     * Each ->with() call is one Cartesian factor (the spec: multiple
     * ->with() = product): inline rows, the name of a shared
     * dataset(), or a lazy closure materialized at build time.
     *
     * @var list<array<array-key, mixed>|non-empty-string|Closure>
     */
    public array $datasets = [];

    /** Skip state: false, true, the reason, or a condition evaluated after beforeEach. */
    public bool|string|Closure $skipped = false;

    /** The reason paired with a closure-form skip. */
    public string $skipReason = '';

    /** @var list<non-empty-string> */
    public array $groups = [];

    /** @var ?non-empty-string a Throwable class name, or a message fragment (per the spec) */
    public ?string $throws = null;

    public ?string $throwsMessage = null;

    public bool $throwsNothing = false;

    /** @var list<non-empty-string> */
    public array $dependsOn = [];

    public ?Todo $todo = null;

    /** The test is expected to fail; passing is the failure. */
    public bool $fails = false;

    public ?string $failsMessage = null;

    /** The test is expected to skip or be incomplete; ending otherwise is the failure. */
    public ?ExpectedOutcome $expectedOutcome = null;

    /** @var positive-int */
    public int $repetitions = 1;

    /**
     * The higher-order chain: methods (and property reads, e.g. an
     * expectation's ->not) to replay when the call has no body.
     *
     * @var list<array{non-empty-string, list<mixed>, bool}> [name, arguments, isProperty]
     */
    public array $chain = [];

    /**
     * @param non-empty-string       $description as written, before it-prefixing
     * @param 'test'|'it'            $kind
     * @param list<non-empty-string> $describePath enclosing describe() descriptions, outermost first
     */
    public function __construct(
        public readonly string $description,
        public readonly string $kind,
        public readonly ?Closure $test,
        public readonly array $describePath = [],
    ) {}

    /**
     * Anything that is not a declared chainable is a higher-order
     * step — but only on a body-less test; on a test with a body it
     * can only be a typo, and failing at load time beats a confusing
     * run-time error.
     *
     * @param non-empty-string $name
     * @param list<mixed>      $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        if ($this->test instanceof Closure) {
            throw new ConfigurationException(sprintf(
                '->%s() is not a pest chainable; higher-order chaining is only available on tests without a body.',
                $name,
            ));
        }

        $this->chain[] = [$name, $arguments, false];

        return $this;
    }

    /**
     * Property reads chain too — `check(fn() => 5)->not->toBe(6)`
     * records ->not for replay on the expectation.
     *
     * @param non-empty-string $name
     */
    public function __get(string $name): self
    {
        if ($this->test instanceof Closure) {
            throw new ConfigurationException(sprintf(
                '->%s is not a pest chainable; higher-order chaining is only available on tests without a body.',
                $name,
            ));
        }

        $this->chain[] = [$name, [], true];

        return $this;
    }

    /**
     * @param iterable<array-key, mixed>|non-empty-string|Closure $dataset rows, a shared dataset name, or a lazy factory
     */
    public function with(iterable|string|Closure $dataset): self
    {
        if (is_string($dataset) || $dataset instanceof Closure) {
            $this->datasets[] = $dataset;

            return $this;
        }

        $rows = [];

        foreach ($dataset as $key => $row) {
            $rows[$key] = $row;
        }

        $this->datasets[] = $rows;

        return $this;
    }

    public function skip(bool|string|Closure $condition = true, string $reason = ''): self
    {
        if ($condition === false) {
            return $this;
        }

        if ($condition instanceof Closure) {
            $this->skipped    = $condition;
            $this->skipReason = $reason;

            return $this;
        }

        $this->skipped = match (true) {
            $condition === true => $reason !== '' ? $reason : true,
            default             => $condition,
        };

        return $this;
    }

    public function skipOnCi(): self
    {
        return $this->skip($this->onCi(), 'Skipped on CI.');
    }

    public function skipLocally(): self
    {
        return $this->skip(!$this->onCi(), 'Skipped locally.');
    }

    public function skipOnWindows(): self
    {
        return $this->skip(PHP_OS_FAMILY === 'Windows', 'Skipped on Windows.');
    }

    public function skipOnMac(): self
    {
        return $this->skip(PHP_OS_FAMILY === 'Darwin', 'Skipped on macOS.');
    }

    public function skipOnLinux(): self
    {
        return $this->skip(PHP_OS_FAMILY === 'Linux', 'Skipped on Linux.');
    }

    public function onlyOnWindows(): self
    {
        return $this->skip(PHP_OS_FAMILY !== 'Windows', 'Only runs on Windows.');
    }

    public function onlyOnMac(): self
    {
        return $this->skip(PHP_OS_FAMILY !== 'Darwin', 'Only runs on macOS.');
    }

    public function onlyOnLinux(): self
    {
        return $this->skip(PHP_OS_FAMILY !== 'Linux', 'Only runs on Linux.');
    }

    /**
     * @param non-empty-string $constraint an operator-prefixed version, e.g. '>=8.0.0'
     */
    public function skipOnPhp(string $constraint): self
    {
        if (preg_match('/^(<=|>=|<>|!=|<|>|==?)?\s*(.+)$/', $constraint, $matches) !== 1) {
            throw new ConfigurationException(sprintf('skipOnPhp(%s): not a version constraint.', $constraint));
        }

        $operator = match ($matches[1]) {
            '', '=' => '==',
            default => $matches[1],
        };

        return $this->skip(
            version_compare(PHP_VERSION, $matches[2], $operator),
            'Skipped on PHP ' . $constraint . '.',
        );
    }

    public function todo(?string $assignee = null, string|int|null $issue = null, ?string $note = null): self
    {
        $this->todo = new Todo(TodoStatus::Todo, $assignee, $issue, $note);

        return $this;
    }

    public function wip(?string $assignee = null, string|int|null $issue = null, ?string $note = null): self
    {
        $this->todo = new Todo(TodoStatus::Wip, $assignee, $issue, $note);

        return $this;
    }

    public function done(?string $assignee = null, string|int|null $issue = null, ?string $note = null): self
    {
        $this->todo = new Todo(TodoStatus::Done, $assignee, $issue, $note);

        return $this;
    }

    /** The test is expected to fail; the optional fragment must appear in the failure message. */
    public function fails(?string $message = null): self
    {
        $this->fails        = true;
        $this->failsMessage = $message;

        return $this;
    }

    /**
     * The test is expected to skip; not skipping is the failure and a
     * skip is the pass (D-073). A crucible-native assertion — the superset,
     * so the name deliberately does not shadow the inherited ->skip().
     * Pair it with any real skip trigger (->skip(), ->skipOnPhp(), a
     * body markTestSkipped); the optional fragment must appear in the
     * skip reason (the fails() symmetry).
     */
    public function expectsSkip(?string $reason = null): self
    {
        $this->expectedOutcome = new ExpectedOutcome(Outcome::Skipped, $reason);

        return $this;
    }

    /**
     * The test is expected to be reported incomplete; ending otherwise
     * is the failure (D-073). A crucible-native assertion — the superset.
     * A body-less call, ->todo()/->wip(), or a body markTestIncomplete
     * supplies the incomplete; the optional fragment must appear in the
     * reason.
     */
    public function expectsIncomplete(?string $reason = null): self
    {
        $this->expectedOutcome = new ExpectedOutcome(Outcome::Incomplete, $reason);

        return $this;
    }

    /**
     * @param class-string<Throwable>|non-empty-string $exception class name, or a message fragment
     */
    public function throws(string $exception, ?string $message = null): self
    {
        $this->throws        = $exception;
        $this->throwsMessage = $message;

        return $this;
    }

    /**
     * @param class-string<Throwable>|non-empty-string $exception
     */
    public function throwsIf(bool|Closure $condition, string $exception, ?string $message = null): self
    {
        $condition = $condition instanceof Closure ? (bool) $condition() : $condition;

        return $condition ? $this->throws($exception, $message) : $this;
    }

    /**
     * @param class-string<Throwable>|non-empty-string $exception
     */
    public function throwsUnless(bool|Closure $condition, string $exception, ?string $message = null): self
    {
        $condition = $condition instanceof Closure ? (bool) $condition() : $condition;

        return $condition ? $this : $this->throws($exception, $message);
    }

    public function throwsNoExceptions(): self
    {
        $this->throwsNothing = true;

        return $this;
    }

    public function repeat(int $times): self
    {
        if ($times < 1) {
            throw new ConfigurationException('repeat() needs a positive number of repetitions.');
        }

        $this->repetitions = $times;

        return $this;
    }

    /**
     * @param non-empty-string ...$groups
     */
    public function group(string ...$groups): self
    {
        foreach ($groups as $group) {
            $this->groups[] = $group;
        }

        return $this;
    }

    /**
     * @param non-empty-string ...$tests parent descriptions; `it` parents carry the literal "it " prefix
     */
    public function depends(string ...$tests): self
    {
        foreach ($tests as $test) {
            $this->dependsOn[] = $test;
        }

        return $this;
    }

    /**
     * The declared name inside the file: describe path joined with
     * the spec's "it " prefix applied.
     *
     * @return non-empty-string
     */
    public function name(): string
    {
        $leaf = ($this->kind === 'it' ? 'it ' : '') . $this->description;

        return $this->describePath === []
            ? $leaf
            : implode(' > ', $this->describePath) . ' > ' . $leaf;
    }

    private function onCi(): bool
    {
        $ci = getenv('CI');

        return $ci !== false && !in_array(mb_strtolower($ci), ['', '0', 'false'], true);
    }
}
