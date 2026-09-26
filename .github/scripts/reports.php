<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Crucible's own run, once, in every format it writes, into one directory,
 * with an index page listing what was written — the sample the site shows
 * under reports/latest/. Built offline by the maintainer, not in CI.
 *
 *     php .github/scripts/reports.php <directory> [crucible options...]
 *
 * Options after the directory pass through to Crucible (`--testsuite
 * examples` for a quick look at the output).
 *
 * Exits with the suite's own exit code, after writing the index: a run that
 * failed is still described truthfully, and is not one to publish.
 */

use LucianoPereira\Crucible\Version;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$root  = \dirname(__DIR__, 2);
$given = $_SERVER['argv'] ?? [];
$argv  = \array_values(\array_filter(\is_array($given) ? $given : [], \is_string(...)));
$out   = $argv[1] ?? null;

if (!\is_string($out) || $out === '') {
    \fwrite(STDERR, "Usage: php .github/scripts/reports.php <directory> [crucible options...]\n");

    exit(2);
}

@\mkdir($out, 0o777, true);
$out = (string) \realpath($out);

/**
 * Every format, as [published path, option, what it is, whether it records
 * absolute file paths]. Those are written as the incumbents' writers write
 * them, with the machine's own paths, so they stay out of a sample that is
 * published; the index names them and says why.
 *
 * @var list<array{string, string, string, bool}> $formats
 */
$formats = [
    ['report.pdf', '--log-pdf', 'The run report as a PDF', false],
    ['testdox.html', '--testdox-html', 'The documentation view, as a page', false],
    ['coverage/index.html', '--coverage-html', 'Line coverage, file by file, with the source annotated', false],
    ['report.md', '--log-markdown', 'The run report in Markdown', false],
    ['testdox.txt', '--testdox-text', 'The documentation view as plain text', false],
    ['coverage.txt', '--coverage-text', 'Coverage as a text table', false],
    ['junit.xml', '--log-junit', 'JUnit XML, for CI', false],
    ['teamcity.txt', '--log-teamcity', 'TeamCity service messages', false],
    ['otr.xml', '--log-otr', 'Open Test Reporting (opentest4j) events', false],
    ['events.ndjson', '--log-events-json', 'The NDJSON event stream: the machine-readable contract', false],
    ['report.json', 'json', 'The finished run as JSON', false],
    ['report.sarif', 'sarif', 'SARIF 2.1.0, for code-scanning tools', false],
    ['clover.xml', '--coverage-clover', 'Clover XML', true],
    ['openclover.xml', '--coverage-openclover', 'OpenClover XML', true],
    ['cobertura.xml', '--coverage-cobertura', 'Cobertura XML', true],
    ['crap4j.xml', '--coverage-crap4j', 'Crap4J: complexity against coverage, per method', true],
    ['coverage-xml/index.xml', '--coverage-xml', 'The XML coverage report: an index and one document per file', true],
    ['coverage.php', '--coverage-php', 'The coverage data as a PHP file', true],
];

$arguments = ['--coverage', ...\array_slice($argv, 2)];
$reports   = [];

foreach ($formats as [$path, $option, , $recordsPaths]) {
    if ($recordsPaths) {
        continue;
    }

    $target = $out . '/' . $path;

    if (\str_starts_with($option, '--')) {
        // Directory reports take the directory, not their index file.
        $arguments[] = $option . '=' . (\str_ends_with($path, '/index.html') || \str_ends_with($path, '/index.xml') ? \dirname($target) : $target);
    } else {
        $reports[] = $option . ':' . $target;
    }
}

$arguments[] = '--report=' . \implode(',', $reports);

$command = \implode(' ', \array_map(\escapeshellarg(...), [PHP_BINARY, '-d', 'xdebug.mode=coverage', $root . '/crucible', ...$arguments]));

\passthru($command, $exit);

$rows = '';

foreach ($formats as [$path, , $what, $recordsPaths]) {
    if ($recordsPaths) {
        $rows .= \sprintf("<tr><td>%s</td><td class=\"muted\">not in this sample: it records absolute file paths</td><td></td></tr>\n", \htmlspecialchars($what));

        continue;
    }

    $size = \is_file($out . '/' . $path) ? \filesize($out . '/' . $path) : false;
    $rows .= $size === false
        ? \sprintf("<tr><td>%s</td><td>not written</td><td></td></tr>\n", \htmlspecialchars($what))
        : \sprintf(
            "<tr><td>%s</td><td><a href=\"%s\">%s</a></td><td class=\"num\">%s KB</td></tr>\n",
            \htmlspecialchars($what),
            \htmlspecialchars($path),
            \htmlspecialchars($path),
            \number_format($size / 1024, 1),
        );
}

\file_put_contents($out . '/index.html', \sprintf(
    <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Crucible %1$s — its own reports</title>
        <style>
        body { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; margin: 2rem; color: #1a1a1a; background: #fff; }
        h1 { font-size: 1.2rem; display: flex; align-items: center; gap: .45rem; }
        .logo svg { display: block; width: 1.6em; height: 1.6em; }
        p { max-width: 48rem; font-size: .9rem; }
        table { border-collapse: collapse; width: 100%%; max-width: 60rem; }
        td { padding: .35rem .75rem; border-bottom: 1px solid #e5e5e5; font-size: .85rem; }
        td.num { text-align: right; } td.muted { color: #777; }
        </style></head>
        <body>
        <h1>%2$sCrucible %1$s — its own reports</h1>
        <p>Crucible's own suite, run once and written in every format Crucible has — a sample of each report.
        Exit code %3$d.</p>
        <table>
        %4$s</table>
        </body>
        </html>

        HTML,
    \htmlspecialchars(Version::NUMBER),
    Version::logoHtml(),
    $exit,
    $rows,
));

exit($exit);
