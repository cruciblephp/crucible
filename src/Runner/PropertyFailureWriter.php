<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;

use function strrpos;
use function substr;

/**
 * Persists falsified-property choice sequences off the event stream
 * (D-040) — a listener, exactly like ResultCacheWriter, so worker
 * results count and only the supervisor side ever writes the file.
 * Saves once at run:finish, merged into whatever is already stored.
 *
 * Pruning (D-071): a key whose stored sequences all replayed clean on
 * a passing first attempt is fixed — its entry is dropped before the
 * merge. When the run was complete (nothing narrowed or halted it),
 * keys whose test no longer exists are dropped too; an entry that
 * replayed as inert reports no clean signal and survives, exactly as
 * D-040 promised.
 */
final class PropertyFailureWriter implements Listener
{
    private const string KEY_SEPARATOR = '#property ';

    /** @var array<string, list<list<int>>> */
    private array $collected = [];

    /** @var array<string, true> */
    private array $clean = [];

    /** @var array<string, true> */
    private array $finished = [];

    /**
     * @param non-empty-string $file
     */
    public function __construct(
        private readonly string $file,
    ) {}

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $this->finished[$event->test->toString()] = true;

            $property = $event->property;

            if ($property !== null) {
                $this->collected[$property['key']][] = $property['choices'];
            }

            foreach ($event->propertyClean as $key) {
                $this->clean[$key] = true;
            }

            return;
        }

        if ($event instanceof RunFinished) {
            $stored = PropertyFailures::load($this->file);
            $kept   = [];

            foreach ($stored as $key => $sequences) {
                if (isset($this->clean[$key])) {
                    continue;
                }

                if ($event->complete && !isset($this->finished[$this->testIdOf($key)])) {
                    continue;
                }

                $kept[$key] = $sequences;
            }

            $merged = PropertyFailures::merge($kept, $this->collected);

            if ($merged !== $stored) {
                PropertyFailures::save($this->file, $merged);
            }
        }
    }

    /**
     * The test-id half of a database key. Keys are built as
     * `TestId::toString() . '#property N'`; the id itself may contain
     * `#` (dataset rows), so the split is on the LAST separator.
     */
    private function testIdOf(string $key): string
    {
        $at = strrpos($key, self::KEY_SEPARATOR);

        return $at === false ? $key : substr($key, 0, $at);
    }
}
