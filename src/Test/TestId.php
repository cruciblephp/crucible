<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Test;

use function hash;
use function strpos;
use function substr;

/**
 * Dialect-neutral, stable test identity (DESIGN.md D-008/D-010).
 *
 * A test is identified by the file that declares it, the name it is
 * declared under inside that file (a method name, a description — the
 * dialect frontend decides), and an optional dataset key. No class or
 * method semantics leak into the engine.
 *
 * The string form is what appears on the event stream; the hash is the
 * stable basis for `--partition hash:m/n` sharding: adding or removing
 * tests never moves other tests between shards.
 */
final readonly class TestId
{
    /**
     * @param non-empty-string $file    project-relative path of the declaring file
     * @param non-empty-string $name    declared name within the file
     * @param ?non-empty-string $dataset dataset row key, when parameterized
     */
    public function __construct(
        public string $file,
        public string $name,
        public ?string $dataset = null,
    ) {}

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->file . '::' . $this->name . ($this->dataset !== null ? '#' . $this->dataset : '');
    }

    /**
     * Inverse of toString(), for consumers that receive ids over the
     * event stream (the supervisor reading worker output). Returns
     * null for a string that no toString() could have produced.
     *
     * @crucible expect(TestId::fromString('a.php::sums#row 1')?->dataset)->toBe('row 1')
     * @crucible expect(TestId::fromString('no separator'))->toBeNull()
     */
    public static function fromString(string $id): ?self
    {
        $separator = strpos($id, '::');

        if ($separator === false || $separator === 0) {
            return null;
        }

        $file = substr($id, 0, $separator);
        $rest = substr($id, $separator + 2);

        $marker  = strpos($rest, '#');
        $name    = $marker === false ? $rest : substr($rest, 0, $marker);
        $dataset = $marker === false ? null : substr($rest, $marker + 1);

        if ($name === '' || $dataset === '') {
            return null;
        }

        return new self($file, $name, $dataset);
    }

    /**
     * @return non-empty-string
     */
    public function hash(): string
    {
        return hash('xxh3', $this->toString());
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }
}
