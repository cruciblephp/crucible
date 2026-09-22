<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function sprintf;

/**
 * One method, with the line range a coverage map can be read against
 * and the cyclomatic complexity the CRAP index needs.
 */
final readonly class SourceMethod
{
    public function __construct(
        public string $name,
        public int $startLine,
        public int $endLine,
        public int $complexity,
        public string $visibility = 'public',
        public bool $static = false,
    ) {}

    public function signature(): string
    {
        return sprintf('%s%s%s()', $this->visibility === 'public' ? '' : $this->visibility . ' ', $this->static ? 'static ' : '', $this->name);
    }

    /**
     * The percentage of this method's executable lines that ran.
     *
     * @param array<int, int> $lines the file's coverage map: line => hits, -2 meaning not executable
     */
    public function coverage(array $lines): float
    {
        $executable = 0;
        $covered    = 0;

        for ($line = $this->startLine; $line <= $this->endLine; $line++) {
            if (!isset($lines[$line]) || $lines[$line] === -2) {
                continue;
            }

            $executable++;

            if ($lines[$line] > 0) {
                $covered++;
            }
        }

        return $executable === 0 ? 0.0 : 100 * $covered / $executable;
    }

    /**
     * The CRAP index (Change Risk Anti-Patterns), on the spec's exact
     * curve: complexity alone once a method is essentially covered,
     * complexity squared and cubed away from it when it is not.
     *
     * @param array<int, int> $lines the file's coverage map: line => hits, -2 meaning not executable
     */
    public function crap(array $lines): float
    {
        $coverage = $this->coverage($lines);

        return match (true) {
            $coverage === 0.0 => $this->complexity ** 2 + $this->complexity,
            $coverage >= 95   => $this->complexity,
            default           => $this->complexity ** 2 * (1 - $coverage / 100) ** 3 + $this->complexity,
        };
    }
}
