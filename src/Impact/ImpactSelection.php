<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Test\TestGroup;

use function array_diff_key;
use function array_intersect_key;
use function array_keys;
use function basename;
use function count;
use function implode;
use function in_array;
use function realpath;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Ekstazi-style test selection at file granularity (growth G3): a
 * test group runs when the transitive closure of the file declaring
 * it intersects the changed files. The safety direction is fixed —
 * everything that cannot be reasoned about (deletions, environment
 * files) widens the selection to the full suite.
 *
 * Every change that does not select a group is reported under the
 * structural reason it did not ({@see ImpactReason}), never as one
 * bucket: `Uncovered` wants a test, `Undeclared` wants an impact rule,
 * and a category that cannot say which would hide both.
 *
 * Declared impact rules (D-083) are the second, additive source: a
 * changed file the graph cannot reach — an asset, a template, a
 * translation — adds the groups its rule names. They only ever widen,
 * so a missing rule leaves the graph's own answer untouched.
 */
final readonly class ImpactSelection
{
    /**
     * A dependency bump invalidates the graph wholesale, so a change to
     * one of these widens the run to everything. The graph cannot see
     * inside an installed package, so narrowing after one moved would
     * be guessing.
     *
     * The JavaScript lockfiles are here for the same reason as the PHP
     * ones and not by analogy: Crucible runs JavaScript suites through
     * the Vitest tier (D-079), so a bumped npm dependency can break a
     * test in this run — and without this the selection would narrow
     * past exactly the tests that would have caught it.
     *
     * @var list<non-empty-string>
     */
    private const array MANIFESTS = [
        'composer.json', 'composer.lock',
        'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml',
    ];

    /**
     * @param non-empty-string       $projectRoot
     * @param list<non-empty-string> $environmentFiles absolute paths whose change invalidates every test
     *                                                 (the configuration file, the bootstrap)
     * @param list<ImpactRule>       $rules            declared path → groups rules (D-083); additive only
     * @param list<non-empty-string> $claimedPaths     absolute directories another tier answers for
     *                                                 (the configured Vitest suites, D-080)
     */
    public function __construct(
        private DependencyGraph $graph,
        private string $projectRoot,
        private array $environmentFiles = [],
        private array $rules = [],
        private array $claimedPaths = [],
    ) {}

    /**
     * @param list<TestGroup> $groups
     */
    public function select(array $groups, ChangedFiles $changed): ImpactResult
    {
        if ($changed->deleted !== []) {
            return ImpactResult::everything(sprintf(
                'Impact: %d deleted file(s) — their dependents are invisible to the graph',
                count($changed->deleted),
            ));
        }

        /** @var array<string, true> $targets */
        $targets = [];
        /** @var array<string, true> $ruled the groups matching rules put in doubt */
        $ruled      = [];
        $undeclared = 0;
        $claimed    = 0;
        $matched    = 0;

        foreach ($changed->files as $file) {
            $real = realpath($file);
            $real = $real === false ? $file : $real;

            if (in_array($real, $this->environmentFiles, true)
                || in_array(basename($real), self::MANIFESTS, true)
            ) {
                return ImpactResult::everything(sprintf('Impact: %s defines the environment', basename($real)));
            }

            // Rules see every change, whatever its extension: a Blade
            // template and a translation file both end in .php and are
            // both unreachable by class reference.
            $named = false;

            foreach ($this->rules as $rule) {
                if (!$rule->matches($this->relative($real))) {
                    continue;
                }

                $named = true;

                foreach ($rule->groups as $group) {
                    $ruled[$group] = true;
                }
            }

            if ($named) {
                ++$matched;
            }

            if (!str_ends_with($real, '.php')) {
                if ($named) {
                    continue;
                }

                if ($this->isClaimed($real)) {
                    ++$claimed;

                    continue;
                }

                ++$undeclared;

                continue;
            }

            $targets[$real] = true;
        }

        $notes = [];

        if ($claimed > 0) {
            $notes[] = sprintf(
                'Impact: %d change(s) %s.',
                $claimed,
                ImpactReason::Claimed->heading(),
            );
        }

        if ($undeclared > 0) {
            $notes[] = sprintf(
                'Impact: %d change(s) %s.',
                $undeclared,
                ImpactReason::Undeclared->heading(),
            );
        }

        if ($matched > 0) {
            $notes[] = sprintf(
                'Impact: %d change(s) matched an impact rule — group(s) %s put in doubt.',
                $matched,
                implode(', ', array_keys($ruled)),
            );
        }

        $definite = [];
        $possible = [];
        $carried  = [];
        /** @var array<string, true> $reached the changed files some closure actually contains */
        $reached = [];

        foreach ($groups as $group) {
            $memberships = $ruled === [] ? [] : $this->membershipsOf($group);
            $carried += $memberships;

            $hits = $targets === []
                ? []
                : array_intersect_key($this->graph->closureOf($this->fileOf($group)), $targets);

            if ($hits !== []) {
                $reached += $hits;
                $definite[] = $group;

                continue;
            }

            if (array_intersect_key($memberships, $ruled) !== []) {
                $possible[] = $group;
            }
        }

        // The changed PHP files no closure contained. Reported apart from
        // the undeclared ones because the fix differs: a test, not a rule.
        $uncovered = count(array_diff_key($targets, $reached));

        if ($uncovered > 0) {
            $notes[] = sprintf(
                'Impact: %d change(s) %s.',
                $uncovered,
                ImpactReason::Uncovered->heading(),
            );
        }

        // A rule naming a group no test carries selects nothing, silently
        // — the one way a declared rule can be wrong. Name it.
        $orphans = array_keys(array_diff_key($ruled, $carried));

        if ($orphans !== []) {
            $notes[] = sprintf(
                'Impact: impact rule group(s) %s are carried by no test.',
                implode(', ', $orphans),
            );
        }

        $tiered = ImpactResult::tiered($definite, $possible, $notes);

        if ($tiered->possibleCount() > 0) {
            $notes[] = sprintf(
                'Impact: %d group(s) selected by a declared rule rather than a resolved edge.',
                $tiered->possibleCount(),
            );
        }

        $notes[] = sprintf(
            'Impact: %d of %d test file(s) affected.',
            count($tiered->selected()),
            count($groups),
        );

        return ImpactResult::tiered($definite, $possible, $notes);
    }

    /**
     * The groups every test in this group carries, as a set.
     *
     * @return array<string, true>
     */
    private function membershipsOf(TestGroup $group): array
    {
        $memberships = [];

        foreach ($group->tests as $test) {
            foreach ($test->metadata->ofType(Group::class) as $membership) {
                $memberships[$membership->name] = true;
            }
        }

        return $memberships;
    }

    /** Whether another tier of the run answers for this file. */
    private function isClaimed(string $file): bool
    {
        foreach ($this->claimedPaths as $directory) {
            if (str_starts_with($file, rtrim($directory, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string project-relative, or the path itself when outside the root
     */
    private function relative(string $file): string
    {
        $prefix = rtrim($this->projectRoot, '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }

    /**
     * The file a group was declared in — dialect-neutral via the
     * TestIds (group names are class names in the phpunit dialect,
     * paths elsewhere; ids always carry the project-relative file).
     *
     * @return non-empty-string absolute path
     */
    private function fileOf(TestGroup $group): string
    {
        $relative = $group->tests[0]->id->file;

        return rtrim($this->projectRoot, '/') . '/' . $relative;
    }
}
