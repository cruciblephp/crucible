<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Snapshot;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Test\TestId;

use function base64_encode;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function htmlspecialchars;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_replace;
use function sprintf;
use function substr;

use const ENT_QUOTES;

/**
 * The snapshot engine (D-042), dialect-neutral like everything else:
 * one ambient context per test attempt (the PropertyContext pattern —
 * a test body cannot be handed parameters), one canonical serializer
 * (the Exporter, which D-012 built with snapshots already in mind),
 * every dialect one thin call away — assertMatchesSnapshot() in
 * TestCase, ->toMatchSnapshot() in pest/crucible, both callable from
 * doctests.
 *
 * The behavior is deterministic and explicit, by principle: a missing
 * snapshot FAILS and names the flag — snapshots are never invented as
 * a side effect of a normal run — and `--update-snapshots` is the one
 * way values get recorded or replaced.
 */
final class Snapshots
{
    private static ?TestId $test = null;

    private static bool $update = false;

    /** whether this process may rewrite test source (D-076) — sequential runs only, never a worker */
    private static bool $inlineRewrite = false;

    private static ?SnapshotRepository $repository = null;

    private static ?WorkingDirectory $workingDirectory = null;
    /**
     * The configured directory, or the '/' the property used to default
     * to. A static property cannot hold a `new`, so the default moved
     * here rather than changing what an unconfigured read answers.
     */
    private static function workingDirectory(): WorkingDirectory
    {
        return self::$workingDirectory ??= new WorkingDirectory('/');
    }


    private static int $counter = 0;

    private static int $created = 0;

    private static int $updated = 0;

    /** @var list<non-empty-string> */
    private static array $visited = [];

    /** @var list<non-empty-string> */
    private static array $lastVisited = [];

    public static function begin(TestId $test, bool $update, SnapshotRepository $repository, WorkingDirectory $workingDirectory, bool $inlineRewrite = false): void
    {
        self::$test             = $test;
        self::$update           = $update;
        self::$inlineRewrite    = $inlineRewrite;
        self::$repository       = $repository;
        self::$workingDirectory = $workingDirectory;
        self::$counter          = 0;
        self::$created          = 0;
        self::$updated          = 0;
        self::$visited          = [];
    }

    /** @var ?array{created: int, updated: int} */
    private static ?array $lastRecorded = null;

    /**
     * What the just-ended attempt recorded under --update-snapshots —
     * the console counts (D-066). Null outside update mode / when
     * nothing changed, so the event payload stays default-omitted.
     * Stashed at end() by the ENDING context: a nested harness run
     * (tests that run their own inner runner, the self-hosted suite's
     * own pattern) cannot leak its counts onto the outer attempt —
     * the outer end() overwrites the stash from its own state.
     *
     * @return ?array{created: int, updated: int}
     */
    public static function recorded(): ?array
    {
        return self::$lastRecorded;
    }

    public static function end(): void
    {
        self::$repository?->flush();

        self::$lastRecorded = self::$update && (self::$created > 0 || self::$updated > 0)
            ? ['created' => self::$created, 'updated' => self::$updated]
            : null;

        self::$lastVisited = self::$visited;

        self::$test          = null;
        self::$update        = false;
        self::$inlineRewrite = false;
        self::$repository    = null;
        self::$counter       = 0;
        self::$created       = 0;
        self::$updated       = 0;
        self::$visited       = [];
    }

    /**
     * The snapshot keys the just-ended attempt touched — what the
     * obsolete-snapshot pruner (D-071) unions across the run to know
     * which stored entries are still alive. Same stash-at-end rule as
     * recorded(): the ending context overwrites, so a nested harness
     * run cannot leak its keys onto the outer attempt.
     *
     * @return list<non-empty-string>
     */
    public static function visited(): array
    {
        return self::$lastVisited;
    }

    /**
     * The one snapshot assertion. Compares the exported value with
     * the stored entry; in update mode it records instead. Multiple
     * calls in one test number themselves; $name pins a stable key.
     *
     * @param ?non-empty-string $name
     */
    public static function match(mixed $value, ?string $name = null): void
    {
        [$test, $repository, $key] = self::context($name);

        $rendered = Exporter::export($value);
        $file     = SnapshotRepository::pathFor(self::workingDirectory(), $test->file);
        $stored   = $repository->get($file, $key);

        if (self::$update) {
            if ($stored === null) {
                self::$created++;
                $repository->put($file, $key, $rendered);
            } elseif ($stored !== $rendered) {
                self::$updated++;
                $repository->put($file, $key, $rendered);
            }

            Assert::countSatisfiedAssertion(); // recording is the assertion

            return;
        }

        if ($stored === null) {
            Assert::fail(sprintf(
                'No snapshot recorded for "%s". Run with --update-snapshots to record the current value.',
                $key,
            ));
        }

        Assert::assertSame($stored, $rendered, sprintf(
            'Snapshot mismatch for "%s" (run --update-snapshots to accept the new value).',
            $key,
        ));
    }

    /**
     * The inline snapshot assertion (D-076): the expected value lives in
     * the test source, passed as $expected (null the first time). Same
     * explicit-recording principle as the file flavor — a missing value
     * FAILS and names the flag; `--update-snapshots` rewrites the source
     * to record or replace it, buffered and applied at run end. Recording
     * is sequential-run only; a worker cannot rewrite a shared file, so
     * it fails naming the constraint instead of racing.
     *
     * @param string $file the call site, captured by the dialect entry point ('' when unknown)
     */
    public static function matchInline(mixed $value, ?string $expected, string $file, int $line): void
    {
        if (!self::$test instanceof TestId) {
            throw new ConfigurationException('Snapshot assertions need the crucible runner — there is no snapshot context here.');
        }

        $rendered = Exporter::export($value);

        // A value already recorded in the source: compare, or replace
        // under --update-snapshots.
        if ($expected !== null) {
            if ($rendered === $expected) {
                Assert::countSatisfiedAssertion();

                return;
            }

            if (self::$update) {
                self::recordInline($file, $line, $rendered);
                self::$updated++;
                Assert::countSatisfiedAssertion();

                return;
            }

            Assert::assertSame($expected, $rendered, 'Inline snapshot mismatch (run --update-snapshots to accept the new value).');

            return;
        }

        // Nothing recorded yet.
        if (self::$update) {
            self::recordInline($file, $line, $rendered);
            self::$created++;
            Assert::countSatisfiedAssertion();

            return;
        }

        Assert::fail('No inline snapshot recorded. Run with --update-snapshots to record the current value.');
    }

    /**
     * Queue a source rewrite, or fail naming the constraint when this
     * process may not rewrite (a worker / a parallel run) or the call
     * site could not be located.
     */
    private static function recordInline(string $file, int $line, string $rendered): void
    {
        if (!self::$inlineRewrite) {
            Assert::fail('Inline snapshot recording needs a sequential run — rerun without --parallel (or process isolation) to record it.');
        }

        if ($file === '' || $line <= 0) {
            Assert::fail('Inline snapshot recording could not locate its call site in the source.');
        }

        InlineSnapshotWriter::record($file, $line, $rendered);
    }

    /**
     * The screenshot flavor (D-064): the .snap entry stays the
     * content hash (byte-exact, collision-proof, diffable as text),
     * while the update run also stores the reference PNG beside the
     * snapshot file — so a later mismatch can render a VISUAL diff:
     * reference, actual, and a blend-mode overlay (black where the
     * pixels agree), all in one dependency-free HTML file named by
     * the failure message. Recording stays explicit — the reference
     * image is only ever written under --update-snapshots.
     *
     * @param ?non-empty-string $name
     */
    public static function matchScreenshot(string $png, ?string $name = null): void
    {
        [$test, $repository, $key] = self::context($name);

        $rendered = 'png-sha256:' . hash('sha256', $png);
        $file     = SnapshotRepository::pathFor(self::workingDirectory(), $test->file);
        $stored   = $repository->get($file, $key);

        $imageDirectory = substr($file, 0, -5); // …/__snapshots__/<basename> (the .snap dropped)
        $imageBase      = $imageDirectory . '/' . self::fileSafe($key);

        if (self::$update) {
            if ($stored === null) {
                self::$created++;
                $repository->put($file, $key, $rendered);
            } elseif ($stored !== $rendered) {
                self::$updated++;
                $repository->put($file, $key, $rendered);
            }

            if (!is_dir($imageDirectory)) {
                mkdir($imageDirectory, 0o777, true);
            }

            file_put_contents($imageBase . '.png', $png);

            Assert::countSatisfiedAssertion();

            return;
        }

        if ($stored === null) {
            Assert::fail(sprintf(
                'No screenshot snapshot recorded for "%s". Run with --update-snapshots to record the current rendering.',
                $key,
            ));
        }

        if ($stored === $rendered) {
            Assert::countSatisfiedAssertion();

            return;
        }

        $diff = self::writeVisualDiff($imageBase, $png, $key);

        Assert::fail(sprintf(
            "Screenshot mismatch for \"%s\" (run --update-snapshots to accept the new rendering).%s",
            $key,
            $diff !== null ? "\nVisual diff: " . $diff : '',
        ));
    }

    /**
     * The side-by-side + difference-overlay view. Dependency-free:
     * both PNGs embed as data URIs, the overlay uses CSS
     * mix-blend-mode difference — black where the renderings agree.
     * Null when the reference image is missing (hash recorded before
     * the screenshot flavor existed).
     */
    private static function writeVisualDiff(string $imageBase, string $actual, string $key): ?string
    {
        $referenceFile = $imageBase . '.png';

        if (!is_file($referenceFile)) {
            return null;
        }

        file_put_contents($imageBase . '.actual.png', $actual);

        $reference = base64_encode((string) file_get_contents($referenceFile));
        $current   = base64_encode($actual);

        $html = sprintf(
            <<<'HTML'
                <!DOCTYPE html>
                <html lang="en">
                <head><meta charset="utf-8"><title>%1$s — screenshot diff</title><style>
                body { font-family: ui-monospace, monospace; margin: 2rem; background: #fff; color: #1a1a1a; }
                h1 { font-size: 1rem; } h2 { font-size: .85rem; margin: 1.5rem 0 .5rem; }
                img { max-width: 100%%; border: 1px solid #ddd; display: block; }
                .side { display: flex; gap: 1rem; } .side > div { flex: 1; }
                .overlay { position: relative; } .overlay img { position: absolute; inset: 0; }
                .overlay img + img { mix-blend-mode: difference; }
                .overlay { aspect-ratio: auto; } .overlay::after { content: ""; display: block; padding-top: 60%%; }
                </style></head>
                <body>
                <h1>%1$s</h1>
                <div class="side">
                <div><h2>Reference</h2><img src="data:image/png;base64,%2$s" alt="reference"></div>
                <div><h2>Actual</h2><img src="data:image/png;base64,%3$s" alt="actual"></div>
                </div>
                <h2>Difference (black = identical)</h2>
                <div class="overlay"><img src="data:image/png;base64,%2$s" alt=""><img src="data:image/png;base64,%3$s" alt=""></div>
                </body>
                </html>
                HTML,
            htmlspecialchars($key, ENT_QUOTES),
            $reference,
            $current,
        );

        $diffFile = $imageBase . '.diff.html';

        file_put_contents($diffFile, $html);

        return $diffFile;
    }

    /**
     * @param ?non-empty-string $name
     *
     * @return array{TestId, SnapshotRepository, non-empty-string}
     */
    private static function context(?string $name): array
    {
        $test       = self::$test;
        $repository = self::$repository;

        if (!$test instanceof TestId || !$repository instanceof SnapshotRepository) {
            throw new ConfigurationException('Snapshot assertions need the crucible runner — there is no snapshot context here.');
        }

        $key = $test->name
            . ($test->dataset !== null ? '#' . $test->dataset : '')
            . ' » '
            . ($name ?? '#' . ++self::$counter);

        self::$visited[] = $key;

        return [$test, $repository, $key];
    }

    /**
     * @return non-empty-string
     */
    public static function fileSafe(string $key): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $key);

        return $safe === '' ? '_' : $safe;
    }
}
