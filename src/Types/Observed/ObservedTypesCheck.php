<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use LucianoPereira\Crucible\Coverage\CacheManifest;
use LucianoPereira\Crucible\Extension\Artifact\Artifact;
use LucianoPereira\Crucible\Extension\Artifact\Claim;
use LucianoPereira\Crucible\Extension\Check;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function count;
use function file_put_contents;
use function implode;
use function is_dir;
use function json_encode;
use function ksort;
use function mkdir;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The phpdocs a run proved wrong (D-163): each parameter or return whose
 * docblock refused a value a call that returned passed or gave back, with
 * that value. Presented as the count of such slots against zero; writes
 * what every slot received as `observed-types.json` beside it.
 */
final readonly class ObservedTypesCheck implements Check
{
    public const int VERSION = 1;

    public const string FILE = 'observed-types.json';

    /** @param non-empty-string $cacheDirectory absolute */
    public function __construct(private string $cacheDirectory) {}

    public function label(): string
    {
        return 'phpdoc contradicted by a test';
    }

    public function inspect(WorkingDirectory $workingDirectory): Artifact
    {
        ObservedRun::stop();

        $functions = Observer::functions();
        $written   = [];
        $found     = [];

        ksort($functions);

        foreach ($functions as $key => $function) {
            $file = $workingDirectory->relative($function->file);

            $written[$key] = ['file' => $file, 'line' => $function->line, 'calls' => $function->calls, 'types' => $function->types];

            foreach ($function->contradictions as $slot => $contradiction) {
                $found[] = sprintf(
                    '%s:%d %s() %s: %s %s, %s (%d of %d calls)',
                    $file,
                    $function->line,
                    $key,
                    $slot,
                    $slot === 'return' ? '@return' : '@param',
                    $contradiction['written'],
                    $contradiction['mismatch'],
                    $contradiction['calls'],
                    $function->calls,
                );
            }
        }

        $this->write($written);

        return new Claim(count($found), 0, $found === [] ? null : implode("\n", $found));
    }

    /** @param array<string, mixed> $functions */
    private function write(array $functions): void
    {
        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0o777, true)) {
            return;
        }

        $json = json_encode(['version' => self::VERSION, 'functions' => $functions], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($this->cacheDirectory . '/' . self::FILE, $json . "\n") !== false) {
            CacheManifest::record($this->cacheDirectory, self::FILE, self::VERSION);
        }
    }
}
