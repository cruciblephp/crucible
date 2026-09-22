<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Snapshot;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_keys;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function rmdir;
use function rtrim;
use function scandir;
use function str_starts_with;
use function substr;
use function unlink;

/**
 * Obsolete-snapshot pruning (D-071), the deletion D-042 deferred. A
 * supervisor-side listener (the ResultCacheWriter pattern — workers
 * never write): every test:finish contributes its visited snapshot
 * keys, and at run:finish — only when the event says the run was
 * COMPLETE, and only under --update-snapshots (subscription-gated;
 * recording and pruning share the one explicit-update posture) —
 * stored entries no test visited are deleted, screenshot reference
 * images included.
 *
 * Safety rule: a snapshot file is only pruned when every test that
 * finished from its test file ran its body to completion (passed or
 * risky). A failed or errored body may have aborted before a later
 * snapshot call; a skipped or incomplete test never reached its own —
 * their stored entries are not obsolete, just unvisited today.
 */
final class SnapshotPruner implements Listener
{
    /** @var array<string, array<string, true>> snap file => visited keys */
    private array $visited = [];

    /** @var array<string, true> snap files owned by a test body that may not have run to completion */
    private array $unsafe = [];

    /**
     * @param list<non-empty-string> $testFiles        every discovered test file, project-relative — their
     *                                                 `__snapshots__` directories are the pruning ground
     */
    public function __construct(
        private readonly WorkingDirectory $workingDirectory,
        private readonly array $testFiles,
    ) {}

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $snap = SnapshotRepository::pathFor($this->workingDirectory, $event->test->file);

            foreach ($event->snapshotKeys as $key) {
                $this->visited[$snap][$key] = true;
            }

            if ($event->outcome !== Outcome::Passed && $event->outcome !== Outcome::Risky) {
                $this->unsafe[$snap] = true;
            }

            return;
        }

        if ($event instanceof RunFinished && $event->complete) {
            $this->prune();
        }
    }

    private function prune(): void
    {
        foreach ($this->candidates() as $snap) {
            if (isset($this->unsafe[$snap]) || !is_file($snap)) {
                continue;
            }

            $contents = file_get_contents($snap);

            if ($contents === false) {
                continue;
            }

            $entries = SnapshotRepository::parse($contents);
            $visited = $this->visited[$snap] ?? [];
            $kept    = [];

            foreach ($entries as $key => $value) {
                if (isset($visited[$key])) {
                    $kept[$key] = $value;

                    continue;
                }

                $this->removeImages($snap, $key, $value);
            }

            if (count($kept) === count($entries)) {
                continue;
            }

            if ($kept !== []) {
                file_put_contents($snap, SnapshotRepository::render($kept));
                $this->removeIfEmpty(substr($snap, 0, -5));

                continue;
            }

            unlink($snap);
            $this->removeIfEmpty(substr($snap, 0, -5));
            $this->removeIfEmpty(dirname($snap));
        }
    }

    /**
     * Every snapshot file beside a discovered test file — including
     * files whose owning test was deleted, which is exactly what
     * makes them obsolete.
     *
     * @return list<non-empty-string>
     */
    private function candidates(): array
    {
        $directories = [];

        foreach ($this->testFiles as $file) {
            $directories[rtrim($this->workingDirectory->path, '/') . '/' . dirname($file) . '/__snapshots__'] = true;
        }

        $candidates = [];

        foreach (array_keys($directories) as $directory) {
            $found = glob($directory . '/*.snap');

            foreach ($found === false ? [] : $found as $snap) {
                if ($snap !== '') {
                    $candidates[] = $snap;
                }
            }
        }

        return $candidates;
    }

    /**
     * A pruned screenshot entry (D-064) takes its reference image,
     * stale actual, and diff page with it.
     */
    private function removeImages(string $snap, string $key, string $value): void
    {
        if (!str_starts_with($value, 'png-sha256:')) {
            return;
        }

        $imageBase = substr($snap, 0, -5) . '/' . Snapshots::fileSafe($key);

        foreach (['.png', '.actual.png', '.diff.html'] as $suffix) {
            if (is_file($imageBase . $suffix)) {
                unlink($imageBase . $suffix);
            }
        }
    }

    private function removeIfEmpty(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries !== false && count($entries) === 2) {
            rmdir($directory);
        }
    }
}
