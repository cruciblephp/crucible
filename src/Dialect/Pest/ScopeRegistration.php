<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use Closure;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function array_merge;
use function array_values;
use function class_exists;
use function sprintf;
use function trait_exists;

/**
 * One pest()/uses() chain: a test-case class, traits, groups, and
 * hooks, optionally scoped to paths via ->in(). Mutable while its
 * declaring file loads; the builder reads the final state.
 *
 * Scoping rules (spec §3): ->in() globs select files under the
 * declaring file's directory. Without ->in(), a registration from a
 * test file is file-local, and a registration from Pest.php applies
 * its *hooks* to the whole suite tree (the spec's global hooks) while
 * class/trait/group assignments stay inert.
 */
final class ScopeRegistration
{
    /** @var ?class-string */
    public ?string $class = null;

    /** @var list<class-string> */
    public array $traits = [];

    /** @var list<non-empty-string> */
    public array $groups = [];

    /**
     * null = bare (no ->in() yet).
     *
     * @var ?list<non-empty-string>
     */
    public ?array $globs = null;

    /** @var list<Closure> */
    public array $beforeEach = [];

    /** @var list<Closure> */
    public array $afterEach = [];

    /** @var list<Closure> */
    public array $beforeAll = [];

    /** @var list<Closure> */
    public array $afterAll = [];

    /**
     * @param non-empty-string $baseDir directory of the declaring file
     */
    public function __construct(
        public readonly string $baseDir,
        public readonly bool $fromConfigFile,
    ) {}

    /** The printer chain (D-066): pest()->printer()->compact(). */
    public function printer(): PrinterSelection
    {
        return new PrinterSelection();
    }

    /**
     * @param class-string $class
     */
    public function extend(string $class): self
    {
        if ($this->class !== null) {
            throw new ConfigurationException(sprintf(
                'extend(%s): this chain already extends %s.',
                $class,
                $this->class,
            ));
        }

        $this->class = $class;

        return $this;
    }

    /**
     * @param class-string ...$traits
     */
    public function use(string ...$traits): self
    {
        foreach ($traits as $trait) {
            if (!trait_exists($trait)) {
                throw new ConfigurationException(sprintf('use(%s): the trait does not exist.', $trait));
            }

            $this->traits[] = $trait;
        }

        return $this;
    }

    /**
     * The legacy uses() spelling accepts classes and traits mixed in
     * one call; names are split by what they actually are.
     */
    public function assign(string ...$names): self
    {
        foreach ($names as $name) {
            if (trait_exists($name)) {
                $this->use($name);

                continue;
            }

            if (class_exists($name)) {
                $this->extend($name);

                continue;
            }

            throw new ConfigurationException(sprintf('uses(%s): no such class or trait.', $name));
        }

        return $this;
    }

    /**
     * @param non-empty-string ...$paths directories or file globs, relative to the declaring file's directory
     */
    public function in(string ...$paths): self
    {
        if (!$this->fromConfigFile) {
            throw new ConfigurationException(
                '->in() belongs in a Pest.php configuration file; inside a test file uses() is always file-local.',
            );
        }

        $this->globs = array_merge($this->globs ?? [], array_values($paths));

        return $this;
    }

    /**
     * @param non-empty-string ...$groups
     */
    public function group(string ...$groups): self
    {
        foreach ($groups as $group) {
            $this->groups[] = $group;
        }

        return $this;
    }

    public function beforeEach(Closure $hook): self
    {
        $this->beforeEach[] = $hook;

        return $this;
    }

    public function afterEach(Closure $hook): self
    {
        $this->afterEach[] = $hook;

        return $this;
    }

    public function beforeAll(Closure $hook): self
    {
        $this->beforeAll[] = $hook;

        return $this;
    }

    public function afterAll(Closure $hook): self
    {
        $this->afterAll[] = $hook;

        return $this;
    }

    public function hasHooks(): bool
    {
        return $this->beforeEach !== []
            || $this->afterEach !== []
            || $this->beforeAll !== []
            || $this->afterAll !== [];
    }
}
