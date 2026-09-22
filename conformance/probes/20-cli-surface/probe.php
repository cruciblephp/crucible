<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The shared black-box probe: what the oracle's --help lists, and which of
 * those options Crucible's real parser accepts. Required by compare.php (raw
 * diff) and ledger.php (the classified backlog); neither may reimplement it,
 * so the two can never disagree about what "rejected" means.
 */

function crucible_root(): string
{
    return \dirname(__DIR__, 3);
}

function require_oracle(): void
{
    if (!\is_file(\crucible_root() . '/phpunit-main/vendor/autoload.php')) {
        \fwrite(STDERR, "The PHPUnit oracle is not installed — see ORACLES.md.\n");

        exit(2);
    }
}

/**
 * @return list<string>
 */
function options(string $help): array
{
    // [a-z0-9-] after the first letter, or --coverage-crap4j truncates
    // to a --coverage-crap nobody offers and nobody can ever cover.
    \preg_match_all('/(?<!\S)(--[a-z][a-z0-9-]+)/', $help, $matches);

    $options = \array_values(\array_unique($matches[1]));
    \sort($options);

    return $options;
}

/**
 * @param list<string> $command
 */
function capture(array $command, string $cwd): string
{
    // stderr to a file, not a second pipe: draining pipes in sequence
    // deadlocks as soon as a child writes past the 64K buffer of the one
    // not being read, and a crashing child does exactly that.
    $errorFile = \tempnam(\sys_get_temp_dir(), 'crucible-probe-stderr-');

    if ($errorFile === false) {
        return '';
    }

    $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']], $pipes, $cwd);

    if ($process === false) {
        \unlink($errorFile);

        return '';
    }

    $output = (string) \stream_get_contents($pipes[1]);
    \proc_close($process);
    $output .= (string) \file_get_contents($errorFile);
    \unlink($errorFile);

    return $output;
}

/**
 * @return list<string>
 */
function oracle_options(): array
{
    return \options(\capture(['php', \crucible_root() . '/conformance/phpunit-oracle.php', '--help'], \crucible_root()));
}

/**
 * @return list<string>
 */
function crucible_options(): array
{
    return \options(\capture(['php', \crucible_root() . '/crucible', '--help'], \crucible_root()));
}

/**
 * Every oracle option Crucible's parser refuses. --help text is a claim; the
 * parser is the fact, so probe the binary rather than diffing help screens.
 * The probe runs in a scratch directory: a value-taking option swallows the
 * next argument and can write a report wherever it is standing.
 *
 * @param list<string> $oracle
 *
 * @return list<string>
 */
function rejected_options(array $oracle): array
{
    $scratch = \sys_get_temp_dir() . '/crucible-cli-surface-' . \getmypid();
    \mkdir($scratch, 0o777, true);

    $rejected = [];

    foreach ($oracle as $option) {
        $probe = \capture(['php', \crucible_root() . '/crucible', $option, '--version'], $scratch);

        if (\str_starts_with(\trim($probe), 'Unknown option')) {
            $rejected[] = $option;
        }
    }

    \capture(['rm', '-rf', $scratch], \sys_get_temp_dir());

    return $rejected;
}
