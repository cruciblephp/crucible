<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;

/**
 * The resolved `--stop-on-*` policy, in one place.
 *
 * It has three consumers that must agree: the sequential runner (which
 * stops between tests), a worker (which receives the same policy in its
 * manifest, so a CLI flag reaches it exactly as a configured one does),
 * and the supervisor (which stops handing new units to free slots once
 * a result matches). A flag read in only one of those places is how
 * `--stop-on-failure --parallel` came to run the whole suite.
 */
final readonly class StopOn
{
    public function __construct(
        public bool $defect = false,
        public bool $error = false,
        public bool $failure = false,
        public bool $risky = false,
        public bool $skipped = false,
        public bool $incomplete = false,
        public bool $deprecation = false,
        public bool $notice = false,
        public bool $warning = false,
    ) {}

    public static function fromRunnerOptions(RunnerOptions $options): self
    {
        return new self(
            defect: $options->stopOnDefect,
            error: $options->stopOnError,
            failure: $options->stopOnFailure,
            risky: $options->stopOnRisky,
            skipped: $options->stopOnSkipped,
            incomplete: $options->stopOnIncomplete,
            deprecation: $options->stopOnDeprecation,
            notice: $options->stopOnNotice,
            warning: $options->stopOnWarning,
        );
    }

    /**
     * @param array<mixed> $data the manifest's serialized form
     */
    public static function fromArray(array $data): self
    {
        $flag = static fn(string $key): bool => ($data[$key] ?? false) === true;

        return new self(
            defect: $flag('defect'),
            error: $flag('error'),
            failure: $flag('failure'),
            risky: $flag('risky'),
            skipped: $flag('skipped'),
            incomplete: $flag('incomplete'),
            deprecation: $flag('deprecation'),
            notice: $flag('notice'),
            warning: $flag('warning'),
        );
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'defect'      => $this->defect,
            'error'       => $this->error,
            'failure'     => $this->failure,
            'risky'       => $this->risky,
            'skipped'     => $this->skipped,
            'incomplete'  => $this->incomplete,
            'deprecation' => $this->deprecation,
            'notice'      => $this->notice,
            'warning'     => $this->warning,
        ];
    }

    public function any(): bool
    {
        return $this->defect
            || $this->error
            || $this->failure
            || $this->risky
            || $this->skipped
            || $this->incomplete
            || $this->deprecation
            || $this->notice
            || $this->warning;
    }

    /**
     * The spec's stop-on semantics: failure and error each have their
     * own switch, defect covers both plus risky, and the issue switches
     * read what the test emitted rather than how it ended.
     *
     * @param list<IssueKind> $issues
     */
    public function matches(Outcome $outcome, array $issues = []): bool
    {
        $byOutcome = match ($outcome) {
            Outcome::Errored    => $this->error || $this->defect,
            Outcome::Failed     => $this->failure || $this->defect,
            Outcome::Risky      => $this->risky || $this->defect,
            Outcome::Skipped    => $this->skipped,
            Outcome::Incomplete => $this->incomplete,
            default             => false,
        };

        if ($byOutcome) {
            return true;
        }

        foreach ($issues as $kind) {
            $stop = match ($kind) {
                IssueKind::Deprecation => $this->deprecation,
                IssueKind::Notice      => $this->notice,
                IssueKind::Warning     => $this->warning,
            };

            if ($stop) {
                return true;
            }
        }

        return false;
    }
}
