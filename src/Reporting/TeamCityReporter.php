<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Coverage\SourceAnalysis;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Reporting\ProgressView\{ProgressView, ProgressViewContract};
use LucianoPereira\Crucible\Test\TestId;

use function array_keys;
use function basename;
use function fwrite;
use function getmypid;
use function implode;
use function is_file;
use function round;
use function sprintf;
use function str_replace;

/**
 * TeamCity service messages, the IDE integration protocol (PhpStorm's
 * test runner tab speaks it). Stateless pass-through: one message per
 * test:start / test:finish, no buffering — the IDE renders live.
 */
#[ProgressView(key: 'teamcity', description: 'Replace the progress output with TeamCity service messages.')]
final class TeamCityReporter implements ProgressViewContract
{
    /**
     * Every message carries it, and TeamCity needs it: without a flow
     * id the reader cannot tell two interleaved parallel runs apart and
     * attributes one worker's failure to another's test.
     */
    private readonly string $flowId;

    /** The file whose suite is currently open, so the next one can close it. */
    private ?string $openSuite = null;

    /** @var array<string, string> file => declaring class, resolved once */
    private array $classes = [];

    /**
     * @param resource $stream
     */
    public function __construct(private $stream)
    {
        $this->flowId = (string) getmypid();
    }

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof RunStarted) {
            if ($event->tests > 0) {
                $this->message('testCount', ['count' => (string) $event->tests]);
            }

            $this->message('testSuiteStarted', ['name' => 'Crucible']);

            return;
        }

        if ($event instanceof RunFinished) {
            $this->closeSuite();
            $this->message('testSuiteFinished', ['name' => 'Crucible']);

            return;
        }

        if ($event instanceof TestStarted) {
            $this->openSuiteFor($event->test);

            $this->message('testStarted', [
                'name'         => $this->name($event->test),
                'locationHint' => $this->location($event->test, method: true),
            ]);

            return;
        }

        if (!$event instanceof TestFinished) {
            return;
        }

        $name     = $this->name($event->test);
        $duration = (string) (int) round($event->duration * 1000);

        switch ($event->outcome) {
            case Outcome::Failed:
            case Outcome::Errored:
                $attributes = [
                    'name'     => $name,
                    'message'  => $event->failure->message ?? $event->reason ?? $event->outcome->value,
                    'details'  => $this->details($event),
                    'duration' => $duration,
                ];

                if ($event->failure?->expected !== null && $event->failure->actual !== null) {
                    $attributes['type']     = 'comparisonFailure';
                    $attributes['expected'] = $event->failure->expected;
                    $attributes['actual']   = $event->failure->actual;
                }

                $this->message('testFailed', $attributes);

                break;

            case Outcome::Skipped:
            case Outcome::Incomplete:
            case Outcome::Risky:
                $ignored = [
                    'name'    => $name,
                    'message' => $event->reason ?? $event->outcome->value,
                ];

                // Only when there is somewhere to point: a skip has no
                // trace, and an empty details key would claim otherwise.
                $details = $this->details($event);

                if ($details !== '') {
                    $ignored['details'] = $details;
                }

                $ignored['duration'] = $duration;

                $this->message('testIgnored', $ignored);

                break;

            case Outcome::Passed:
                break;
        }

        $this->message('testFinished', [
            'name'     => $name,
            'duration' => $duration,
        ]);
    }

    /**
     * Opens the suite for a test's file, closing the previous one.
     * TeamCity nests tests under the class they came from, and a reader
     * given no suite shows one flat list with no way back to the file.
     */
    private function openSuiteFor(TestId $id): void
    {
        if ($this->openSuite === $id->file) {
            return;
        }

        $this->closeSuite();

        $this->message('testSuiteStarted', [
            'name'         => $this->classOf($id->file),
            'locationHint' => $this->location($id, method: false),
        ]);

        $this->openSuite = $id->file;
    }

    private function closeSuite(): void
    {
        if ($this->openSuite === null) {
            return;
        }

        $this->message('testSuiteFinished', ['name' => $this->classOf($this->openSuite)]);

        $this->openSuite = null;
    }

    /**
     * The protocol's source pointer, which is how the IDE turns a test
     * name into a place to jump to.
     */
    private function location(TestId $id, bool $method): string
    {
        return 'php_qn://' . $id->file . '::\\' . $this->classOf($id->file)
            . ($method ? '::' . $id->name : '');
    }

    /**
     * The class a test file declares, from the analysis the coverage
     * reports already cache. Falls back to the file's basename when the
     * file declares none — a Pest file has tests but no class.
     */
    private function classOf(string $file): string
    {
        if (!isset($this->classes[$file])) {
            $declared = '';

            if ($file !== '' && is_file($file)) {
                foreach (array_keys(SourceAnalysis::of($file)->classes) as $qualified) {
                    $declared = $qualified;

                    break;
                }
            }

            $this->classes[$file] = $declared === '' ? basename($file, '.php') : $declared;
        }

        return $this->classes[$file];
    }

    /**
     * Where the failure happened, one frame per line — what the reader
     * shows under the message when someone opens a failed test.
     */
    private function details(TestFinished $event): string
    {
        if (!$event->failure instanceof Failure) {
            return '';
        }

        $frames = '';

        foreach ($event->failure->trace as $frame) {
            $frames .= $frame->file . ':' . $frame->line . "\n";
        }

        return $frames;
    }

    /**
     * @return non-empty-string
     */
    private function name(TestId $id): string
    {
        return $id->name . ($id->dataset !== null ? PrettyName::dataset($id->dataset) : '');
    }

    /**
     * @param array<non-empty-string, string> $attributes
     */
    private function message(string $name, array $attributes): void
    {
        $rendered   = [];
        $attributes = [...$attributes, 'flowId' => $this->flowId];

        foreach ($attributes as $key => $value) {
            $rendered[] = sprintf("%s='%s'", $key, $this->escape($value));
        }

        fwrite($this->stream, sprintf('##teamcity[%s %s]', $name, implode(' ', $rendered)) . "\n");
    }

    /**
     * The protocol's escaping: pipe first, then the characters that
     * would end or break an attribute.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['|', "'", "\n", "\r", '[', ']'],
            ['||', "|'", '|n', '|r', '|[', '|]'],
            $value,
        );
    }
}
