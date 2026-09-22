<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use function in_array;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_replace;

/**
 * The seeded table `compat-check --auto-fix` actually knows how to
 * rewrite. Every pattern here was verified against a real suite
 * (Monolog 3.9.0, 2026-07-28 session) and re-checked against the real
 * PHPUnit oracle after rewriting, so a fix doesn't just weaken the
 * test into a vacuous pass — including a real correction found by
 * that same re-check: a first version of the vendor-path pattern
 * matched ANY frame index, but only frame 0 (the immediate call site)
 * is structurally guaranteed to be some TestCase.php in every engine.
 * Frame 1+ reflects each engine's own internal call depth, which does
 * NOT line up — real PHPUnit's frame 1 is also TestCase.php, but
 * Crucible's is a different file entirely. The two non-immediate-frame
 * patterns below exist specifically to still close that case, honestly
 * (weakened to "this frame exists", never claiming which file it's
 * in) rather than either guessing wrong or leaving it unfixed.
 *
 * A fourth real shape found in that same audit is deliberately NOT
 * here: a composite `assertEquals()` against a whole array/object
 * literal with a PHPUnit-internal value buried inside one of its
 * fields. Fixing that needs understanding the surrounding structure,
 * not just one call's literal argument — outside what a single-call
 * token rewrite can safely do. It stays diagnosed, not auto-fixed.
 * Extend this table only with patterns verified the same way: fixed
 * by hand against a real failure, then re-checked against the real
 * oracle — never by guessing at a plausible-looking transform.
 */
final readonly class KnownCouplingPatterns
{
    /**
     * PHPUnit-only internal filenames with no Crucible equivalent at
     * all — no path prefix to relax, so containment can only be
     * weakened to a structural "some stacktrace frame exists" check.
     */
    private const array NO_EQUIVALENT_FILENAMES = ['TestSuite.php'];

    /**
     * Order matters: `SourceRewriter` tries these in array order and
     * stops at the first match, so the two non-immediate-frame
     * patterns (more specific) must come before the general one
     * (which would otherwise wrongly claim a specific file for a
     * frame that does not structurally guarantee one).
     *
     * @return list<CouplingPattern>
     */
    public static function all(): array
    {
        return [
            // Frame N>=1 with an explicit "#N " marker inside the
            // literal itself (LineFormatterTest::testBasePath's
            // shape). The frame-number text is real and verifiable in
            // both engines; the specific file at that frame is not
            // (real PHPUnit's frame 1 is also TestCase.php -- two
            // consecutive TestCase.php frames, its own internal
            // quirk -- but Crucible's frame 1 is TestBuilder.php, a
            // different file). Weaken to "this frame exists", not
            // which file it names.
            new CouplingPattern(
                description: 'Asserts a literal vendor/phpunit/phpunit path at a non-immediate stack frame',
                methods: ['assertStringContainsString', 'assertStringStartsWith'],
                matchesLiteral: static fn(string $literal, string $restArgs): bool => str_contains($literal, 'vendor/phpunit/phpunit')
                    && preg_match('/^.*?#\d+\s+vendor\/phpunit\/phpunit/', $literal) === 1
                    && !self::isImmediateFrame($literal, $restArgs),
                rewrite: static function (string $literal, string $restArgs): string {
                    preg_match('/^(.*?#\d+\s+)/', $literal, $match);

                    return 'assertMatchesRegularExpression(\'/' . preg_quote($match[1] ?? '', '/') . '\S+/\', ' . $restArgs . ')';
                },
            ),
            // Frame N>=1 via a trailing "[N]" array index with NO
            // frame marker in the literal at all
            // (NormalizerFormatterTest's shape: the identical literal
            // is reused for every frame, only the expression's index
            // distinguishes them). Nothing in the literal is worth
            // preserving for a non-immediate frame, so the honest
            // weakening is confirming that frame has some string
            // content, not what it says.
            new CouplingPattern(
                description: 'Asserts a literal vendor/phpunit/phpunit path at a non-immediate array-indexed frame',
                methods: ['assertStringContainsString', 'assertStringStartsWith'],
                matchesLiteral: static fn(string $literal, string $restArgs): bool => str_contains($literal, 'vendor/phpunit/phpunit')
                    && preg_match('/\[\s*(\d+)\s*]\s*$/', $restArgs, $match) === 1
                    && $match[1] !== '0',
                rewrite: static fn(string $literal, string $restArgs): string => 'assertIsString(' . $restArgs . ')',
            ),
            // Frame 0 (the immediate call site) -- structurally
            // guaranteed to be some TestCase.php in every engine, so
            // preserving the file-name suffix is safe.
            new CouplingPattern(
                description: 'Asserts a literal vendor/phpunit/phpunit install path',
                methods: ['assertStringContainsString', 'assertStringStartsWith'],
                matchesLiteral: static fn(string $literal, string $restArgs): bool => str_contains($literal, 'vendor/phpunit/phpunit')
                    && self::isImmediateFrame($literal, $restArgs),
                rewrite: static function (string $literal, string $restArgs): string {
                    $pattern = str_replace(
                        preg_quote('vendor/phpunit/phpunit', '/'),
                        '\S*',
                        preg_quote($literal, '/'),
                    );

                    return 'assertMatchesRegularExpression(\'/' . $pattern . '/\', ' . $restArgs . ')';
                },
            ),
            new CouplingPattern(
                description: 'Asserts a PHPUnit-only internal filename Crucible has no equivalent for',
                methods: ['assertStringContainsString'],
                matchesLiteral: static fn(string $literal, string $restArgs): bool => in_array($literal, self::NO_EQUIVALENT_FILENAMES, true),
                rewrite: static fn(string $literal, string $restArgs): string => 'assertMatchesRegularExpression(\'/#\d+ /\', ' . $restArgs . ')',
            ),
        ];
    }

    /**
     * Whether this occurrence is checking the immediate call frame
     * (index 0) specifically, as opposed to a deeper one — the only
     * case verified safe to rewrite (see class docblock). Checks both
     * signals a real occurrence might carry: an explicit "#N " frame
     * marker inside the literal itself (LineFormatterTest's shape), or
     * a trailing "[N]" array index in the asserted-on expression
     * (NormalizerFormatterTest's shape, where the literal is identical
     * for every frame and only the expression distinguishes them). No
     * signal present at all (neither marker) is treated as frame 0,
     * matching every real occurrence seen so far.
     */
    private static function isImmediateFrame(string $literal, string $restArgs): bool
    {
        if (preg_match('/#(\d+)\s/', $literal, $match) === 1 && $match[1] !== '0') {
            return false;
        }

        if (preg_match('/\[\s*(\d+)\s*]\s*$/', $restArgs, $match) === 1 && $match[1] !== '0') {
            return false;
        }

        return true;
    }
}
