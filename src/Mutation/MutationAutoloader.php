<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use function getenv;
use function is_file;
use function is_string;
use function spl_autoload_register;

/**
 * Serves one mutated class from a temp path, ahead of Composer. The same
 * mechanism drives both isolation modes: warm ({@see MutantApplier})
 * registers it dormant in the parent and arms it inside the fork; cold
 * ({@see ColdMutantExecutor}) has the spawned worker arm it from the
 * environment before the suite boots. Prepended, so it wins for exactly
 * the mutated class and defers to Composer for everything else; the
 * unique temp path means opcache compiles the mutant cleanly.
 *
 * The precondition is the same either way: the class must not be loaded
 * yet, or its declaration is fixed and the loader can never fire.
 */
final class MutationAutoloader
{
    private const string CLASS_ENV = 'CRUCIBLE_MUTANT_CLASS';

    private const string FILE_ENV = 'CRUCIBLE_MUTANT_FILE';

    /** @var ?array{class: non-empty-string, file: non-empty-string} */
    private ?array $target = null;

    private bool $registered = false;

    /**
     * The env keys the parent sets to hand a mutant to a cold worker.
     *
     * @return array{string, string}
     */
    public static function environmentKeys(): array
    {
        return [self::CLASS_ENV, self::FILE_ENV];
    }

    /**
     * Register the (dormant) loader. Idempotent: warm registers once in
     * the parent and every fork inherits it.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        spl_autoload_register(function (string $class): void {
            if ($this->target !== null && $class === $this->target['class']) {
                require $this->target['file'];
            }
        }, prepend: true);

        $this->registered = true;
    }

    /**
     * Arm the loader for a specific mutant.
     *
     * @param non-empty-string $class
     * @param non-empty-string $file
     */
    public function activate(string $class, string $file): void
    {
        $this->target = ['class' => $class, 'file' => $file];
    }

    /**
     * Cold entry: read the mutant the parent injected into the
     * environment and arm the loader before the suite boots. Null when no
     * mutant is set (an ordinary worker), so the caller no-ops.
     */
    public static function fromEnvironment(): ?self
    {
        $class = getenv(self::CLASS_ENV);
        $file  = getenv(self::FILE_ENV);

        if (!is_string($class) || $class === '' || !is_string($file) || $file === '' || !is_file($file)) {
            return null;
        }

        $loader = new self();
        $loader->register();
        $loader->activate($class, $file);

        return $loader;
    }
}
