<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\Console\Components\Pager;
use LucianoPereira\Crucible\Console\Output\Markdown;
use LucianoPereira\Crucible\Console\Output\TextPdf;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Version;

use function array_pop;
use function basename;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function max;
use function preg_match;
use function printf;
use function realpath;
use function str_starts_with;

use const PHP_EOL;

/**
 * `crucible manual`: a fullscreen, Norton-Guides-style reader over
 * Crucible's own documentation. Local `[text](other.md)` links are
 * followed with a back stack (number keys open, ⌫ goes back, q quits);
 * a non-interactive terminal (CI, piped output) gets the rendered text
 * once instead.
 *
 * ⚠ Rooted in the package, not in the working directory. Installed in
 * someone's project, reading `./README.md` opened THEIR repository's
 * readme — the one document guaranteed not to be about this command.
 */
final class ManualCommand
{
    /** Columns the PDF lays the text out at — wider than a terminal, narrower than the page. */
    private const int PDF_COLUMNS = 96;

    public function execute(?string $out = null): int
    {
        // ⚠ The package's own docs, not the working directory's. Installed
        // in someone's project, reading `./README.md` opened THEIR
        // repository's readme — the one document guaranteed not to be
        // about this command.
        $path = dirname(__DIR__, 3) . '/MANUAL.md';

        if (! is_file($path)) {
            print 'Crucible\'s MANUAL.md was not found beside the installed package.' . PHP_EOL;

            return 1;
        }

        if ($out !== null) {
            return $this->writePdf($path, $out);
        }

        $terminal = Runtime::terminal();

        if (! $terminal->supportsInteractivity()) {
            $width = max(20, $terminal->columns());
            $terminal->write(Markdown::render((string) file_get_contents($path), $width)->plainText() . PHP_EOL);

            return 0;
        }

        /** @var list<non-empty-string> $stack */
        $stack   = [];
        $current = $path;

        while (true) {
            $width    = max(20, $terminal->columns() - 4);
            $document = Markdown::render((string) file_get_contents($current), $width);
            $viewer   = new Pager($document->lines, basename($current), $document->links);

            Runtime::run($viewer);

            if ($viewer->quit) {
                return 0;
            }

            if ($viewer->goBack) {
                if ($stack !== []) {
                    $current = array_pop($stack);
                }

                continue;
            }

            if ($viewer->followLink === null || ! isset($document->links[$viewer->followLink])) {
                return 0;
            }

            $target = $this->resolveDocPath($document->links[$viewer->followLink], dirname($current));

            if ($target !== null) {
                $stack[] = $current;
                $current = $target;
            }
        }
    }

    /**
     * The manual as a monospaced PDF, laid out exactly as the pager
     * lays it out — the same text, on paper.
     */
    private function writePdf(string $source, string $out): int
    {
        $text = Markdown::render((string) file_get_contents($source), self::PDF_COLUMNS)->plainText();

        if (file_put_contents($out, (new TextPdf())->render($text, 'Crucible PHP — the manual', Version::AUTHOR)) === false) {
            printf('Could not write %s.' . PHP_EOL, $out);

            return 1;
        }

        printf('Wrote the manual to %s.' . PHP_EOL, $out);

        return 0;
    }

    /**
     * Resolve a local documentation link relative to the current
     * document's directory. External (scheme://) links and missing
     * files return null.
     *
     * @return non-empty-string|null
     */
    private function resolveDocPath(string $target, string $baseDir): ?string
    {
        if (preg_match('#^[a-z]+://#i', $target) === 1) {
            return null;
        }

        $candidate = str_starts_with($target, '/') ? $target : $baseDir . '/' . $target;
        $real      = realpath($candidate);

        return $real !== false && is_file($real) ? $real : null;
    }
}
