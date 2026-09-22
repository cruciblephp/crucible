<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\Event;
use LucianoPereira\Crucible\Event\EventName;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Frame;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\OutputChannel;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestOutputWritten;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Test\TestId;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

/**
 * The supervisor's read side of the IPC: one worker NDJSON line in,
 * one event object out. Test-scoped events are reconstructed and
 * re-emitted on the supervisor's own stream; run-scoped events mark
 * protocol state (run:finish is the worker's completion handshake)
 * and are not forwarded — the supervisor owns the one true stream.
 *
 * Unparseable lines return null: a worker whose stdout was polluted
 * by user code must degrade to a diagnosable error, never a crash.
 */
final readonly class EventParser
{
    /**
     * Parses a test-scoped event line; null for anything else —
     * malformed JSON, run/suite-scoped events, foreign output.
     */
    public function parse(string $line): ?Event
    {
        $decoded = json_decode($line, true);

        if (!is_array($decoded) || !is_string($decoded['event'] ?? null)) {
            return null;
        }

        $id = is_string($decoded['id'] ?? null) ? TestId::fromString($decoded['id']) : null;

        if (!$id instanceof TestId) {
            return null;
        }

        return match (EventName::tryFrom($decoded['event'])) {
            EventName::TestStarted       => new TestStarted($id, $this->attempt($decoded)),
            EventName::TestFinished      => $this->testFinished($id, $decoded),
            EventName::TestOutputWritten => $this->testOutput($id, $decoded),
            default                      => null,
        };
    }

    /**
     * Whether the line is a worker's run:finish — the completion
     * handshake: a worker that reached EOF without one crashed.
     */
    public function isRunFinished(string $line): bool
    {
        $decoded = json_decode($line, true);

        return is_array($decoded) && ($decoded['event'] ?? null) === EventName::RunFinished->value;
    }

    /**
     * @param array<mixed> $decoded
     */
    private function testFinished(TestId $id, array $decoded): ?TestFinished
    {
        $outcome  = is_string($decoded['outcome'] ?? null) ? Outcome::tryFrom($decoded['outcome']) : null;
        $duration = $decoded['duration'] ?? null;

        if (!$outcome instanceof Outcome || (!is_float($duration) && !is_int($duration))) {
            return null;
        }

        $reason = is_string($decoded['reason'] ?? null) && $decoded['reason'] !== '' ? $decoded['reason'] : null;

        return new TestFinished(
            $id,
            $outcome,
            (float) $duration,
            $this->failure($decoded['error'] ?? null),
            $this->attempt($decoded),
            $reason,
            $this->issues($decoded['issues'] ?? null),
            ($decoded['quarantined'] ?? false) === true,
            $this->property($decoded['property'] ?? null),
            $this->retried($decoded['retried'] ?? null),
            $this->snapshots($decoded['snapshots'] ?? null),
            $this->keys($decoded['propertyClean'] ?? null),
            $this->keys($decoded['snapshotKeys'] ?? null),
            ($decoded['blocked'] ?? false) === true,
            is_int($decoded['assertions'] ?? null) ? $decoded['assertions'] : 0,
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private function keys(mixed $keys): array
    {
        $parsed = [];

        foreach (is_array($keys) ? $keys : [] as $key) {
            if (is_string($key) && $key !== '') {
                $parsed[] = $key;
            }
        }

        return $parsed;
    }

    /**
     * @return ?array{created: int, updated: int}
     */
    private function snapshots(mixed $snapshots): ?array
    {
        if (!is_array($snapshots) || !is_int($snapshots['created'] ?? null) || !is_int($snapshots['updated'] ?? null)) {
            return null;
        }

        return ['created' => $snapshots['created'], 'updated' => $snapshots['updated']];
    }

    /**
     * @return list<Failure>
     */
    private function retried(mixed $retried): array
    {
        $failures = [];

        foreach (is_array($retried) ? $retried : [] as $entry) {
            $failure = $this->failure($entry);

            if ($failure instanceof Failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @return ?array{key: non-empty-string, choices: list<int>}
     */
    private function property(mixed $property): ?array
    {
        if (!is_array($property) || !is_string($property['key'] ?? null) || $property['key'] === '' || !is_array($property['choices'] ?? null)) {
            return null;
        }

        $choices = [];

        foreach ($property['choices'] as $choice) {
            if (!is_int($choice)) {
                return null;
            }

            $choices[] = $choice;
        }

        return ['key' => $property['key'], 'choices' => $choices];
    }

    /**
     * @return list<Issue>
     */
    private function issues(mixed $issues): array
    {
        $parsed = [];

        foreach (is_array($issues) ? $issues : [] as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $kind    = is_string($issue['kind'] ?? null) ? IssueKind::tryFrom($issue['kind']) : null;
            $message = $issue['message'] ?? null;
            $file    = $issue['file'] ?? null;
            $line    = $issue['line'] ?? null;

            if ($kind === null || !is_string($message) || $message === '' || !is_string($file) || $file === '' || !is_int($line) || $line < 1) {
                continue;
            }

            $scope = is_string($issue['scope'] ?? null) ? DeprecationScope::tryFrom($issue['scope']) : null;

            $parsed[] = new Issue($kind, $message, $file, $line, $scope);
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $decoded
     */
    private function testOutput(TestId $id, array $decoded): ?TestOutputWritten
    {
        $channel = is_string($decoded['channel'] ?? null) ? OutputChannel::tryFrom($decoded['channel']) : null;
        $chunk   = $decoded['chunk'] ?? null;

        if (!$channel instanceof OutputChannel || !is_string($chunk)) {
            return null;
        }

        return new TestOutputWritten($id, $channel, $chunk);
    }

    private function failure(mixed $error): ?Failure
    {
        if (!is_array($error) || !is_string($error['message'] ?? null) || $error['message'] === '') {
            return null;
        }

        $class = is_string($error['class'] ?? null) && $error['class'] !== '' ? $error['class'] : null;

        $trace = [];

        foreach (is_array($error['trace'] ?? null) ? $error['trace'] : [] as $frame) {
            if (!is_array($frame) || !is_string($frame['file'] ?? null) || $frame['file'] === '') {
                continue;
            }

            $line = $frame['line'] ?? null;

            if (!is_int($line) || $line < 1) {
                continue;
            }

            $function = is_string($frame['function'] ?? null) && $frame['function'] !== '' ? $frame['function'] : null;

            $trace[] = new Frame($frame['file'], $line, $function);
        }

        $diff     = is_array($error['diff'] ?? null) ? $error['diff'] : [];
        $expected = is_string($diff['expected'] ?? null) && $diff['expected'] !== '' ? $diff['expected'] : null;
        $actual   = is_string($diff['actual'] ?? null) && $diff['actual'] !== '' ? $diff['actual'] : null;

        return new Failure($error['message'], $class, $trace, $expected, $actual);
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return positive-int
     */
    private function attempt(array $decoded): int
    {
        $attempt = $decoded['attempt'] ?? 1;

        return is_int($attempt) && $attempt >= 1 ? $attempt : 1;
    }
}
