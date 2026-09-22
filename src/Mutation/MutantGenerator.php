<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use PhpToken;

use function array_values;

/**
 * Turns a file's source into the list of {@see Mutant}s the catalog can
 * make from it. It tokenizes once, asks each mutator where it would
 * change something, and for every such point rebuilds the whole source
 * with that one token replaced — token-text concatenation reproduces the
 * original byte for byte, so a mutant differs from the original by
 * exactly its one operator and nothing else.
 *
 * Pure and side-effect free: it reads no files and runs no tests. The
 * class name (the autoload key) and source are given; the CLI resolves
 * the class with {@see \LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator}.
 */
final readonly class MutantGenerator
{
    /** @var list<Mutator> */
    private array $mutators;

    /**
     * @param ?list<Mutator> $mutators null selects the default catalog
     */
    public function __construct(?array $mutators = null)
    {
        $this->mutators = $mutators ?? [
            new ArithmeticMutator(),
            new ComparisonMutator(),
            new LogicalMutator(),
        ];
    }

    /**
     * @param non-empty-string $file  absolute path of the original file
     * @param non-empty-string $class the FQCN it declares
     *
     * @return list<Mutant>
     */
    public function generate(string $file, string $class, string $source): array
    {
        $tokens  = array_values(PhpToken::tokenize($source));
        $mutants = [];

        foreach ($this->mutators as $mutator) {
            foreach ($mutator->mutate($tokens) as $mutation) {
                $mutants[] = new Mutant($file, $class, $mutation->line, $mutator->id(), $this->rebuild($tokens, $mutation));
            }
        }

        return $mutants;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private function rebuild(array $tokens, TokenMutation $mutation): string
    {
        $source = '';

        foreach ($tokens as $index => $token) {
            $source .= $index === $mutation->index ? $mutation->replacement : $token->text;
        }

        return $source;
    }
}
