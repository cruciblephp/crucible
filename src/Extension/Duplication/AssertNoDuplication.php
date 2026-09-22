<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension\Duplication;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\PhpcpdNext\Phpcpd;

use function class_exists;

/**
 * Copy/paste detection as a regression test, over phpcpd-next.
 *
 *     final class DuplicationTest extends TestCase
 *     {
 *         use AssertNoDuplication;
 *
 *         public function testTheAppIsDry(): void
 *         {
 *             $this->assertNoDuplication(__DIR__ . '/../app', minTokens: 70);
 *         }
 *     }
 *
 * Detection runs in-process through {@see Phpcpd::detect()} — no binary,
 * no temp files. The signature and defaults are the incumbent trait's, so
 * a suite written against phpcpd-next's PHPUnit integration runs here
 * unchanged.
 *
 * @phpcpd-keep Used by the USER in their own tests, never by Crucible, so no
 * reference to it can exist in src/.
 */
trait AssertNoDuplication
{
    /**
     * @param list<non-empty-string>|non-empty-string $paths     directories to scan
     * @param ?non-empty-string                       $algorithm null = phpcpd's default strategy
     * @param list<non-empty-string>                  $exclude   substring/glob patterns to skip
     * @param list<non-empty-string>                  $suffixes  file suffixes to include
     * @param ?non-empty-string                       $preset    a phpcpd preset name, e.g. 'laravel'
     */
    final protected function assertNoDuplication(
        string|array $paths = [],
        int $minTokens = 70,
        int $minLines = 5,
        ?string $algorithm = null,
        array $exclude = [],
        array $suffixes = ['.php'],
        ?string $preset = null,
        string $message = '',
    ): void {
        // Same guard and same wording as DuplicationCheck: phpcpd-next is
        // not a dependency of Crucible, so its absence is a configuration
        // answer rather than a fatal about a missing class.
        if (!class_exists(Phpcpd::class)) {
            throw new ConfigurationException(
                'The duplication check needs phpcpd-next: composer require --dev phpcpd-next/phpcpd',
            );
        }

        Assert::assertThat(
            Phpcpd::detect(
                paths: $paths,
                minLines: $minLines,
                minTokens: $minTokens,
                algorithm: $algorithm,
                exclude: $exclude,
                suffixes: $suffixes,
                preset: $preset,
            ),
            new DuplicationConstraint(),
            $message,
        );
    }
}
