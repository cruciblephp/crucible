<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Mutation;

use LucianoPereira\Crucible\Coverage\MutationIndex;
use LucianoPereira\Crucible\Coverage\SourceAnalysis;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use PhpToken;

use function abs;
use function array_filter;
use function array_flip;
use function array_values;
use function count;
use function file_get_contents;
use function substr_count;

/**
 * The tests that judge a mutant, on lines line coverage cannot see.
 *
 * An attribute argument, a property default or an inner line of an
 * array literal compiles to no opcode of its own, so the driver never
 * reports that line, while an executable line nobody ran is reported
 * as -1. An unreported line takes the covering tests of the nearest
 * reported line of its method (an attribute belongs to the method it
 * decorates) or, outside every method, of all the file's methods. A
 * reported line nobody ran stays not covered, and so does every line
 * of a file the map holds no lines for.
 */
final class CoveringTests
{
    /** @var array<string, array{methods: list<array{int, int}>, attributes: array<int, int>}> */
    private array $shapes = [];

    /** @var array<string, array<int, int>> */
    private array $reported = [];

    public function __construct(
        private readonly MutationIndex $index,
        private readonly TestLineMap $map,
    ) {}

    /**
     * @param non-empty-string $absoluteFile
     *
     * @return list<array{id: string, duration: ?float}>
     */
    public function of(string $absoluteFile, string $relativeFile, int $line): array
    {
        $direct = $this->index->coveringTests($relativeFile, $line);

        if ($direct !== [] || !isset($this->map->executable[$relativeFile])) {
            return $direct;
        }

        $this->reported[$relativeFile] ??= array_flip($this->map->executable[$relativeFile]);

        if (isset($this->reported[$relativeFile][$line])) {
            return [];
        }

        $shape = $this->shape($absoluteFile);
        $owner = $shape['attributes'][$line] ?? $line;

        foreach ($shape['methods'] as [$start, $end]) {
            if ($owner >= $start && $owner <= $end) {
                $nearest = $this->nearest($this->map->executable[$relativeFile], $start, $end, $line);

                return $nearest === null ? [] : $this->index->coveringTests($relativeFile, $nearest);
            }
        }

        $inMethods = array_values(array_filter(
            $this->map->executable[$relativeFile],
            static function (int $reported) use ($shape): bool {
                foreach ($shape['methods'] as [$start, $end]) {
                    if ($reported >= $start && $reported <= $end) {
                        return true;
                    }
                }

                return false;
            },
        ));

        return $this->index->coveringAny($relativeFile, $inMethods);
    }

    /**
     * @param list<int> $reported
     */
    private function nearest(array $reported, int $start, int $end, int $line): ?int
    {
        $nearest = null;

        foreach ($reported as $candidate) {
            if ($candidate < $start || $candidate > $end) {
                continue;
            }

            // Ties go to the later line: the body follows an attribute.
            if ($nearest === null || abs($candidate - $line) < abs($nearest - $line)
                || (abs($candidate - $line) === abs($nearest - $line) && $candidate > $nearest)
            ) {
                $nearest = $candidate;
            }
        }

        return $nearest;
    }

    /**
     * The file's method ranges, and each attribute line of a method
     * mapped to the line of its `function` keyword.
     *
     * @param non-empty-string $absoluteFile
     *
     * @return array{methods: list<array{int, int}>, attributes: array<int, int>}
     */
    private function shape(string $absoluteFile): array
    {
        if (isset($this->shapes[$absoluteFile])) {
            return $this->shapes[$absoluteFile];
        }

        $methods = [];

        foreach (SourceAnalysis::of($absoluteFile)->classes as $class) {
            foreach ($class->methods as $method) {
                $methods[] = [$method->startLine, $method->endLine];
            }
        }

        $source = @file_get_contents($absoluteFile);

        return $this->shapes[$absoluteFile] = [
            'methods'    => $methods,
            'attributes' => $source === false ? [] : $this->attributeLines(PhpToken::tokenize($source)),
        ];
    }

    /**
     * @param array<PhpToken> $tokens
     *
     * @return array<int, int> attribute line => line of the function it decorates
     */
    private function attributeLines(array $tokens): array
    {
        $owned   = [];
        $pending = [];
        $count   = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_ATTRIBUTE)) {
                $depth = 1;
                $from  = $token->line;

                while ($depth > 0 && ++$i < $count) {
                    if ($tokens[$i]->is(T_ATTRIBUTE) || $tokens[$i]->text === '[') {
                        $depth++;
                    } elseif ($tokens[$i]->text === ']') {
                        $depth--;
                    }
                }

                $to = $i < $count ? $tokens[$i]->line + substr_count($tokens[$i]->text, "\n") : $from;

                for ($line = $from; $line <= $to; $line++) {
                    $pending[] = $line;
                }

                continue;
            }

            if ($pending === [] || $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY])) {
                continue;
            }

            if ($token->is(T_FUNCTION)) {
                foreach ($pending as $line) {
                    $owned[$line] = $token->line;
                }
            }

            $pending = [];
        }

        return $owned;
    }
}
