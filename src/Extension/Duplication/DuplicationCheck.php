<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension\Duplication;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Extension\Artifact\Artifact;
use LucianoPereira\Crucible\Extension\Artifact\Claim;
use LucianoPereira\Crucible\Extension\Check;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\PhpcpdNext\CodeClone;
use LucianoPereira\PhpcpdNext\Phpcpd;

use function array_slice;
use function class_exists;
use function count;
use function implode;
use function sprintf;

/**
 * Copy-paste duplication as a run-scoped check, over phpcpd-next.
 *
 * The check role's own documentation uses this tool as its example — "a
 * plugin presents facts and never judges: phpcpd presents (clones found,
 * 0, the locations), and Crucible is the one that tests 3 === 0" (D-078)
 * — so this is that sentence, built. The plugin runs the detection and
 * reports what it saw; the threshold, the verdict, the message and the
 * exit-code vote stay Crucible's.
 *
 * phpcpd-next is not a dependency of Crucible and never becomes one: a
 * test framework that dragged a duplication detector into every install
 * would be charging everyone for a tool most projects do not run. The
 * class is absent until someone requires it, and asking for the check
 * without the package says so by name rather than fataling on a missing
 * class.
 *
 * Registered like any other extension, in `crucible.php`:
 *
 *     ->extension(new DuplicationCheck(paths: ['src'], preset: 'laravel'))
 *
 * The defaults are phpcpd's own, not opinions of Crucible's: 5 lines and
 * 70 tokens is what the tool considers a clone unless told otherwise.
 *
 * @phpcpd-keep Constructed by the USER in crucible.php, never by Crucible, so no
 * reference to it can exist in src/. `keep` rather than `planned`: it is wired
 * now, from outside, and will never gain an internal caller to prompt removal.
 */
final readonly class DuplicationCheck implements Check
{
    /**
     * @param list<non-empty-string>|non-empty-string $paths     directories to scan; a preset supplies its own when this is empty
     * @param int<0, max>                             $maxClones how many clones the project tolerates — 0 is the point of the check
     * @param list<non-empty-string>                  $exclude   substring/glob patterns to skip, merged after a preset's
     * @param list<non-empty-string>                  $suffixes  file suffixes to include
     * @param ?non-empty-string                       $preset    a phpcpd preset name, e.g. 'laravel'
     * @param ?non-empty-string                       $algorithm null = phpcpd's default strategy
     */
    public function __construct(
        private array|string $paths = [],
        private int $maxClones = 0,
        private int $minLines = 5,
        private int $minTokens = 70,
        private array $exclude = [],
        private array $suffixes = ['.php'],
        private ?string $preset = null,
        private ?string $algorithm = null,
        private bool $fuzzy = false,
        private bool $typeAnchored = false,
    ) {}

    public function label(): string
    {
        return 'Duplication';
    }

    public function inspect(WorkingDirectory $workingDirectory): Artifact
    {
        if (!class_exists(Phpcpd::class)) {
            throw new ConfigurationException(
                'The duplication check needs phpcpd-next: composer require --dev phpcpd-next/phpcpd',
            );
        }

        $clones = Phpcpd::detect(
            paths: $this->paths,
            minLines: $this->minLines,
            minTokens: $this->minTokens,
            algorithm: $this->algorithm,
            exclude: $this->exclude,
            suffixes: $this->suffixes,
            preset: $this->preset,
            fuzzy: $this->fuzzy,
            typeAnchored: $this->typeAnchored,
        );

        $found = $clones->count();

        return new Claim(
            actual: $found,
            expected: $this->maxClones,
            detail: $found > $this->maxClones ? $this->locations($clones->clones()) : null,
        );
    }

    /**
     * Where the duplication is, which is the only part of the finding
     * that tells anyone what to do about it. Capped, because a project
     * that has just switched the check on can have hundreds and a wall
     * of them buries the count that matters.
     *
     * @param list<CodeClone> $clones
     *
     * @return non-empty-string
     */
    private function locations(array $clones): string
    {
        $lines = [];

        foreach (array_slice($clones, 0, self::SHOWN) as $clone) {
            $places = [];

            foreach ($clone->files() as $file) {
                // Properties, not name()/startLine(): phpcpd-next 2.0 drops
                // those accessors and keeps the public readonly properties.
                $places[] = $file->name . ':' . $file->startLine;
            }

            $lines[] = sprintf(
                '  %d lines duplicated across %s',
                $clone->numberOfLines(),
                implode(' ↔ ', $places),
            );
        }

        $remaining = count($clones) - self::SHOWN;

        if ($remaining > 0) {
            $lines[] = sprintf('  ... and %d more', $remaining);
        }

        $rendered = implode("\n", $lines);

        return $rendered === '' ? 'duplication was reported without locations' : $rendered;
    }

    /** How many clone sites the failure detail names before summarising. */
    private const int SHOWN = 10;
}
