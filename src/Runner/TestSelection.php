<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\CoversFunction;
use LucianoPereira\Crucible\Attributes\CoversMethod;
use LucianoPereira\Crucible\Attributes\CoversTrait;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Attributes\UsesClass;
use LucianoPereira\Crucible\Attributes\UsesFunction;
use LucianoPereira\Crucible\Attributes\UsesMethod;
use LucianoPereira\Crucible\Attributes\UsesTrait;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;

use function array_any;
use function in_array;

/**
 * Which tests of the discovered suite actually run: the spec's
 * --filter (name pattern), --group (only members), --exclude-group
 * (never members, and exclusion wins), and the todo listing (D-045)
 * — --todos keeps only todo-marked tests, --assignee/--issue narrow
 * by the marker's fields (each implies todo membership on its own,
 * the observed pest behavior). Selection happens after discovery and
 * before scheduling, so ordering, the pool, and the workers all see
 * only selected tests. --no-wip drops work-in-progress tests from the
 * run (wip runs by default, Pest parity) — a custom exclusion, not a
 * Pest flag, so it changes no inherited behavior.
 */
final readonly class TestSelection
{
    /**
     * @param list<non-empty-string> $groups
     * @param list<non-empty-string> $excludeGroups
     * @param ?non-empty-string      $assignee
     * @param ?non-empty-string      $issue
     * @param list<non-empty-string> $covers     the spec's --covers: only tests declaring one of these targets
     * @param list<non-empty-string> $uses       the spec's --uses: same, over the Uses* attributes
     * @param list<non-empty-string> $extensions the spec's --requires-php-extension: only tests requiring one of these
     * @param ?list<non-empty-string> $testIds   the spec's --run-test-id / --test-id-filter-file; null = no id selection
     * @param ?list<non-empty-string> $files     the spec's --test-files-file; null = no file selection
     */
    public function __construct(
        private ?NameFilter $filter = null,
        private array $groups = [],
        private array $excludeGroups = [],
        private bool $todos = false,
        private ?string $assignee = null,
        private ?string $issue = null,
        private bool $noWip = false,
        private ?NameFilter $excludeFilter = null,
        private array $covers = [],
        private array $uses = [],
        private array $extensions = [],
        private ?array $testIds = null,
        private ?array $files = null,
    ) {}

    /**
     * @param list<TestGroup> $testGroups
     *
     * @return list<TestGroup>
     */
    public function apply(array $testGroups): array
    {
        if (!$this->filter instanceof NameFilter && $this->groups === [] && $this->excludeGroups === []
            && !$this->todos && $this->assignee === null && $this->issue === null && !$this->noWip
            && !$this->excludeFilter instanceof NameFilter && $this->covers === [] && $this->uses === []
            && $this->extensions === [] && $this->testIds === null && $this->files === null
        ) {
            return $testGroups;
        }

        $selected = [];

        foreach ($testGroups as $testGroup) {
            $tests = [];

            foreach ($testGroup->tests as $test) {
                if ($this->selects($testGroup->name, $test)) {
                    $tests[] = $test;
                }
            }

            if ($tests !== []) {
                $selected[] = new TestGroup($testGroup->name, $tests, $testGroup->beforeAll, $testGroup->afterAll);
            }
        }

        return $selected;
    }

    /**
     * @param non-empty-string $className
     */
    private function selects(string $className, TestDefinition $test): bool
    {
        if ($this->noWip) {
            $wip = $test->metadata->first(Todo::class);

            if ($wip instanceof Todo && $wip->status === TodoStatus::Wip) {
                return false;
            }
        }

        if ($this->todos || $this->assignee !== null || $this->issue !== null) {
            $todo = $test->metadata->first(Todo::class);

            if (!$todo instanceof Todo) {
                return false;
            }

            if ($this->assignee !== null && $todo->assignee !== $this->assignee) {
                return false;
            }

            if ($this->issue !== null && (string) $todo->issue !== $this->issue) {
                return false;
            }
        }

        if ($this->groups !== [] || $this->excludeGroups !== []) {
            $memberships = [];

            foreach ($test->metadata->ofType(Group::class) as $group) {
                $memberships[] = $group->name;
            }

            foreach ($this->excludeGroups as $excluded) {
                if (in_array($excluded, $memberships, true)) {
                    return false;
                }
            }

            if ($this->groups !== []) {
                $included = array_any($this->groups, fn($wanted) => in_array($wanted, $memberships, true));
                if (!$included) {
                    return false;
                }
            }
        }

        if ($this->excludeFilter instanceof NameFilter && $this->excludeFilter->matches($className, $test->id)) {
            return false;
        }

        // --covers / --uses select on the intent a test declared, which is
        // the same metadata the coverage targets read: a class name, or a
        // function/method/trait name, whichever attribute carried it.
        if ($this->covers !== [] && !$this->declaresCovers($test, $this->covers)) {
            return false;
        }

        if ($this->uses !== [] && !$this->declaresUses($test, $this->uses)) {
            return false;
        }

        if ($this->extensions !== []) {
            $required = [];

            foreach ($test->metadata->ofType(RequiresPhpExtension::class) as $requirement) {
                $required[] = $requirement->extension;
            }

            if (!array_any($this->extensions, static fn(string $wanted): bool => in_array($wanted, $required, true))) {
                return false;
            }
        }

        if ($this->testIds !== null && !in_array($test->id->toString(), $this->testIds, true)) {
            return false;
        }

        if ($this->files !== null && !in_array($test->id->file, $this->files, true)) {
            return false;
        }

        return !$this->filter instanceof NameFilter || $this->filter->matches($className, $test->id);
    }

    /**
     * @param list<non-empty-string> $wanted
     */
    private function declaresCovers(TestDefinition $test, array $wanted): bool
    {
        return $this->declares($test, $wanted, [
            CoversClass::class, CoversFunction::class, CoversTrait::class, CoversMethod::class,
        ]);
    }

    /**
     * @param list<non-empty-string> $wanted
     */
    private function declaresUses(TestDefinition $test, array $wanted): bool
    {
        return $this->declares($test, $wanted, [
            UsesClass::class, UsesFunction::class, UsesTrait::class, UsesMethod::class,
        ]);
    }

    /**
     * @param list<non-empty-string>                $wanted
     * @param list<class-string<CrucibleAttribute>> $types  one family, Covers* or Uses*
     */
    private function declares(TestDefinition $test, array $wanted, array $types): bool
    {
        $declared = [];

        foreach ($types as $type) {
            foreach ($test->metadata->ofType($type) as $target) {
                foreach ($this->namesOf($target) as $name) {
                    $declared[] = $name;
                }
            }
        }

        return array_any($wanted, static fn(string $target): bool => in_array($target, $declared, true));
    }

    /**
     * Each attribute names its target differently — a class, a function,
     * a trait, or a class::method pair — so each is read on its own terms.
     *
     * @return list<string>
     */
    private function namesOf(CrucibleAttribute $target): array
    {
        return match (true) {
            $target instanceof CoversClass,
            $target instanceof UsesClass => [$target->className],
            $target instanceof CoversFunction,
            $target instanceof UsesFunction => [$target->functionName],
            $target instanceof CoversTrait,
            $target instanceof UsesTrait => [$target->traitName],
            $target instanceof CoversMethod,
            $target instanceof UsesMethod => [$target->className, $target->className . '::' . $target->methodName],
            default                       => [],
        };
    }
}
