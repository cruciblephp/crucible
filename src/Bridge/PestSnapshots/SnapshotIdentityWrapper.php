<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Bridge\PestSnapshots;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Generated\GeneratedCode;
use ReflectionClass;

use function array_shift;
use function array_values;
use function class_exists;
use function class_uses;
use function get_parent_class;
use function in_array;
use function md5;
use function sprintf;
use function trait_exists;

/**
 * Overrides getSnapshotDirectory()/getSnapshotId() (both otherwise
 * inherited from spatie/phpunit-snapshot-assertions's own
 * SnapshotDirectoryAware/SnapshotIdAware, mixed in via
 * AutoMixedTrait) to read from SnapshotFileContext instead of
 * reflecting on the running instance — see that class's own docblock
 * for why. A direct method override, not a second trait: PHP would
 * otherwise require explicit insteadof/as conflict resolution for
 * two traits providing the same method names, which
 * TraitComposer::compose()'s bare `use Trait1, Trait2;` generation
 * doesn't support — and shouldn't have to, for a need this specific.
 * A class's own directly-declared method already wins over a
 * trait-provided one with no such resolution needed, so this wraps
 * the already-composed class in one more eval()'d subclass instead.
 *
 * The wrapper body itself never varies by call site (it only ever
 * reads from SnapshotFileContext, never embeds a literal), so unlike
 * TraitComposer's own per-(class,traits) cache, this caches by the
 * wrapped class name alone — generated once per process, reused for
 * every file.
 */
final class SnapshotIdentityWrapper
{
    /** @var array<string, class-string> */
    private static array $wrapped = [];

    /**
     * @param class-string $class
     *
     * @return class-string
     */
    public static function wrap(string $class): string
    {
        // Memo first: the trait walk below is the expensive part, and
        // only a wrapped class is ever recorded here.
        if (isset(self::$wrapped[$class])) {
            return self::$wrapped[$class];
        }

        if (!trait_exists(\Spatie\Snapshots\MatchesSnapshots::class)
            || !in_array(\Spatie\Snapshots\MatchesSnapshots::class, self::traitsOf($class), true)) {
            return $class;
        }

        // A final test class is ordinary style, and extending one is an
        // uncatchable compile fatal — the wrapper would die naming
        // SnapshotIdentity_<md5> for a decision the user made in their
        // own file. Refused before eval(), like every other failure
        // reflection can see (D-119).
        if ((new ReflectionClass($class))->isFinal()) {
            throw new ConfigurationException(sprintf(
                'Cannot give %s its snapshot identity: the class is final, and the wrapper has to extend '
                    . 'it. Drop `final`, or move the MatchesSnapshots trait onto a non-final base.',
                $class,
            ));
        }

        $short = 'SnapshotIdentity_' . md5($class);
        $fqcn  = __NAMESPACE__ . '\\Composed\\' . $short;

        if (!class_exists($fqcn, false)) {
            GeneratedCode::evaluate(sprintf(
                <<<'PHP'
                namespace %s;

                final class %s extends \%s
                {
                    protected function getSnapshotDirectory(): string
                    {
                        return \%s\SnapshotFileContext::directory();
                    }

                    protected function getSnapshotId(?string $id = null): string
                    {
                        $suffix = $id !== null ? ('s-' . $id) : $this->snapshotIncrementor;

                        return sprintf(
                            '%%s__%%s__%%s',
                            \%s\SnapshotFileContext::basename(),
                            str_replace(' ', '_', str_replace('__pest_evaluable_', '', $this->nameWithDataSet())),
                            $suffix,
                        );
                    }
                }
                PHP,
                __NAMESPACE__ . '\\Composed',
                $short,
                $class,
                __NAMESPACE__,
                __NAMESPACE__,
            ), 'the snapshot identity wrapper for ' . $class);
        }

        /** @var class-string $fqcn */
        return self::$wrapped[$class] = $fqcn;
    }

    /**
     * Every trait the class actually has. `class_uses()` alone answers
     * a narrower question — the traits declared on THAT class — so it
     * sees neither a parent's traits nor a trait's own, and a test
     * class inheriting MatchesSnapshots from a base would go unwrapped
     * while keeping spatie's directory and id.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function traitsOf(string $class): array
    {
        $pending = [];

        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            $uses = class_uses($current);

            if ($uses !== false) {
                $pending = [...$pending, ...array_values($uses)];
            }
        }

        $found = [];

        while ($pending !== []) {
            $trait = array_shift($pending);

            if (in_array($trait, $found, true)) {
                continue;
            }

            $found[] = $trait;
            $uses    = class_uses($trait);

            if ($uses !== false) {
                $pending = [...$pending, ...array_values($uses)];
            }
        }

        return $found;
    }
}
