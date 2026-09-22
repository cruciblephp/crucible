<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Watch;

/**
 * One iteration of the watch loop: what to run and why. Null files
 * means the full suite; otherwise the files feed --related, so the
 * child selects everything their change can affect.
 */
final readonly class WatchRun
{
    /**
     * @param ?list<non-empty-string> $related
     * @param non-empty-string        $reason
     * @param list<non-empty-string>  $extraArguments
     */
    private function __construct(
        public ?array $related,
        public string $reason,
        public array $extraArguments = [],
    ) {}

    /**
     * @param non-empty-string       $reason
     * @param list<non-empty-string> $extraArguments
     */
    public static function full(string $reason, array $extraArguments = []): self
    {
        return new self(null, $reason, $extraArguments);
    }

    /**
     * @param list<non-empty-string> $files
     * @param non-empty-string       $reason
     */
    public static function related(array $files, string $reason): self
    {
        return new self($files, $reason);
    }

    public function isFull(): bool
    {
        return $this->related === null;
    }
}
