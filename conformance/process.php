<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/**
 * One child process, run to the end: the exit code, its stdout and its
 * stderr. Shared by every conformance script that spawns one.
 *
 * stderr goes to a temp file, not a second pipe: draining pipes in
 * sequence deadlocks the moment a child writes more than the 64K pipe
 * buffer to the one not being read, and a crashing child (a fatal with
 * a deep stack trace) does exactly that. A hang here reads as "the
 * suite is slow" and costs far more than the file.
 *
 * @param list<string>               $command
 * @param ?array<string, string>     $environment null: the parent's
 *
 * @return array{int, string, string} exit, stdout, stderr; 255 when it could not start
 */
function run_process(array $command, string $cwd, ?array $environment = null): array
{
    $errorFile = \tempnam(\sys_get_temp_dir(), 'crucible-conformance-stderr-');

    if ($errorFile === false) {
        return [255, '', ''];
    }

    $process = \proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']], $pipes, $cwd, $environment);

    if ($process === false) {
        \unlink($errorFile);

        return [255, '', ''];
    }

    $stdout = (string) \stream_get_contents($pipes[1]);
    $exit   = \proc_close($process);
    $stderr = (string) \file_get_contents($errorFile);

    \unlink($errorFile);

    return [$exit, $stdout, $stderr];
}
