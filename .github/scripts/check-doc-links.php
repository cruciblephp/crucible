<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Every relative link in the repository's Markdown must reach something that
 * is tracked, and every #anchor must name a heading that exists.
 *
 * The suite already holds HELP.md to the parser and runs every example; what
 * nothing checked is the links between documents. They matter twice over:
 * GitHub renders them here, and cruciblephp.com's docs page reads these same
 * files from this repository, so a document renamed or moved breaks the site
 * without any commit to it. The six that page lists are checked for by name.
 *
 * Anchors follow GitHub's slug rule — lowercase, punctuation dropped, spaces
 * to hyphens, a repeated heading suffixed -1, -2 — because GitHub is where a
 * reader of these files follows them.
 *
 * Run: php .github/scripts/check-doc-links.php
 */

/** The documents cruciblephp.com's docs page fetches (its docs.js, `pages`). */
const SITE_DOCUMENTS = ['MANUAL.md', 'HELP.md', 'examples/README.md', 'RELEASE.md', 'ORACLES.md', 'DESIGN.md'];

/**
 * @return list<string>
 */
function trackedFiles(): array
{
    $output = \shell_exec('git ls-files -z');
    if (!\is_string($output)) {
        \fwrite(STDERR, "git ls-files failed: run this from inside the repository.\n");
        exit(2);
    }

    return \array_values(\array_filter(\explode("\0", $output), static fn(string $path): bool => $path !== ''));
}

/** A file's contents, or a named exit: an unreadable document is a broken check, not an empty one. */
function readDocument(string $path): string
{
    $contents = \file_get_contents($path);
    if ($contents === false) {
        \fwrite(STDERR, "cannot read {$path}\n");
        exit(2);
    }

    return $contents;
}

/** preg_replace, failing loudly on a regex error rather than returning null into a string. */
function replacePattern(string $pattern, string $replacement, string $subject): string
{
    $result = \preg_replace($pattern, $replacement, $subject);
    if ($result === null) {
        \fwrite(STDERR, "regex failed: {$pattern}\n");
        exit(2);
    }

    return $result;
}

/** `a/b/../c` → `a/c`, and a trailing slash dropped. */
function normalizePath(string $path): string
{
    $parts = [];
    foreach (\explode('/', $path) as $part) {
        if ($part === '..') {
            \array_pop($parts);
        } elseif ($part !== '.' && $part !== '') {
            $parts[] = $part;
        }
    }

    return \implode('/', $parts);
}

/**
 * The document's lines outside fenced code blocks, keyed by their 1-based
 * line number in the file: a link or a heading inside a sample is the
 * sample's, not the document's, and a reported line must be the line an
 * editor shows, not a count that skipped the samples.
 *
 * @return array<int, string>
 */
function proseLines(string $markdown): array
{
    $split = \preg_split('/\R/', $markdown);
    if ($split === false) {
        return [];
    }

    $lines  = [];
    $fenced = false;
    foreach ($split as $index => $line) {
        if (\str_starts_with($line, '```')) {
            $fenced = !$fenced;

            continue;
        }
        if (!$fenced) {
            $lines[$index + 1] = $line;
        }
    }

    return $lines;
}

/**
 * Every anchor GitHub generates for the document's headings.
 *
 * @return array<string, true>
 */
function anchorsOf(string $markdown): array
{
    $anchors = [];
    $seen    = [];
    foreach (\proseLines($markdown) as $line) {
        if (\preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $match) !== 1) {
            continue;
        }
        $slug = \mb_strtolower(\str_replace('`', '', $match[1]));
        $slug = \replacePattern('/[^\p{L}\p{N}\s_-]/u', '', $slug);
        $slug = \replacePattern('/\s/u', '-', $slug);

        $count                                                = $seen[$slug] ?? 0;
        $seen[$slug]                                          = $count + 1;
        $anchors[$count === 0 ? $slug : $slug . '-' . $count] = true;
    }

    return $anchors;
}

$tracked     = \trackedFiles();
$files       = \array_fill_keys($tracked, true);
$directories = [];
foreach ($tracked as $path) {
    for ($dir = \dirname($path); $dir !== '.' && $dir !== ''; $dir = \dirname($dir)) {
        $directories[$dir] = true;
    }
}

$problems = [];

foreach (SITE_DOCUMENTS as $document) {
    if (!isset($files[$document])) {
        $problems[] = "{$document}: missing, and cruciblephp.com's docs page reads it";
    }
}

$markdownFiles = \array_values(\array_filter($tracked, static fn(string $path): bool => \str_ends_with($path, '.md')));
/** @var array<string, array<string, true>> $anchorCache */
$anchorCache = [];
$linksSeen   = 0;

foreach ($markdownFiles as $source) {
    $markdown = \readDocument($source);
    $base     = \dirname($source) === '.' ? '' : \dirname($source) . '/';

    foreach (\proseLines($markdown) as $number => $line) {
        // Inline code is text, not a link, even when it looks like one.
        $line = \replacePattern('/`[^`]*`/', '', $line);
        \preg_match_all('/\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $line, $matches);

        foreach ($matches[1] as $href) {
            if (\preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
                continue; // http:, https:, mailto: — not this check's to verify
            }
            $linksSeen++;
            $hash   = \strpos($href, '#');
            $path   = $hash === false ? $href : \substr($href, 0, $hash);
            $anchor = $hash === false ? null : \substr($href, $hash + 1);
            $target = $path === '' ? $source : \normalizePath($base . \rawurldecode($path));
            $where  = $source . ':' . $number . ' → ' . $href;

            if (!isset($files[$target]) && !isset($directories[$target])) {
                $problems[] = "{$where}: {$target} is not in the repository";

                continue;
            }
            if ($anchor === null || $anchor === '' || !\str_ends_with($target, '.md')) {
                continue;
            }
            $anchorCache[$target] ??= \anchorsOf(\readDocument($target));
            if (!isset($anchorCache[$target][$anchor])) {
                $problems[] = "{$where}: {$target} has no heading #{$anchor}";
            }
        }
    }
}

if ($problems !== []) {
    \fwrite(STDERR, \count($problems) . " broken link(s):\n");
    foreach ($problems as $problem) {
        \fwrite(STDERR, "  {$problem}\n");
    }
    exit(1);
}

\printf("%d Markdown files, %d relative links, all resolve.\n", \count($markdownFiles), $linksSeen);
