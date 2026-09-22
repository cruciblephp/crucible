<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use Closure;

/**
 * One known, real shape of a test that couples to PHPUnit's own
 * internals rather than the code under test — seeded from actual
 * compat-audit findings, not guessed. Deliberately narrow:
 * `SourceRewriter` only ever applies a pattern when its own
 * `matchesLiteral` returns true for the exact literal found as the
 * first argument of a call to one of `methods` — nothing here
 * attempts to understand arbitrary assertions.
 *
 * `matchesLiteral` sees the rest-of-args text too, not just the
 * literal: a call stack's frame 0 (the immediate call site) is
 * structurally guaranteed to be some TestCase.php in every engine,
 * but frame 1+ reflects each engine's own internal call depth, which
 * does NOT line up between them (verified: real PHPUnit's frame 1 is
 * also TestCase.php; Crucible's is a different file entirely) — a
 * pattern needs both pieces of context to tell which case it is in.
 */
final readonly class CouplingPattern
{
    /**
     * @param list<non-empty-string>          $methods        assertion method names this pattern watches
     * @param Closure(string, string): bool   $matchesLiteral (literal, rest-of-args raw text) => whether to apply
     * @param Closure(string, string): string $rewrite        (literal, rest-of-args raw text) => new "methodName(args)" text
     */
    public function __construct(
        public string $description,
        public array $methods,
        public Closure $matchesLiteral,
        public Closure $rewrite,
    ) {}
}
