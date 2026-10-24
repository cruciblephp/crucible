<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\PHPStan;

use FilesystemIterator;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function hash;
use function hash_file;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function ksort;

use const DIRECTORY_SEPARATOR;

/**
 * The Pest.php files that decide `$this` in a Pest file (DialectThisResolver)
 * are read beside the analysis, so PHPStan's result cache cannot see them:
 * a changed `pest()->extend()->in()` left the old answer cached (D-157).
 * Their contents join the cache's metadata, and a change re-analyses.
 */
final readonly class PestConfigurationHash implements ResultCacheMetaExtension
{
    private const array SKIPPED = ['vendor', 'node_modules', '.git'];

    /**
     * @param list<string> $analysedPaths
     */
    public function __construct(private array $analysedPaths) {}

    public function getKey(): string
    {
        return 'crucible-pest-configuration';
    }

    public function getHash(): string
    {
        /** @var array<string, string> $files path => content hash */
        $files = [];

        foreach ($this->analysedPaths as $path) {
            $directory = is_dir($path) ? $path : dirname($path);

            foreach (DialectThisResolver::configurationDirectories($directory) as $ancestor) {
                $this->add($ancestor . DIRECTORY_SEPARATOR . 'Pest.php', $files);
            }

            if (is_dir($path)) {
                foreach ($this->beneath($path) as $file) {
                    $this->add($file, $files);
                }
            }
        }

        ksort($files);

        $lines = [];

        foreach ($files as $file => $contentHash) {
            $lines[] = $file . "\0" . $contentHash;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * @param array<string, string> $files
     */
    private function add(string $file, array &$files): void
    {
        if (isset($files[$file]) || !is_file($file)) {
            return;
        }

        $files[$file] = (string) hash_file('sha256', $file);
    }

    /**
     * @return iterable<string>
     */
    private function beneath(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $entry): bool => !$entry->isDir() || !in_array($entry->getFilename(), self::SKIPPED, true),
        ));

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->getFilename() === 'Pest.php') {
                yield $entry->getPathname();
            }
        }
    }
}
