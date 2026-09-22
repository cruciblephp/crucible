<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use function fnmatch;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * A declared impact rule (D-083): the files this pattern covers put
 * these test groups in doubt. The dependency graph resolves *class
 * references*, so everything reached by convention, path, or runtime
 * lookup is invisible to it — assets, Blade templates, translations,
 * fixtures, regardless of extension. Only the author knows which tests
 * such a change endangers, so it is declared rather than inferred:
 * inferring it (a bundler manifest, a naming convention) fails by
 * silently selecting too few tests, which is worse than the full run
 * it would be avoiding.
 *
 * Rules are **additive**. A match adds groups to the selection and can
 * never remove one, so a missing or wrong rule is never worse than
 * having no rules at all.
 */
final readonly class ImpactRule
{
    /**
     * @param non-empty-string       $pattern project-relative path; a directory covers everything
     *                                        beneath it, and `*`/`?` glob (matching across `/`)
     * @param list<non-empty-string> $groups  the groups a matching change puts in doubt
     */
    public function __construct(
        public string $pattern,
        public array $groups,
    ) {}

    /**
     * @param string $path project-relative, forward slashes
     */
    public function matches(string $path): bool
    {
        if (fnmatch($this->pattern, $path)) {
            return true;
        }

        // A pattern with no wildcard is a prefix: naming a directory is
        // what a rule almost always means, and demanding `dir/**` for it
        // would make the silent no-match the easy mistake to write.
        return !str_contains($this->pattern, '*')
            && !str_contains($this->pattern, '?')
            && str_starts_with($path, rtrim($this->pattern, '/') . '/');
    }
}
