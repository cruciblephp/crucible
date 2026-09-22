<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use DateTimeImmutable;

use function serialize;

use const PHP_EOL;
use const PHP_VERSION;

/**
 * The spec's --coverage-php: a PHP file that returns the run's coverage
 * data, for a later process to load instead of re-collecting it. The
 * spec serializes its own object graph here, so parity is the *shape*
 * (a `return unserialize(<<<'...')` file with build information beside
 * the data) rather than the payload — Crucible returns Crucible's own
 * CoverageData, which is what could ever be loaded back.
 */
final readonly class PhpWriter
{
    private const string FORMAT = 'crucible-coverage-1';

    /**
     * @param non-empty-string $driver
     * @param ?array{originUrl: ?string, branch: ?string, commit: ?string, isClean: bool} $git the spec's --include-git-information
     */
    public function write(CoverageData $data, string $driver, ?array $git = null, ?DateTimeImmutable $at = null): string
    {
        $payload = [
            'buildInformation' => [
                'timestamp' => ($at ?? new DateTimeImmutable())->format('D M j G:i:s T Y'),
                'runtime'   => ['name' => 'PHP', 'version' => PHP_VERSION],
                'crucible'  => ['format' => self::FORMAT, 'driver' => $driver],
            ],
            'coverage' => ['lines' => $data->lines, 'branches' => $data->branches, 'tests' => $data->tests],
        ];

        if ($git !== null) {
            $payload['buildInformation']['git'] = $git;
        }

        return '<?php // crucible coverage serialization format ' . self::FORMAT . PHP_EOL
            . "return \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'" . PHP_EOL
            . serialize($payload) . PHP_EOL
            . 'END_OF_COVERAGE_SERIALIZATION' . PHP_EOL
            . ');' . PHP_EOL;
    }
}
