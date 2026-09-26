<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Types;

/**
 * Type tests (D-130): `*.types.php` files whose `assertType()` calls, and
 * lines marked `// crucible-type-error <identifier>`, are tests in the
 * run. PHPStan analyses the files once; every assertion becomes a test
 * with its own verdict, folded into the tree, the report and the exit
 * code the way the Vitest suite is (D-079). Vitest's typecheck mode is
 * the model: the same idea for a language whose type checker is PHPStan.
 *
 * Registered with `->typeTests(...)` in `crucible.php`; named `types`, so
 * `--testsuite types` and `--exclude-testsuite types` select it (D-125).
 */
final readonly class TypeTestSuite
{
    /**
     * @param non-empty-string  $directory     where the *.types.php files live
     * @param non-empty-string  $name          what --testsuite calls it
     * @param ?non-empty-string $phpstan       the phpstan executable; null = vendor/bin/phpstan
     * @param ?non-empty-string $configuration a phpstan configuration; null = the project's phpstan.neon(.dist), if any
     * @param ?non-empty-string $filter        the run's --filter, when this suite was named with --testsuite
     */
    public function __construct(
        public string $directory = 'tests',
        public string $name = 'types',
        public ?string $phpstan = null,
        public ?string $configuration = null,
        public ?string $filter = null,
    ) {}

    /**
     * @param non-empty-string $filter
     */
    public function filtered(string $filter): self
    {
        return new self($this->directory, $this->name, $this->phpstan, $this->configuration, $filter);
    }
}
