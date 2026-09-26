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

use function count;
use function preg_match;
use function substr_count;
use function trim;

use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * Where a source declares its mutants equivalent (D-134): code whose
 * mutation no test can ever kill — `$i < count($list)` against `<=` on a
 * loop that breaks first, a log message's exact wording. Without a way
 * to say so, such a survivor stays in the score as noise forever.
 *
 * The notation is phpcpd-next's, harvested for the same job — saying in
 * the source that something is deliberate — with Crucible's prefix and
 * a reason required:
 *
 *   `@crucible-equivalent <reason>`                  the declaration that follows
 *   `// crucible-equivalent-start <reason>` … `-end`   an explicit region
 *   `// crucible-equivalent-line <reason>`           its own line
 *
 * The reason follows a space or a colon (`@crucible-equivalent: <reason>`),
 * the same in every form. A marker without a reason declares nothing, and
 * is reported like a marker that covers no mutant: a claim nobody can
 * check is not one.
 */
final readonly class EquivalentMarkers
{
    private const string DECLARATION = '@crucible-equivalent';

    private const string START = 'crucible-equivalent-start';

    private const string END = 'crucible-equivalent-end';

    private const string LINE = 'crucible-equivalent-line';

    /** What follows a marker's name: its reason, after a space or a colon. */
    private const string REASON = '(?:(?:\s*:\s*|\s+)(.+?))?\s*(?:\*\/)?$';

    /**
     * @param list<array{from: int, to: int, line: int, reason: ?non-empty-string}> $ranges
     */
    private function __construct(public array $ranges) {}

    public static function scan(string $source): self
    {
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);
        $ranges = [];
        $open   = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id !== T_COMMENT && $token->id !== T_DOC_COMMENT) {
                continue;
            }

            $text = $token->text;

            if (preg_match('/' . self::START . self::REASON . '/m', $text, $match) === 1) {
                $open = ['line' => $token->line, 'reason' => self::reason($match[1] ?? '')];

                continue;
            }

            if (preg_match('/' . self::END . '\b/', $text) === 1 && $open !== null) {
                $ranges[] = ['from' => $open['line'], 'to' => $token->line, 'line' => $open['line'], 'reason' => $open['reason']];
                $open     = null;

                continue;
            }

            if (preg_match('/' . self::LINE . self::REASON . '/m', $text, $match) === 1) {
                $ranges[] = ['from' => $token->line, 'to' => $token->line, 'line' => $token->line, 'reason' => self::reason($match[1] ?? '')];

                continue;
            }

            // In a docblock the reason ends at the line: `*` starts the next.
            if (preg_match('/' . self::DECLARATION . '(?:(?:\s*:\s*|\s+)([^\n*]+))?/', $text, $match) === 1) {
                $from     = $token->line + substr_count($text, "\n") + 1;
                $ranges[] = ['from' => $from, 'to' => self::declarationEnd($tokens, $i + 1, $from), 'line' => $token->line, 'reason' => self::reason($match[1] ?? '')];
            }
        }

        return new self($ranges);
    }

    /**
     * The reason a line is declared equivalent, or null when none is.
     *
     * @return ?non-empty-string
     */
    public function reasonFor(int $line): ?string
    {
        foreach ($this->ranges as $range) {
            if ($range['reason'] !== null && $line >= $range['from'] && $line <= $range['to']) {
                return $range['reason'];
            }
        }

        return null;
    }

    /**
     * The last line of the declaration after a marker: its body's closing
     * brace, or its own line when it has no body.
     *
     * @param array<PhpToken> $tokens as PhpToken::tokenize() returns them
     */
    private static function declarationEnd(array $tokens, int $from, int $fallback): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    return $tokens[$i]->line;
                }
            } elseif ($text === ';' && $depth === 0) {
                return $tokens[$i]->line;
            }
        }

        return $fallback;
    }

    /** @return ?non-empty-string */
    private static function reason(string $text): ?string
    {
        $reason = trim($text);

        return $reason === '' ? null : $reason;
    }
}
