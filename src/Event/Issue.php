<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

/**
 * One PHP-level issue a test triggered: a deprecation, notice, or
 * warning. Not a failure — the test's outcome is untouched — but
 * counted, attributed, and carried on the test:finish event so
 * policies (fail-on, thresholds, baselines) and reporters can act.
 */
final readonly class Issue
{
    /**
     * @param non-empty-string  $message
     * @param non-empty-string  $file where the issue was triggered
     * @param positive-int      $line
     * @param ?DeprecationScope $scope attribution; deprecations only
     */
    public function __construct(
        public IssueKind $kind,
        public string $message,
        public string $file,
        public int $line,
        public ?DeprecationScope $scope = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $issue = [
            'kind'    => $this->kind->value,
            'message' => $this->message,
            'file'    => $this->file,
            'line'    => $this->line,
        ];

        if ($this->scope instanceof DeprecationScope) {
            $issue['scope'] = $this->scope->value;
        }

        return $issue;
    }
}
