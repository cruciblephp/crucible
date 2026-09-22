<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\TestFinished;

/**
 * Collects every issue of the run off the event stream — one more
 * listener, so it sees worker results exactly like in-process ones.
 * The threshold policy and --update-deprecations-baseline both read
 * from here after the run.
 */
final class IssueLog implements Listener
{
    /** @var list<Issue> */
    private array $issues = [];

    /** @var array<string, int> deprecation counts by scope */
    private array $scopes = ['self' => 0, 'direct' => 0, 'indirect' => 0];

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if (!$event instanceof TestFinished) {
            return;
        }

        foreach ($event->issues as $issue) {
            $this->issues[] = $issue;

            if ($issue->kind === IssueKind::Deprecation && $issue->scope instanceof DeprecationScope) {
                $this->scopes[$issue->scope->value]++;
            }
        }
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return array<string, int> deprecation counts keyed self|direct|indirect
     */
    public function deprecationsByScope(): array
    {
        return $this->scopes;
    }
}
