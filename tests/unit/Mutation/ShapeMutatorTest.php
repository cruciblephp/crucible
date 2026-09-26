<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\MutantGenerator;
use LucianoPereira\Crucible\Mutation\ShapeMutator;
use PhpToken;

use function array_map;
use function preg_match;
use function str_contains;
use function str_replace;

use const TOKEN_PARSE;

/**
 * A returned array's keys, one at a time (D-134): every mutant is the
 * same array with exactly one string-keyed element gone, and still
 * valid PHP.
 */
#[CoversClass(ShapeMutator::class)]
#[CoversClass(MutantGenerator::class)]
final class ShapeMutatorTest extends TestCase
{
    private const string SOURCE = <<<'PHP'
        <?php
        final class UserExport
        {
            public function toArray(): array
            {
                return [
                    'id'     => $this->id,
                    'email'  => $this->email,
                    'tags'   => ['a', 'b'],
                    'nested' => ['city' => $this->city],
                ];
            }

            public function positional(): array
            {
                return [1, 2, 3];
            }
        }
        PHP;

    public function testEachStringKeyIsDroppedOnceAndTheResultStillParses(): void
    {
        $mutants = (new MutantGenerator([new ShapeMutator()]))->generate('/p/UserExport.php', 'UserExport', self::SOURCE);

        self::assertCount(4, $mutants, 'one mutant per top-level string key; the positional array has none');

        $dropped = [];

        foreach ($mutants as $mutant) {
            self::assertSame('shape:drop-key', $mutant->mutatorId);
            self::assertSame(1, preg_match('/return \[/', $mutant->mutatedSource));

            // Valid PHP: a mutant that does not parse would be an error,
            // not a verdict about the tests. TOKEN_PARSE throws on one.
            self::assertNotSame([], PhpToken::tokenize($mutant->mutatedSource, TOKEN_PARSE));

            foreach (['id', 'email', 'tags', 'nested'] as $key) {
                if (!str_contains($mutant->mutatedSource, "'" . $key . "'")) {
                    $dropped[] = $key;
                }
            }
        }

        self::assertSame(['id', 'email', 'tags', 'nested'], $dropped);
        self::assertSame([7, 8, 9, 10], array_map(static fn($m): int => $m->line, $mutants));
    }

    public function testOnlyAStringKeyedElementIsDroppedAndExactlyIt(): void
    {
        // A positional string is a value, not a key; the last element has
        // no trailing comma to take with it; array() and a returned call
        // are no literal this reads.
        $source = <<<'PHP'
            <?php
            function a(): array { return ['plain', 'k' => 1]; }
            function b(): array { return ['k' => 1, 'plain']; }
            function c(): array { return array('k' => 1); }
            function d(): array { return e(['k' => 1]); }
            PHP;

        $mutated = array_map(
            static fn($mutant): string => $mutant->mutatedSource,
            (new MutantGenerator([new ShapeMutator()]))->generate('/p/f.php', 'f', $source),
        );

        self::assertSame([
            str_replace("['plain', 'k' => 1]", "['plain',  ]", $source),
            str_replace("['k' => 1, 'plain']", "[  'plain']", $source),
        ], $mutated);
    }
}
