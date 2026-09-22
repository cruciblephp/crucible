<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension\Duplication;

use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\PhpcpdNext\CodeClone;
use LucianoPereira\PhpcpdNext\CodeCloneMap;
use Override;

use function count;
use function implode;
use function sprintf;

/**
 * Passes when a CodeCloneMap is empty.
 *
 * The in-test half of the duplication surface. {@see DuplicationCheck} is
 * run-scoped and votes on the exit code; this fails one test, so a clone
 * can be pinned to the code that must not grow one.
 *
 * Wording and layout are the incumbent integration's, deliberately: a
 * suite ported from phpcpd-next's own PHPUnit trait reads the same
 * failure here.
 */
final class DuplicationConstraint extends Constraint
{
    public function toString(): string
    {
        return 'contains no duplicated code';
    }

    #[Override]
    protected function matches(mixed $other): bool
    {
        return $other instanceof CodeCloneMap && $other->count() === 0;
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return 'Failed asserting that the scanned code ' . $this->toString() . '.';
    }

    #[Override]
    protected function additionalFailureDescription(mixed $other): string
    {
        if (!$other instanceof CodeCloneMap) {
            return '';
        }

        $lines = [];

        foreach ($other->clones() as $clone) {
            $lines[] = '  ' . $this->describeClone($clone);
        }

        return sprintf(
            "\n%d clone%s found:\n%s",
            count($lines),
            count($lines) === 1 ? '' : 's',
            implode("\n", $lines),
        );
    }

    private function describeClone(CodeClone $clone): string
    {
        $where = [];

        foreach ($clone->files() as $file) {
            // Properties, not name()/startLine(): phpcpd-next 2.0 drops
            // those accessors and keeps the public readonly properties.
            $where[] = $file->name . ':' . $file->startLine;
        }

        return sprintf(
            '%s%d lines @ %s',
            $clone->isGapped() ? '[inconsistent] ' : '',
            $clone->numberOfLines(),
            implode(' ↔ ', $where),
        );
    }
}
